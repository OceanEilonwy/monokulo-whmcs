<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Everything that reaches `modules/gateways/callback/monokulo.php`:
 *
 * - `POST` (no action): Monokulo's webhooks, registered when WHMCS connected.
 * - `GET ?action=pay`: the invoice's Pay with Monero link. Creates the
 *   Monokulo order for the invoice's balance and sends the client to pay it.
 * - `GET ?action=connect`: the settings page's Connect button. Sends the
 *   admin's browser to Monokulo to sign in and pick (or set up) the store.
 * - `GET ?action=return`: where the connect flow comes back with its token.
 */
final class Callback {
	private Trace $trace;
	private Logger $log;
	private Repository $repository;
	private string $systemUrl;
	private ?Connection $connection = null;
	private bool $connectionRead = false;

	/**
	 * @param array<string, mixed> $gateway the gateway's settings, from getGatewayVariables
	 */
	public function __construct(
		private readonly Whmcs $whmcs,
		private readonly array $gateway,
		private readonly int $now,
	) {
		$this->trace = new Trace();
		$this->log = new Logger(static function (string $level, string $line) use ($whmcs): void {
			$whmcs->log($level, $line);
		}, $this->trace);
		$this->repository = new Repository($whmcs->db());
		$this->systemUrl = $whmcs->systemUrl();
	}

	public function handle(Request $request): Response {
		$this->repository->install();
		$action = $request->get('action');
		if ($request->method === 'POST' && $action === '') {
			$response = $this->webhook($request);
		} elseif ($request->method === 'GET' && $action === 'pay') {
			$response = $this->pay($request);
		} elseif ($request->method === 'GET' && $action === 'connect') {
			$response = $this->connect($request);
		} elseif ($request->method === 'GET' && $action === 'return') {
			$response = $this->connectReturn($request);
		} else {
			$response = Response::text(404, 'Not found');
		}
		$this->forward();

		return $response;
	}

	/** Monokulo's webhooks. See WebhookReceiver for what each answer means. */
	private function webhook(Request $request): Response {
		$this->trace->adopt($request->traceparent);
		$receiver = new WebhookReceiver($this->repository, new InvoiceUpdater($this->whmcs, $this->repository), $this->log);
		$status = $receiver->handle($this->connection(), $request->body, $request->signature, $this->now);

		return Response::text($status, (string)$status);
	}

	/**
	 * Creates the Monokulo order for the invoice's balance (or gets the one
	 * made before, when nothing changed) and sends the client to pay it.
	 */
	private function pay(Request $request): Response {
		$invoiceId = (int)$request->get('invoice');
		if (!Links::payIsGenuine($this->repository->linkKey(), $invoiceId, $request->get('sig'))) {
			$this->log->warning('Pay link refused: not one this WHMCS made.');

			return Response::page(403, 'error_link');
		}
		$back = $this->systemUrl . 'viewinvoice.php?id=' . $invoiceId;
		$connection = $this->connection();
		if (!$connection) {
			$this->log->error(sprintf('Invoice %d: a client wanted to pay with Monero, but WHMCS is not connected to Monokulo (connect it from the gateway settings).', $invoiceId));

			return Response::page(503, 'error_not_connected', $back);
		}
		$invoice = $this->whmcs->invoice($invoiceId);
		if (!$invoice) {
			return Response::page(404, 'error_invoice');
		}
		if (!$invoice->payable()) {
			return Response::page(409, 'error_not_payable', $back);
		}

		$attempt = $this->repository->attempt($invoiceId);
		$body = OrderRequest::body($invoice, $attempt, sprintf(Text::get('text_invoice'), $invoice->number), Text::get('text_more'));
		try {
			$order = (new Client($this->trace))->createOrder($connection, $body);
		} catch (ApiError $e) {
			$this->log->error(sprintf('Creating the Monokulo order for invoice %d failed: HTTP %d: %s%s', $invoiceId, $e->status, $e->detail, $e->merchantHint()));

			return Response::page(502, $e->customerMessageKey(), $back);
		} catch (TransportError $e) {
			$this->log->error(sprintf('Creating the Monokulo order for invoice %d failed: could not reach %s: %s', $invoiceId, $connection->api, $e->getMessage()));

			return Response::page(502, 'error_unreachable', $back);
		}

		if ($this->repository->recordOrder($order->id, $invoiceId, $connection->host, $body['amount'], $body['currency'], $attempt, $this->now)) {
			$this->whmcs->gatewayLog([
				'invoice'        => (string)$invoiceId,
				'monokulo_order' => $order->id,
				'amount'         => $body['amount'] . ' ' . $body['currency'],
				'note'           => sprintf(Text::get('note_started'), $order->checkoutUrl),
			], 'Sent to pay');
		}
		$this->log->info(sprintf('Invoice %d: client sent to Monokulo order %s.', $invoiceId, $order->id));

		return Response::redirect($order->checkoutUrl);
	}

	/**
	 * Starts connecting: remembers a single-use nonce, then sends the
	 * admin's browser to Monokulo. Monokulo comes back to `?action=return`
	 * on the System URL's host.
	 */
	private function connect(Request $request): Response {
		if (!$this->whmcs->adminSignedIn()) {
			return Response::page(403, 'error_connect_admin');
		}
		$return = $request->get('return');
		if (!Links::connectIsGenuine($this->repository->linkKey(), $return, (int)$request->get('t'), $request->get('sig'), $this->now)) {
			return Response::page(400, 'error_connect_link');
		}
		$address = ConnectLink::address((string)($this->gateway['address'] ?? ''));
		if ($address === null) {
			return Response::redirect(Links::with($return, 'monokulo_connect_error=address'));
		}
		$nonce = $this->repository->createNonce(ConnectLink::host($this->systemUrl), $return, $this->now);

		return Response::redirect(ConnectLink::start($address, $this->systemUrl, Links::callback($this->systemUrl) . '?action=return', $nonce));
	}

	/**
	 * The end of the connect flow. Monokulo sends the admin's browser back
	 * here with the single-use `token` and the `nonce` the Connect link made.
	 * The nonce is used up whatever happens; when it is still fresh, the
	 * token is redeemed from the server for the store's credentials, and the
	 * browser goes back to the settings page saying how it went.
	 */
	private function connectReturn(Request $request): Response {
		$token = $request->get('token');
		$attempt = $this->repository->consumeNonce($request->get('nonce'), $this->now);
		if (!$attempt || $token === '') {
			$this->log->warning('Connect return refused: no token, or a nonce this WHMCS did not make, already used or expired.');

			return Response::page(400, 'text_connect_failed');
		}

		$host = ConnectLink::host($this->systemUrl);
		if ($attempt['host'] !== $host) {
			$this->log->warning(sprintf('Connect return refused: started for %s but the System URL is now %s.', $attempt['host'], $host));
			$result = 'monokulo_connect_error=host';
		} else {
			$result = $this->finishConnect($host, $token);
		}

		return Response::redirect(Links::with($attempt['admin_return'], $result));
	}

	/** Redeems the connect token; the query string telling the settings page how it went. */
	private function finishConnect(string $host, string $token): string {
		$address = (string)ConnectLink::address((string)($this->gateway['address'] ?? ''));
		try {
			$credentials = (new Client($this->trace))->finishConnect($address, $token, Links::callback($this->systemUrl));
		} catch (ApiError $e) {
			$this->log->error(sprintf('Connect finish at %s failed: HTTP %d: %s', $address, $e->status, $e->detail));

			return $e->status === 503 ? 'monokulo_connect_error=unavailable' : 'monokulo_connect_error=finish';
		} catch (TransportError $e) {
			$this->log->error(sprintf('Connect finish at %s failed: could not reach it: %s', $address, $e->getMessage()));

			return 'monokulo_connect_error=unreachable';
		}

		$this->connection = new Connection($host, $credentials['api'], $credentials['public_key'], $credentials['secret_token'], $credentials['webhook_signing_secret'], $this->now);
		$this->connectionRead = true;
		$this->repository->saveConnection($this->connection);
		$this->log->info(sprintf('%s connected to Monokulo as %s.', $host, $credentials['public_key']));

		return 'monokulo_connected=1';
	}

	/** This WHMCS's connection, or null before it has connected. */
	private function connection(): ?Connection {
		if (!$this->connectionRead) {
			$this->connection = $this->repository->connection(ConnectLink::host($this->systemUrl), (string)($this->gateway['address'] ?? ''));
			$this->connectionRead = true;
		}

		return $this->connection;
	}

	/** Sends this request's warnings and errors to Monokulo, when the merchant turned that on. */
	private function forward(): void {
		$connection = $this->connection();
		if ($connection && ($this->gateway['forwardErrors'] ?? '') === 'on') {
			(new Client($this->trace))->forwardLogs($connection, $this->log->forwardable());
		}
	}
}

<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The gateway module's functions (modules/gateways/monokulo.php): its
 * metadata, its settings page with the connection and the Connect and
 * Test connection buttons, the check on saving them, and the Pay with Monero link on an invoice.
 */
final class Gateway {
	private const CONNECT_ERRORS = ['address', 'host', 'finish', 'unavailable', 'busy', 'unreachable', 'callback'];

	public function __construct(
		private readonly Whmcs $whmcs,
	) {
	}

	/** @return array<string, mixed> */
	public static function metaData(): array {
		return [
			'DisplayName'                 => Text::get('display_name'),
			'APIVersion'                  => '1.1',
			'DisableLocalCreditCardInput' => true,
			'TokenisedStorage'            => false,
		];
	}

	/**
	 * The settings page's fields. `UsageNotes` (a System field WHMCS shows
	 * as a note) carries the connection, the last test webhook received,
	 * the Connect and Test connection buttons, and how a test went.
	 *
	 * @param array<string, mixed> $query the settings page's query (how a connect attempt or a test went)
	 * @param string $scriptName the settings page's script (`/admin/configgateways.php`), whose folder is the admin area's
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function config(array $query, string $scriptName, int $now): array {
		return [
			'FriendlyName' => [
				'Type'  => 'System',
				'Value' => Text::get('display_name'),
			],
			'address' => [
				'FriendlyName' => Text::get('entry_address'),
				'Type'         => 'text',
				'Size'         => '60',
				'Default'      => 'https://www.monokulo.com',
				'Description'  => Text::html('help_address'),
			],
			'forwardErrors' => [
				'FriendlyName' => Text::get('entry_forward_errors'),
				'Type'         => 'yesno',
				'Description'  => Text::html('help_forward_errors'),
			],
			'UsageNotes' => [
				'Type'  => 'System',
				'Value' => $this->connectionNotes($query, $scriptName, $now),
			],
		];
	}

	/**
	 * Refuses settings WHMCS shouldn't save: a Monokulo address that isn't an
	 * http(s) address.
	 *
	 * @param array<string, mixed> $params the settings as submitted
	 *
	 * @throws \WHMCS\Exception\Module\InvalidConfiguration
	 */
	public static function validate(array $params): void {
		if (ConnectLink::address((string)($params['address'] ?? '')) === null) {
			throw new \WHMCS\Exception\Module\InvalidConfiguration(Text::get('error_address'));
		}
	}

	/**
	 * The invoice's Pay with Monero button, on the invoice page and in the
	 * invoice email. It goes through the callback, which creates the
	 * Monokulo order only when pressed. On the shop's onion address, the
	 * link is there too, so the client stays on Tor.
	 *
	 * @param array<string, mixed> $params WHMCS's invoice and gateway parameters
	 */
	public function link(array $params): string {
		$repository = new Repository($this->whmcs->db());
		$repository->install();
		$systemUrl = $this->whmcs->systemUrl();
		$connection = $repository->connection(ConnectLink::host($systemUrl));
		if (!$connection) {
			return '<p>' . Text::html('text_unavailable') . '</p>';
		}
		$url = Links::pay(Links::shopUrl($systemUrl, $this->whmcs->requestOrigin()), $repository->linkKey(), (int)$params['invoiceid']);

		return '<a href="' . Text::escape($url) . '" class="btn btn-success">' . Text::html('button_pay') . '</a>'
			. '<p><small>' . Text::html('text_pay') . '</small></p>';
	}

	/** @param array<string, mixed> $query */
	private function connectionNotes(array $query, string $scriptName, int $now): string {
		$repository = new Repository($this->whmcs->db());
		$repository->install();
		$systemUrl = $this->whmcs->systemUrl();
		$connection = $repository->connection(ConnectLink::host($systemUrl));
		$lines = [];

		if (isset($query['monokulo_connected'])) {
			$lines[] = '<strong>' . Text::html('text_connected') . '</strong>';
		}
		$error = is_string($query['monokulo_connect_error'] ?? null) ? $query['monokulo_connect_error'] : '';
		if (in_array($error, self::CONNECT_ERRORS, true)) {
			$lines[] = '<strong>' . Text::html('error_connect_' . $error) . '</strong>';
		}

		$notice = TestWebhook::notice($query);

		if (!$connection) {
			$lines[] = Text::html('text_not_connected');
		} else {
			$lines[] = Text::escape(sprintf(Text::get('text_connected_as'), $connection->publicKey, date('Y-m-d H:i', $connection->connectedAt)));
			$lines[] = $connection->onionApi !== null
				? Text::escape(sprintf(Text::get('text_onion'), (string)parse_url($connection->onionApi, PHP_URL_HOST)))
				: Text::html('text_no_onion');
			$test = $repository->lastTestWebhook();
			$lines[] = $test !== null
				? Text::escape(sprintf(Text::get('text_last_test'), date('Y-m-d H:i:s', $test['received_at']), $test['event_id']))
				: Text::html('text_no_test');
		}

		if (ConnectLink::address($this->whmcs->setting('address')) === null) {
			$lines[] = Text::html('text_address_first');
		} else {
			$return = $this->adminReturn($systemUrl, $scriptName);
			$url = Links::connect($systemUrl, $repository->linkKey(), $return, $now);
			$buttons = '<a href="' . Text::escape($url) . '" class="btn btn-default btn-sm">' . Text::html($connection ? 'button_reconnect' : 'button_connect') . '</a>';
			if ($connection) {
				$buttons .= ' <a href="' . Text::escape(Links::test($systemUrl, $repository->linkKey(), $return, $now)) . '" class="btn btn-default btn-sm">' . Text::html('button_test') . '</a>';
			}
			$lines[] = $buttons;
		}
		$lines[] = '<small>' . Text::escape(sprintf(Text::get('text_webhook'), Links::callback($systemUrl))) . '</small>';

		return $notice . implode('<br>', $lines);
	}

	/**
	 * Where the connect flow ends: this settings page, in whichever folder
	 * the admin area is (WHMCS lets it be renamed).
	 */
	private function adminReturn(string $systemUrl, string $scriptName): string {
		$folder = basename(dirname($scriptName));
		if ($scriptName === '' || $folder === '' || $folder === '.' || $folder === '/') {
			$folder = 'admin';
		}

		return $systemUrl . $folder . '/configgateways.php';
	}
}

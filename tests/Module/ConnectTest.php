<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Links;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\Calls;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use Monokulo\Whmcs\Tests\Support\WhmcsTestCase;
use WHMCS\Database\Capsule;

/**
 * The connect flow, through the callback: the settings page's Connect sends
 * a signed-in admin to Monokulo with a single-use nonce; Monokulo sends the
 * browser back with a token, which WHMCS redeems for the store's
 * credentials, and the browser goes back to the settings page saying how it
 * went.
 */
final class ConnectTest extends WhmcsTestCase {
	use Calls;

	private const ADMIN = Whmcs::URL . 'admin/configgateways.php';
	private const CREDENTIALS = ['public_key' => 'pk_new', 'secret_token' => 'sk_new', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_new'];

	private function repository(): Repository {
		return new Repository(Capsule::connection());
	}

	/** @return array<string, string> the Connect button's query, as the settings page makes it */
	private function connectLink(int $now = 0, string $return = self::ADMIN): array {
		$this->repository()->install();

		return self::query(Links::connect(Whmcs::URL, $this->repository()->linkKey(), $return, $now ?: time()));
	}

	private function nonce(string $host = Whmcs::HOST, int $now = 0): string {
		$this->repository()->install();

		return $this->repository()->createNonce($host, self::ADMIN, $now ?: time());
	}

	private function monokuloFinishes(int $status, mixed $body): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', $status, $body, headers: $status >= 400 ? ['Content-Type' => Api::PROBLEM] : []);
	}

	public function test_connect_sends_the_admin_to_monokulo_with_a_nonce(): void {
		$_SESSION['adminid'] = 1;

		$response = $this->get($this->connectLink());

		$this->assertSame(303, $response->status);
		$location = $response->headers['Location'];
		$this->assertStringStartsWith(self::$monokulo->url . '/plugins/connect/whmcs?', $location);
		$query = self::query($location);
		$this->assertSame(Whmcs::URL, $query['site_url']);
		$this->assertSame(Whmcs::CALLBACK . '?action=return', $query['return_url']);
		$this->assertSame('WHMCS', $query['name']);
		$nonce = Whmcs::rows('mod_monokulo_nonce')[0];
		$this->assertSame([$query['nonce'], Whmcs::HOST, self::ADMIN], [$nonce['nonce'], $nonce['host'], $nonce['admin_return']]);
	}

	/** The link alone isn't enough: the browser must be signed in to the admin area. */
	public function test_connect_needs_a_signed_in_admin(): void {
		$response = $this->get($this->connectLink());

		$this->assertSame(403, $response->status);
		$this->assertStringContainsString('Sign in to the WHMCS admin area', $response->body);
		$this->assertSame([], Whmcs::rows('mod_monokulo_nonce'));
	}

	public static function badConnectLinks(): array {
		return [
			'more than an hour old' => [static fn(array $q): array => $q, 3601],
			'another return'        => [static fn(array $q): array => ['return' => 'https://evil.example/'] + $q, 0],
			'no signature'          => [static fn(array $q): array => array_diff_key($q, ['sig' => 1]), 0],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('badConnectLinks')]
	public function test_a_connect_link_this_page_did_not_make_is_refused(\Closure $tamper, int $age): void {
		$_SESSION['adminid'] = 1;
		$now = time();

		$response = $this->get($tamper($this->connectLink($now - $age)), $now);

		$this->assertSame(400, $response->status);
		$this->assertStringContainsString('This Connect link has expired', $response->body);
		$this->assertSame([], Whmcs::rows('mod_monokulo_nonce'));
	}

	/** The address was cleared since the page was opened: back to the page, which says so. */
	public function test_connect_without_an_address_goes_back(): void {
		$_SESSION['adminid'] = 1;
		Whmcs::setting('address', '');

		$response = $this->get($this->connectLink());

		$this->assertSame(self::ADMIN . '?monokulo_connect_error=address', $response->headers['Location']);
	}

	public function test_a_good_return_connects(): void {
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$response = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(303, $response->status);
		$this->assertSame(self::ADMIN . '?monokulo_connected=1', $response->headers['Location']);
		$request = self::$monokulo->requests()[0];
		$this->assertSame('/plugins/connect/whmcs/finish', $request['path']);
		$this->assertSame(['token' => 'tok_1', 'webhook_url' => Whmcs::CALLBACK, 'name' => 'WHMCS'], json_decode($request['body'], true));
		$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);

		$connection = $this->repository()->connection(Whmcs::HOST);
		$this->assertSame([self::$monokulo->url . '/api/v1', 'pk_new', 'sk_new', 'whsec_new'], [$connection->api, $connection->publicKey, $connection->secretToken, $connection->webhookSecret]);
		$this->assertSame(self::$monokulo->url . '/api/v1', Whmcs::rows('mod_monokulo_connection')[0]['api'], 'the entry is kept, resolved against the address');
		$this->assertNull($connection->onionApi, 'no onion address');
		$this->assertStringContainsString('localhost:8091 connected to Monokulo as pk_new', Whmcs::logs());
	}

	/** A Monokulo with an onion address hands over its entry there: kept with the connection. */
	public function test_connecting_keeps_the_onion_entry(): void {
		$this->monokuloFinishes(200, ['onion_api' => Api::ONION . '/api/v1'] + self::CREDENTIALS);

		$this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(Api::ONION . '/api/v1', $this->repository()->connection(Whmcs::HOST)->onionApi);
		$this->assertStringContainsString('as pk_new, on Tor at ' . Api::ONION . '/api/v1', Whmcs::logs());
	}

	/** A Monokulo under a path: /finish under it, and the entry it hands over kept with its path. */
	public function test_connecting_to_a_monokulo_under_a_path(): void {
		Whmcs::setting('address', self::$monokulo->url . '/monokulo/');
		self::$monokulo->respond('POST', '/monokulo/plugins/connect/whmcs/finish', 200, ['api' => '/monokulo/api/v1'] + self::CREDENTIALS);

		$response = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '?monokulo_connected=1', $response->headers['Location']);
		$this->assertSame(self::$monokulo->url . '/monokulo/api/v1', $this->repository()->connection(Whmcs::HOST)->api);
	}

	/** Connecting again forgets the entry kept for the old key. */
	public function test_connecting_again_forgets_the_kept_entry(): void {
		Whmcs::connect('https://old.example', 'whsec_old');
		Capsule::table('mod_monokulo_connection')->update(['entry' => '{"_links":{}}', 'entry_etag' => '"x"', 'entry_expires_at' => time() + 3600, 'onion_api' => 'http://old.onion/api/v1']);
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$row = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertSame([null, null, null, null], [$row['entry'], $row['entry_etag'], $row['entry_expires_at'], $row['onion_api']]);
	}

	/** Connecting again replaces the credentials (Monokulo replaced its webhook too). */
	public function test_connecting_again_replaces_the_credentials(): void {
		Whmcs::connect('https://old.example', 'whsec_old');
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame('pk_new', $this->repository()->connection(Whmcs::HOST)->publicKey);
		$this->assertCount(1, Whmcs::rows('mod_monokulo_connection'));
	}

	public function test_a_nonce_works_once(): void {
		$this->monokuloFinishes(200, self::CREDENTIALS);
		$nonce = $this->nonce();
		$this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $nonce]);
		Capsule::table('mod_monokulo_connection')->delete();

		$again = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $nonce]);

		$this->assertSame(400, $again->status);
		$this->assertArrayNotHasKey('Location', $again->headers);
		$this->assertStringContainsString('already been used or has expired', $again->body);
		$this->assertNull($this->repository()->connection(Whmcs::HOST));
		$this->assertCount(1, self::$monokulo->requests(), 'the token was not sent twice');
	}

	public static function badReturns(): array {
		return [
			'no nonce'      => [['token' => 'tok_1']],
			'unknown nonce' => [['token' => 'tok_1', 'nonce' => str_repeat('a', 32)]],
			'not a nonce'   => [['token' => 'tok_1', 'nonce' => "' OR 1=1 --"]],
		];
	}

	/** @param array<string, string> $query */
	#[\PHPUnit\Framework\Attributes\DataProvider('badReturns')]
	public function test_a_return_this_whmcs_did_not_start_is_refused(array $query): void {
		$this->assertSame(400, $this->get(['action' => 'return'] + $query)->status);
		$this->assertSame([], self::$monokulo->requests());
	}

	/** Without a token the nonce is still used up: the attempt must start again. */
	public function test_no_token_is_refused_and_spends_the_nonce(): void {
		$nonce = $this->nonce();

		$this->assertSame(400, $this->get(['action' => 'return', 'nonce' => $nonce])->status);
		$this->assertNull($this->repository()->consumeNonce($nonce, time()));
	}

	public function test_a_nonce_older_than_ten_minutes_is_refused(): void {
		$nonce = $this->nonce(Whmcs::HOST, time() - 601);

		$this->assertSame(400, $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $nonce])->status);
	}

	/** The System URL changed while the admin was on Monokulo: refused, nothing connected. */
	public function test_a_changed_system_url_is_refused(): void {
		$nonce = $this->nonce('old.example');

		$response = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $nonce]);

		$this->assertSame(self::ADMIN . '?monokulo_connect_error=host', $response->headers['Location']);
		$this->assertSame([], self::$monokulo->requests());
	}

	public static function finishFailures(): array {
		return [
			'no public address yet' => [503, Api::FINISH_PROBLEMS['no-public-address'], 'unavailable'],
			'token refused'         => [401, Api::FINISH_PROBLEMS['invalid-connect-code'], 'finish'],
			'rate limited'          => [429, Api::FINISH_PROBLEMS['rate-limited'], 'busy'],
			'failed after spending' => [500, Api::FINISH_PROBLEMS['internal'], 'finish'],
			'callback refused'      => [400, Api::FINISH_PROBLEMS['invalid-request'], 'callback'],
			'not a plugin slug'     => [404, Api::FINISH_PROBLEMS['not-found'], 'finish'],
			'too large'             => [413, Api::FINISH_PROBLEMS['too-large'], 'finish'],
			'not JSON'              => [415, Api::FINISH_PROBLEMS['unsupported-media-type'], 'finish'],
			'missing secret'        => [200, ['public_key' => 'pk', 'secret_token' => 'sk', 'api' => '/api/v1'], 'finish'],
			'missing entry'         => [200, ['public_key' => 'pk', 'secret_token' => 'sk', 'webhook_signing_secret' => 'wh'], 'finish'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('finishFailures')]
	public function test_when_monokulo_does_not_hand_over_the_keys_the_admin_is_told(int $status, mixed $body, string $error): void {
		$this->monokuloFinishes($status, $body);

		$response = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '?monokulo_connect_error=' . $error, $response->headers['Location']);
		$this->assertNull($this->repository()->connection(Whmcs::HOST));
		$this->assertStringContainsString('Connect finish at ' . self::$monokulo->url . ' failed: HTTP ' . $status, Whmcs::logs());
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		Whmcs::setting('address', 'http://127.0.0.1:' . FakeMonokulo::freePort());

		$response = $this->get(['action' => 'return', 'token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '?monokulo_connect_error=unreachable', $response->headers['Location']);
	}
}

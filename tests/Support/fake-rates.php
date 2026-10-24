<?php
/**
 * A stand-in for CoinGecko's price API, for the smoke test's Monokulo
 * (docker/monokulo-smoke.toml): 1 XMR is 150 in every currency asked for,
 * so making an order never waits on, or is refused by, the real one. A
 * router for PHP's built-in server (scripts/smoke).
 *
 * Except EUR: asked for it, it rate limits Monokulo (429, Retry-After 30),
 * as CoinGecko does when it's busy, so Monokulo has no rate for it.
 *
 * Monokulo also asks which currencies there are
 * (/api/v3/simple/supported_vs_currencies): a JSON array of lowercase
 * tickers, as CoinGecko answers, EUR among them, so EUR is refused for
 * having no rate rather than for being unknown.
 */
$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/v3/simple/supported_vs_currencies') {
	header('Content-Type: application/json');
	echo json_encode(['usd', 'eur', 'gbp', 'aud', 'cad', 'chf', 'jpy', 'nzd', 'sek', 'nok', 'dkk', 'pln', 'czk', 'brl', 'inr', 'zar', 'btc']);

	return true;
}
if ($path !== '/api/v3/simple/price') {
	http_response_code(404);

	return true;
}
if (in_array('eur', explode(',', strtolower((string)($_GET['vs_currencies'] ?? ''))), true)) {
	http_response_code(429);
	header('Retry-After: 30');
	header('Content-Type: application/json');
	echo '{"status":{"error_code":429,"error_message":"You\'ve exceeded the Rate Limit."}}';

	return true;
}
$prices = [];
foreach (explode(',', strtolower((string)($_GET['vs_currencies'] ?? ''))) as $currency) {
	if ($currency !== '') {
		$prices[$currency] = 150;
	}
}
header('Content-Type: application/json');
echo json_encode(['monero' => $prices]);

return true;

<?php
/**
 * A stand-in for CoinGecko's price API, for the smoke test's Monokulo
 * (docker/monokulo-smoke.toml): 1 XMR is 150 in every currency asked for,
 * so making an order never waits on, or is refused by, the real one. A
 * router for PHP's built-in server (scripts/smoke).
 */
if (parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/v3/simple/price') {
	http_response_code(404);

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

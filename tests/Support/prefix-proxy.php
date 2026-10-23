<?php
/**
 * A reverse proxy that serves a Monokulo under a path, as a merchant's own
 * proxy would: `http://localhost:8085/monokulo/…` is passed on to
 * PREFIX_PROXY_UPSTREAM as `/…`, the path stripped, everything else as it
 * came (the Host header too). A router for PHP's built-in server; the
 * smoke suite's second run goes through it (scripts/smoke).
 */
$prefix = (string)getenv('PREFIX_PROXY_PREFIX');
$upstream = (string)getenv('PREFIX_PROXY_UPSTREAM');
$uri = (string)$_SERVER['REQUEST_URI'];
if ($uri !== $prefix && !str_starts_with($uri, $prefix . '/') && !str_starts_with($uri, $prefix . '?')) {
	http_response_code(404);
	echo 'Not under ' . $prefix;

	return true;
}
$rest = substr($uri, strlen($prefix));
if ($rest === '' || $rest[0] === '?') {
	$rest = '/' . $rest;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
	if (!in_array(strtolower($name), ['content-length', 'connection', 'expect'], true)) {
		$headers[] = $name . ': ' . $value;
	}
}
$headers[] = 'Expect:';
$curl = curl_init($upstream . $rest);
curl_setopt_array($curl, [
	CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'],
	CURLOPT_HTTPHEADER     => $headers,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => true,
	CURLOPT_FOLLOWLOCATION => false,
	CURLOPT_TIMEOUT        => 60,
]);
$body = (string)file_get_contents('php://input');
if ($body !== '') {
	curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
}
$raw = curl_exec($curl);
if ($raw === false) {
	http_response_code(502);
	echo 'Upstream: ' . curl_error($curl);

	return true;
}
$size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
http_response_code((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
header_remove('X-Powered-By');
foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
	$name = strtolower(trim((string)strstr($line, ':', true)));
	if ($name !== '' && !in_array($name, ['transfer-encoding', 'connection', 'content-length', 'keep-alive'], true)) {
		header($line, false);
	}
}
echo substr($raw, $size);

return true;

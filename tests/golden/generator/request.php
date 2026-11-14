<?php

// One web request against the PHP app, described by the CASE_JSON variable:
// the query string, an optional urlencoded POST body, the host and whether it
// came over HTTPS. parse_str() fills $_GET and $_POST with the parser PHP uses
// for real requests. The body goes to stdout; a JSON report to stderr marks a
// request that ran to its end (the CLI keeps no status code or headers, so
// http_parity.py compares those over HTTP).

$case = json_decode(getenv('CASE_JSON'), true, flags: JSON_THROW_ON_ERROR);
parse_str($case['query'], $_GET);
$_SERVER['REQUEST_METHOD'] = $case['body'] === null ? 'GET' : 'POST';
if ($case['body'] !== null) {
    parse_str($case['body'], $_POST);
}
$_SERVER['HTTP_HOST'] = $case['host'];
$_SERVER['SCRIPT_NAME'] = '/sunrise-sunset-calendar.php';
if ($case['https']) {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
}
register_shutdown_function(function (): void {
    file_put_contents('php://stderr', json_encode(['status' => http_response_code(), 'headers' => headers_list()]));
});
chdir('/var/www/html');
require '/var/www/html/sunrise-sunset-calendar.php';

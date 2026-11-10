<?php

/*
 * Router for `php -S`: a stand-in Watch Tower ingest endpoint plus an echo
 * endpoint for outgoing-request tests. Captured requests are written to
 * WT_CAPTURE_DIR; the ingest status code is read from WT_CAPTURE_DIR/status.
 */

$dir = (string) getenv('WT_CAPTURE_DIR');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

if ($path === '/api/ingest/v1') {
    $status = is_file($dir.'/status') ? (int) trim((string) file_get_contents($dir.'/status')) : 202;
    file_put_contents($dir.'/request-'.sprintf('%.6F', microtime(true)).'-'.mt_rand().'.json', json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => $headers,
        'body' => base64_encode($body),
        'status' => $status,
    ]));
    http_response_code($status);
    header('Content-Type: application/json');
    echo '{"accepted":true}';

    return true;
}

if ($path === '/echo') {
    http_response_code((int) ($_GET['status'] ?? 200));
    header('Content-Type: application/json');
    echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers, 'body' => $body]);

    return true;
}

http_response_code(404);
echo 'not found';

return true;

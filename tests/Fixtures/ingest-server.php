<?php

/*
 * Router for `php -S`: a stand-in Watch Tower ingest endpoint (plus Shield rules, the
 * browser script and browser beacons) and an echo endpoint for outgoing-request tests.
 * Captured requests are written to WT_CAPTURE_DIR; the ingest status code is read from
 * WT_CAPTURE_DIR/status.
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

if ($path === '/api/shield/v1/rules') {
    $rules = is_file($dir.'/shield-rules.json') ? (string) file_get_contents($dir.'/shield-rules.json') : '';
    file_put_contents($dir.'/shield-request-'.sprintf('%.6F', microtime(true)).'-'.mt_rand().'.json', json_encode(['headers' => $headers]));

    if ($rules === '') {
        http_response_code(503);

        return true;
    }

    $version = (string) (json_decode($rules, true)['version'] ?? '');

    if (($headers['if-none-match'] ?? null) === '"'.$version.'"') {
        http_response_code(304);

        return true;
    }

    header('Content-Type: application/json');
    header('ETag: "'.$version.'"');
    echo $rules;

    return true;
}

if ($path === '/watchtower-browser.js') {
    file_put_contents($dir.'/browser-script-'.sprintf('%.6F', microtime(true)).'-'.mt_rand().'.json', json_encode(['headers' => $headers]));

    if (is_file($dir.'/browser-down')) {
        http_response_code(503);

        return true;
    }

    header('Content-Type: application/javascript');
    echo '/* watchtower-browser */';

    return true;
}

if (str_starts_with($path, '/api/browser/v1/')) {
    file_put_contents($dir.'/browser-beacon-'.sprintf('%.6F', microtime(true)).'-'.mt_rand().'.json', json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'headers' => $headers,
        'body' => $body,
    ]));
    http_response_code(204);

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

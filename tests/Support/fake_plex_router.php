<?php

// `php -S` router for FakePlex: answers each request path with $FAKE_PLEX_DIR/<path>.xml, 404 otherwise.
$rFile = getenv('FAKE_PLEX_DIR') . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '.xml';

if (is_file($rFile)) {
    header('Content-Type: application/xml');
    readfile($rFile);
} else {
    http_response_code(404);
}

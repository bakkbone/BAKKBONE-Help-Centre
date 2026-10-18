<?php
// Fallback for installs where the project folder itself is the web root.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with(str_replace('\\', '/', $f), str_replace('\\', '/', __DIR__) . '/public/') && is_file($f) && !str_ends_with($f, '.php')) {
        return false;
    }
}

define('HC_VIA_ROOT', true);
require __DIR__ . '/public/index.php';

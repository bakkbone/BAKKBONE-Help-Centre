<?php
declare(strict_types=1);

define('HC_ROOT', dirname(__DIR__));
define('HC_VERSION', '1.0.0');
define('HC_SCHEMA_VERSION', 3);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit("BAKKBONE Help Centre requires PHP 8.1 or newer (this server runs " . PHP_VERSION . "). Ask your host to switch PHP versions.\n");
}
foreach (['pdo', 'mbstring', 'openssl', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("BAKKBONE Help Centre requires the PHP '$ext' extension. Ask your host to enable it.\n");
    }
}

if (is_file(HC_ROOT . '/vendor/autoload.php')) {
    require HC_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Bakkbone\\HelpCentre\\';
        if (str_starts_with($class, $prefix)) {
            $file = HC_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require HC_ROOT . '/src/helpers.php';
}

\Bakkbone\HelpCentre\Core\App::boot();

if (!\Bakkbone\HelpCentre\Core\Dependencies::register()) {
    \Bakkbone\HelpCentre\Core\Dependencies::firstRun();
}

if (\Bakkbone\HelpCentre\Core\App::$installed && (int)\Bakkbone\HelpCentre\Core\Settings::get('schema_version') < HC_SCHEMA_VERSION) {
    try {
        \Bakkbone\HelpCentre\Services\Installer::upgrade();
    } catch (\Throwable $e) {
        error_log('[HelpCentre] Automatic database upgrade failed: ' . $e->getMessage());
        // Remember why, so admins see it in the panel (System log + banner) instead of a vague error later.
        try {
            $prev = (string)\Bakkbone\HelpCentre\Core\Settings::get('schema_upgrade_error');
            \Bakkbone\HelpCentre\Core\Settings::set('schema_upgrade_error', mb_substr($e->getMessage(), 0, 1000));
            if ($prev !== $e->getMessage()) {
                \Bakkbone\HelpCentre\Core\Log::error('Automatic database upgrade failed', $e->getMessage());
            }
        } catch (\Throwable) {
        }
    }
}

// A crashing add-on switches itself off; 'safe_mode' => true in config/config.php loads none.
\Bakkbone\HelpCentre\Core\Addons::boot();

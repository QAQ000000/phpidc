<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

require_once __DIR__.'/vendor/autoload.php';

// Keep the documented CLI gate usable with the host's 128M PHP default.
ini_set('memory_limit', '1G');

// PHPStan 2.3 restores dependency metadata before running Larastan's deferred
// bootstrap. Stub selection needs this version during that earlier reflection.
// Read the installed framework version without booting Laravel or opening I/O.
if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', Application::VERSION);
}

return [];

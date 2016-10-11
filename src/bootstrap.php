<?php
// Check for php version
if (version_compare('7.0.0', PHP_VERSION, '>')) {
    fwrite(
        STDERR,
        'PHP 7.0 or later is needed to run this application.'.PHP_EOL
    );

    exit(1);
}

// Set timezone if not set
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'UTC');
}

// Set error reporting
error_reporting(E_ALL);

// Ensure errors are displayed correctly
// CLI - display errors only if they're not already logged to STDERR
if (function_exists('ini_set') && (!ini_get('log_errors') || ini_get('error_log'))) {
    ini_set('display_errors', 1);
}

// Find composer's autoload.php file
$loader = null;
foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'] as $file) {
    if (file_exists($file)) {
        $loader = $file;
        break;
    }
}

// Check if project is not set up yet
if (is_null($loader)) {
    fwrite(STDERR,
        'You need to set up the project dependencies using the following commands:'.PHP_EOL.
        'wget http://getcomposer.org/composer.phar'.PHP_EOL.
        'php composer.phar install'.PHP_EOL
    );

    exit(1);
}

// Include composer autoload file
return include $loader;

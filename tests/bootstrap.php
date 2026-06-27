<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Minimal constants/stubs so unit tests can exercise plugin classes that touch
// WordPress globals without a full WordPress runtime.
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/');
}

if (! defined('CPULSE_VERSION')) {
    define('CPULSE_VERSION', '1.0.2');
}

if (! isset($GLOBALS['__cpulse_test_options'])) {
    $GLOBALS['__cpulse_test_options'] = [];
}

if (! function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['__cpulse_test_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['__cpulse_test_options'][$key] = $value;

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

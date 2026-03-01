<?php

use ContentPulse\WordPress\Plugin;

/**
 * Plugin Name: ContentPulse
 * Plugin URI: https://contentpulse.io
 * Description: Auto-publish AI-generated content from ContentPulse to your WordPress site.
 * Version: 1.0.0
 * Author: ContentPulse
 * Author URI: https://contentpulse.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: contentpulse-wp
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */
if (! defined('ABSPATH')) {
    exit;
}

define('CONTENTPULSE_WP_VERSION', '1.0.0');
define('CONTENTPULSE_WP_FILE', __FILE__);
define('CONTENTPULSE_WP_DIR', plugin_dir_path(__FILE__));
define('CONTENTPULSE_WP_URL', plugin_dir_url(__FILE__));

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

/**
 * Boot the plugin after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    $plugin = Plugin::getInstance();
    $plugin->boot();
});

register_activation_hook(__FILE__, function () {
    Plugin::activate();
});

register_deactivation_hook(__FILE__, function () {
    Plugin::deactivate();
});

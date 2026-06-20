<?php

use ContentPulse\WordPress\Plugin;

/**
 * Plugin Name: ContentPulse - AI SEO Autoblogger
 * Plugin URI: https://contentpulse.io/wordpress
 * Description: AI SEO autoblogger that auto-publishes AI-generated, SEO-optimized content from ContentPulse to your WordPress site.
 * Version: 1.0.1
 * Author: ContentPulse
 * Author URI: https://contentpulse.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: contentpulse-ai-seo-autoblogger
 * Requires at least: 5.9
 * Requires PHP: 8.2
 */
if (! defined('ABSPATH')) {
    exit;
}

// Plugin-internal globals use the distinct "cpulse"/"CPULSE" prefix (not the
// common word "content") to avoid collisions with other plugins and core.
define('CPULSE_VERSION', '1.0.1');
define('CPULSE_FILE', __FILE__);
define('CPULSE_DIR', plugin_dir_path(__FILE__));
define('CPULSE_URL', plugin_dir_url(__FILE__));

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

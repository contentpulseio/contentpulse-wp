<?php

declare(strict_types=1);

namespace ContentPulse\WordPress;

use ContentPulse\WordPress\Api\Routes;

final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('admin_menu', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerRoutes(): void
    {
        $routes = new Routes;
        $routes->register();
    }

    public function registerSettingsPage(): void
    {
        add_options_page(
            __('ContentPulse Settings', 'contentpulse-wp'),
            __('ContentPulse', 'contentpulse-wp'),
            'manage_options',
            'contentpulse-settings',
            [$this, 'renderSettingsPage'],
        );
    }

    public function renderSettingsPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $apiUrl = get_option('contentpulse_api_url', '');
        $apiKey = get_option('contentpulse_api_key', '');

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('ContentPulse Settings', 'contentpulse-wp').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('contentpulse_settings');
        do_settings_sections('contentpulse-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function registerSettings(): void
    {
        register_setting('contentpulse_settings', 'contentpulse_api_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        register_setting('contentpulse_settings', 'contentpulse_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        add_settings_section(
            'contentpulse_api_section',
            __('API Connection', 'contentpulse-wp'),
            function () {
                echo '<p>'.esc_html__('Configure your ContentPulse API connection.', 'contentpulse-wp').'</p>';
            },
            'contentpulse-settings',
        );

        add_settings_field(
            'contentpulse_api_url',
            __('API URL', 'contentpulse-wp'),
            fn () => printf(
                '<input type="url" name="contentpulse_api_url" value="%s" class="regular-text">',
                esc_attr(get_option('contentpulse_api_url', '')),
            ),
            'contentpulse-settings',
            'contentpulse_api_section',
        );

        add_settings_field(
            'contentpulse_api_key',
            __('API Key', 'contentpulse-wp'),
            fn () => printf(
                '<input type="password" name="contentpulse_api_key" value="%s" class="regular-text">',
                esc_attr(get_option('contentpulse_api_key', '')),
            ),
            'contentpulse-settings',
            'contentpulse_api_section',
        );
    }

    public static function activate(): void
    {
        update_option('contentpulse_wp_version', CONTENTPULSE_WP_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}

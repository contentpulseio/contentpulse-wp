<?php

declare(strict_types=1);

namespace ContentPulse\WordPress;

use ContentPulse\WordPress\Api\Routes;
use ContentPulse\WordPress\Support\SyncHistoryService;

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
        add_action('wp_head', [$this, 'renderContentPulseMetaTags'], 5);
        add_action('admin_post_contentpulse_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_contentpulse_send_sample', [$this, 'handleSendSampleContent']);
    }

    public function renderContentPulseMetaTags(): void
    {
        if (! is_singular('post')) {
            return;
        }

        // Avoid duplicate head tags when full SEO plugins are active.
        if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION')) {
            return;
        }

        $postId = get_queried_object_id();
        if (! is_int($postId) || $postId <= 0) {
            return;
        }

        $metaTitle = trim((string) get_post_meta($postId, '_contentpulse_meta_title', true));
        $metaDescription = trim((string) get_post_meta($postId, '_contentpulse_meta_description', true));
        $metaKeywords = trim((string) get_post_meta($postId, '_contentpulse_meta_keywords', true));
        $metaRobots = trim((string) get_post_meta($postId, '_contentpulse_meta_robots', true));
        $ogTitle = trim((string) get_post_meta($postId, '_contentpulse_og_title', true));
        $ogDescription = trim((string) get_post_meta($postId, '_contentpulse_og_description', true));
        $twitterTitle = trim((string) get_post_meta($postId, '_contentpulse_twitter_title', true));
        $twitterDescription = trim((string) get_post_meta($postId, '_contentpulse_twitter_description', true));

        if (
            $metaTitle === '' &&
            $metaDescription === '' &&
            $metaKeywords === '' &&
            $metaRobots === '' &&
            $ogTitle === '' &&
            $ogDescription === '' &&
            $twitterTitle === '' &&
            $twitterDescription === ''
        ) {
            return;
        }

        if ($metaDescription !== '') {
            echo '<meta name="description" content="'.esc_attr($metaDescription).'">'."\n";
        }
        if ($metaKeywords !== '') {
            echo '<meta name="keywords" content="'.esc_attr($metaKeywords).'">'."\n";
        }
        if ($metaRobots !== '') {
            echo '<meta name="robots" content="'.esc_attr($metaRobots).'">'."\n";
        }

        $resolvedOgTitle = $ogTitle !== '' ? $ogTitle : $metaTitle;
        $resolvedOgDescription = $ogDescription !== '' ? $ogDescription : $metaDescription;
        if ($resolvedOgTitle !== '') {
            echo '<meta property="og:title" content="'.esc_attr($resolvedOgTitle).'">'."\n";
        }
        if ($resolvedOgDescription !== '') {
            echo '<meta property="og:description" content="'.esc_attr($resolvedOgDescription).'">'."\n";
        }
        echo '<meta property="og:type" content="article">'."\n";
        echo '<meta property="og:url" content="'.esc_url(get_permalink($postId) ?: '').'">'."\n";

        $resolvedTwitterTitle = $twitterTitle !== '' ? $twitterTitle : $resolvedOgTitle;
        $resolvedTwitterDescription = $twitterDescription !== '' ? $twitterDescription : $resolvedOgDescription;
        if ($resolvedTwitterTitle !== '') {
            echo '<meta name="twitter:title" content="'.esc_attr($resolvedTwitterTitle).'">'."\n";
        }
        if ($resolvedTwitterDescription !== '') {
            echo '<meta name="twitter:description" content="'.esc_attr($resolvedTwitterDescription).'">'."\n";
        }
        echo '<meta name="twitter:card" content="summary_large_image">'."\n";
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

        $sharedKey = (string) get_option('contentpulse_api_key', '');
        $recentSyncs = (new SyncHistoryService)->latest(10);

        $noticeMessage = isset($_GET['contentpulse_notice']) ? sanitize_text_field(wp_unslash($_GET['contentpulse_notice'])) : '';
        $noticeType = isset($_GET['contentpulse_notice_type']) ? sanitize_key(wp_unslash($_GET['contentpulse_notice_type'])) : '';
        $noticeClass = $noticeType === 'success' ? 'notice notice-success' : 'notice notice-error';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ContentPulse Settings', 'contentpulse-wp'); ?></h1>

            <?php if ($noticeMessage !== '') { ?>
                <div class="<?php echo esc_attr($noticeClass); ?>">
                    <p><?php echo esc_html($noticeMessage); ?></p>
                </div>
            <?php } ?>

            <h2><?php echo esc_html__('1) Configure inbound shared key', 'contentpulse-wp'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('contentpulse_settings'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label for="contentpulse_api_key"><?php echo esc_html__('Inbound Shared Key', 'contentpulse-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                id="contentpulse_api_key"
                                type="password"
                                name="contentpulse_api_key"
                                value="<?php echo esc_attr($sharedKey); ?>"
                                class="regular-text"
                                autocomplete="off"
                            >
                            <p class="description">
                                <?php echo esc_html__('This key must match the X-ContentPulse-Key header sent by ContentPulse.', 'contentpulse-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Shared Key', 'contentpulse-wp')); ?>
            </form>

            <h2><?php echo esc_html__('2) Run quick checks', 'contentpulse-wp'); ?></h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="contentpulse_test_connection">
                    <?php wp_nonce_field('contentpulse_test_connection'); ?>
                    <?php submit_button(__('Test Connection', 'contentpulse-wp'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="contentpulse_send_sample">
                    <?php wp_nonce_field('contentpulse_send_sample'); ?>
                    <?php submit_button(__('Send Sample Content', 'contentpulse-wp'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2><?php echo esc_html__('Recent Syncs', 'contentpulse-wp'); ?></h2>
            <?php if (empty($recentSyncs)) { ?>
                <p><?php echo esc_html__('No sync activity yet.', 'contentpulse-wp'); ?></p>
            <?php } else { ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Time', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Action', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Title', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('ContentPulse ID', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Post', 'contentpulse-wp'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentSyncs as $sync) { ?>
                        <tr>
                            <td><?php echo esc_html((string) ($sync['synced_at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($sync['action'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($sync['title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($sync['contentpulse_id'] ?? '')); ?></td>
                            <td>
                                <?php if (! empty($sync['url'])) { ?>
                                    <a href="<?php echo esc_url((string) $sync['url']); ?>" target="_blank" rel="noreferrer">
                                        <?php echo esc_html__('View', 'contentpulse-wp'); ?>
                                    </a>
                                <?php } else { ?>
                                    —
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('Quick Start', 'contentpulse-wp'); ?></h2>
            <ol>
                <li><?php echo esc_html__('Save an inbound shared key above.', 'contentpulse-wp'); ?></li>
                <li><?php echo esc_html__('Use Test Connection and Send Sample Content to verify end-to-end flow.', 'contentpulse-wp'); ?></li>
            </ol>
        </div>
        <?php
    }

    public function registerSettings(): void
    {
        register_setting('contentpulse_settings', 'contentpulse_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
    }

    public function handleTestConnection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-wp'));
        }
        check_admin_referer('contentpulse_test_connection');

        $key = (string) get_option('contentpulse_api_key', '');
        if ($key === '') {
            $this->redirectWithNotice('error', __('Please save your inbound shared key first.', 'contentpulse-wp'));
        }

        $response = $this->dispatchInternalRestRequest('GET', '/contentpulse/v1/plugin-info', [], $key);
        $status = $response->get_status();
        $body = $response->get_data();
        if ($status !== 200) {
            $message = __('Connection failed with HTTP ', 'contentpulse-wp').$status;
            if (is_array($body) && isset($body['message']) && is_string($body['message'])) {
                $message .= ': '.$body['message'];
            }
            $this->redirectWithNotice('error', $message);
        }

        $pluginVersion = is_array($body) ? (string) ($body['plugin_version'] ?? CONTENTPULSE_WP_VERSION) : CONTENTPULSE_WP_VERSION;
        $this->redirectWithNotice('success', sprintf(
            /* translators: %s: plugin version */
            __('Connection successful. Plugin version: %s', 'contentpulse-wp'),
            $pluginVersion,
        ));
    }

    public function handleSendSampleContent(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-wp'));
        }
        check_admin_referer('contentpulse_send_sample');

        $key = (string) get_option('contentpulse_api_key', '');
        if ($key === '') {
            $this->redirectWithNotice('error', __('Please save your inbound shared key first.', 'contentpulse-wp'));
        }

        $payload = $this->makeSamplePayload();
        $response = $this->dispatchInternalRestRequest('POST', '/contentpulse/v1/posts', $payload, $key);
        $status = $response->get_status();
        $body = $response->get_data();
        if (! in_array($status, [200, 201], true) || ! is_array($body)) {
            $message = __('Sample publish failed with HTTP ', 'contentpulse-wp').$status;
            if (is_array($body) && isset($body['message']) && is_string($body['message'])) {
                $message .= ': '.$body['message'];
            }
            $this->redirectWithNotice('error', $message);
        }

        $postId = (int) ($body['post_id'] ?? 0);
        $url = (string) ($body['url'] ?? '');
        $action = (string) ($body['action'] ?? 'created');
        $message = sprintf(
            /* translators: 1: action, 2: post id */
            __('Sample content %1$s successfully (Post #%2$d).', 'contentpulse-wp'),
            $action,
            $postId,
        );
        if ($url !== '') {
            $message .= ' '.$url;
        }

        $this->redirectWithNotice('success', $message);
    }

    /**
     * Dispatch an authenticated REST request internally to avoid loopback HTTP/cURL failures.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchInternalRestRequest(string $method, string $route, array $payload = [], string $key = ''): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, $route);
        if ($key !== '') {
            $request->set_header('X-ContentPulse-Key', $key);
        }

        if ($payload !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($payload));
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSamplePayload(): array
    {
        $suffix = gmdate('YmdHis');

        return [
            'contentpulse_id' => (int) $suffix,
            'title' => "ContentPulse Sample Post {$suffix}",
            'body_html' => '<h2>Sample content from ContentPulse</h2><p>This sample verifies plugin ingestion end-to-end.</p>',
            'excerpt' => 'Sample post generated by ContentPulse plugin.',
            'slug' => "contentpulse-sample-{$suffix}",
            'post_status' => 'draft',
            'categories' => [
                ['name' => 'ContentPulse'],
            ],
            'tags' => [
                ['name' => 'sample'],
                ['name' => 'wordpress'],
            ],
            'seo' => [
                'meta_title' => "ContentPulse Sample {$suffix}",
                'meta_description' => 'Sample SEO description generated by ContentPulse plugin.',
            ],
        ];
    }

    private function redirectWithNotice(string $type, string $message): never
    {
        $redirectUrl = add_query_arg([
            'page' => 'contentpulse-settings',
            'contentpulse_notice_type' => $type === 'success' ? 'success' : 'error',
            'contentpulse_notice' => $message,
        ], admin_url('options-general.php'));

        wp_safe_redirect($redirectUrl);
        exit;
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

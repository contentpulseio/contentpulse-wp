<?php

declare(strict_types=1);

namespace ContentPulse\WordPress;

use ContentPulse\Core\DTO\ContentFilters;
use ContentPulse\Core\DTO\ContentItem;
use ContentPulse\Http\ContentPulseClient;
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
        add_action('wp_head', [$this, 'renderContentPulseFeaturedImageStyleFix'], 6);
        add_action('admin_post_contentpulse_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_contentpulse_publish_ready', [$this, 'handlePublishReadyContent']);
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

    public function renderContentPulseFeaturedImageStyleFix(): void
    {
        if (is_admin()) {
            return;
        }

        echo '<style id="contentpulse-featured-image-fix">';
        echo 'figure.wp-block-post-featured-image{aspect-ratio:auto !important;}';
        echo 'figure.wp-block-post-featured-image[style*="aspect-ratio"]{aspect-ratio:auto !important;}';
        echo 'figure.wp-block-post-featured-image img.wp-post-image{height:auto !important;max-height:none !important;object-fit:contain !important;object-position:center center !important;}';
        echo '</style>';
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

        $settingsApiKey = (string) get_option('contentpulse_api_key', '');
        $recentSyncs = (new SyncHistoryService)->latest(10);
        $readyContents = [];
        $readyContentsError = '';

        if ($settingsApiKey !== '') {
            [$readyContents, $readyContentsError] = $this->fetchReadyContents($settingsApiKey);
        }

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

            <h2><?php echo esc_html__('1) Configure settings API key', 'contentpulse-wp'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('contentpulse_settings'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label for="contentpulse_api_key"><?php echo esc_html__('Settings API Key', 'contentpulse-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                id="contentpulse_api_key"
                                type="password"
                                name="contentpulse_api_key"
                                value="<?php echo esc_attr($settingsApiKey); ?>"
                                class="regular-text"
                                autocomplete="off"
                            >
                            <p class="description">
                                <?php echo esc_html__('Use one key for both directions: ContentPulse -> WordPress ingestion and WordPress -> ContentPulse publish-ready requests.', 'contentpulse-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings API Key', 'contentpulse-wp')); ?>
            </form>

            <h2><?php echo esc_html__('2) Run quick checks', 'contentpulse-wp'); ?></h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="contentpulse_test_connection">
                    <?php wp_nonce_field('contentpulse_test_connection'); ?>
                    <?php submit_button(__('Test Connection', 'contentpulse-wp'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2><?php echo esc_html__('3) Publish ready ContentPulse content', 'contentpulse-wp'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Ready contents are loaded from ContentPulse automatically via SDK.', 'contentpulse-wp'); ?>
            </p>
            <?php if ($settingsApiKey === '') { ?>
                <p><?php echo esc_html__('Save your settings API key to load ready contents.', 'contentpulse-wp'); ?></p>
            <?php } elseif ($readyContentsError !== '') { ?>
                <div class="notice notice-error inline">
                    <p><?php echo esc_html($readyContentsError); ?></p>
                </div>
            <?php } elseif (empty($readyContents)) { ?>
                <p><?php echo esc_html__('No ready contents found right now.', 'contentpulse-wp'); ?></p>
            <?php } else { ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Title', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Status', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Updated', 'contentpulse-wp'); ?></th>
                        <th><?php echo esc_html__('Action', 'contentpulse-wp'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($readyContents as $readyContent) { ?>
                        <tr>
                            <td><?php echo esc_html((string) ($readyContent['id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($readyContent['title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($readyContent['status'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($readyContent['updated_at'] ?? '')); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="contentpulse_publish_ready">
                                    <input type="hidden" name="contentpulse_content_id" value="<?php echo esc_attr((string) ($readyContent['id'] ?? '')); ?>">
                                    <?php wp_nonce_field('contentpulse_publish_ready'); ?>
                                    <button type="submit" class="button button-secondary">
                                        <?php echo esc_html__('Publish to WordPress', 'contentpulse-wp'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('4) Recent Syncs', 'contentpulse-wp'); ?></h2>
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
                <li><?php echo esc_html__('Save one Settings API Key above.', 'contentpulse-wp'); ?></li>
                <li><?php echo esc_html__('Use Test Connection to verify end-to-end flow.', 'contentpulse-wp'); ?></li>
                <li><?php echo esc_html__('Choose any ready content from the list and click Publish to WordPress.', 'contentpulse-wp'); ?></li>
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
            $this->redirectWithNotice('error', __('Please save your settings API key first.', 'contentpulse-wp'));
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

    public function handlePublishReadyContent(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-wp'));
        }
        check_admin_referer('contentpulse_publish_ready');

        $contentId = isset($_POST['contentpulse_content_id'])
            ? absint((string) wp_unslash($_POST['contentpulse_content_id']))
            : 0;
        if ($contentId <= 0) {
            $this->redirectWithNotice('error', __('Please provide a valid ContentPulse content ID.', 'contentpulse-wp'));
        }

        $sourceApiUrl = $this->resolveContentPulseApiBaseUrl();
        $sourceApiKey = trim((string) get_option('contentpulse_api_key', ''));
        if ($sourceApiUrl === '' || $sourceApiKey === '') {
            $this->redirectWithNotice('error', __('Please save your settings API key first.', 'contentpulse-wp'));
        }

        $endpoint = rtrim($sourceApiUrl, '/')."/api/v1/content/{$contentId}/publish-wordpress";
        $response = wp_remote_post($endpoint, [
            'timeout' => 25,
            'redirection' => 3,
            'reject_unsafe_urls' => false,
            'headers' => [
                'X-API-Key' => $sourceApiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->redirectWithNotice('error', __('Ready content publish failed: ', 'contentpulse-wp').$response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            $message = __('Ready content publish failed with HTTP ', 'contentpulse-wp').$status;
            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                $message .= ': '.$decoded['message'];
            }
            $this->redirectWithNotice('error', $message);
        }

        $remoteUrl = '';
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $remoteUrl = isset($decoded['data']['remote_url']) && is_string($decoded['data']['remote_url'])
                ? $decoded['data']['remote_url']
                : '';
        }

        $message = __('Ready content published to WordPress successfully.', 'contentpulse-wp');
        if (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
            $message = $decoded['message'];
        }
        if ($remoteUrl !== '') {
            $message .= ' '.$remoteUrl;
        }

        $this->redirectWithNotice('success', $message);
    }

    private function normalizeContentPulseApiUrl(string $url): string
    {
        $normalized = rtrim(trim($url), '/');
        if ($normalized === '') {
            return '';
        }

        if (str_ends_with($normalized, '/api/v1')) {
            return mb_substr($normalized, 0, -7);
        }

        return $normalized;
    }

    private function resolveContentPulseApiBaseUrl(): string
    {
        $configured = '';

        if (defined('CONTENTPULSE_API_URL')) {
            $constantUrl = constant('CONTENTPULSE_API_URL');
            if (is_string($constantUrl)) {
                $configured = $constantUrl;
            }
        } elseif (is_string(getenv('CONTENTPULSE_API_URL'))) {
            $configured = (string) getenv('CONTENTPULSE_API_URL');
        }

        if (trim($configured) === '') {
            $configured = 'http://host.docker.internal:8080';
        }

        $filtered = apply_filters('contentpulse_api_base_url', $configured);

        return $this->normalizeContentPulseApiUrl((string) $filtered);
    }

    /**
     * @return array{0: array<int, array{id: int, title: string, status: string, updated_at: string}>, 1: string}
     */
    private function fetchReadyContents(string $apiKey): array
    {
        $baseUrl = $this->resolveContentPulseApiBaseUrl();
        if ($baseUrl === '') {
            return [[], __('ContentPulse API URL could not be resolved.', 'contentpulse-wp')];
        }

        try {
            $client = new ContentPulseClient($baseUrl, $apiKey);
            $items = [];
            $currentPage = 1;
            $maxPages = 100;

            do {
                $feed = $client->getContentFeed(new ContentFilters(
                    page: $currentPage,
                    perPage: 50,
                ));

                foreach ($feed->items as $item) {
                    if (! $item instanceof ContentItem) {
                        continue;
                    }

                    $status = (string) ($item->status ?? '');
                    if (! in_array($status, ['draft', 'review', 'published', 'scheduled'], true)) {
                        continue;
                    }

                    $items[] = [
                        'id' => $item->id,
                        'title' => $item->title !== '' ? $item->title : $item->slug,
                        'status' => $status !== '' ? $status : 'unknown',
                        'updated_at' => $item->updatedAt?->format('Y-m-d H:i') ?? '',
                    ];
                }

                $currentPage++;
            } while ($feed->hasMorePages() && $currentPage <= $maxPages);

            usort($items, static function (array $a, array $b): int {
                return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
            });

            return [$items, ''];
        } catch (\Throwable $exception) {
            return [[], __('Failed to load ready contents: ', 'contentpulse-wp').$exception->getMessage()];
        }
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

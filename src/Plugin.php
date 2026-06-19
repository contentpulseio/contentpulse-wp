<?php

declare(strict_types=1);

namespace ContentPulse\WordPress;

use ContentPulse\Core\DTO\ContentFilters;
use ContentPulse\Core\DTO\ContentItem;
use ContentPulse\Http\ContentPulseClient;
use ContentPulse\WordPress\Api\Routes;
use ContentPulse\WordPress\Support\ContentPulseEndpointResolver;
use ContentPulse\WordPress\Support\PostUpsertService;
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

    public static function activate(): void
    {
        update_option('cpulse_version', CPULSE_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('admin_menu', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('wp_head', [$this, 'renderContentPulseMetaTags'], 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFeaturedImageStyleFix']);
        add_action('admin_post_cpulse_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_cpulse_test_api_key', [$this, 'handleTestApiKey']);
        add_action('admin_post_cpulse_publish_ready', [$this, 'handlePublishReadyContent']);
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

        $metaTitle = trim((string) get_post_meta($postId, '_cpulse_meta_title', true));
        $metaDescription = trim((string) get_post_meta($postId, '_cpulse_meta_description', true));
        $metaKeywords = trim((string) get_post_meta($postId, '_cpulse_meta_keywords', true));
        $metaRobots = trim((string) get_post_meta($postId, '_cpulse_meta_robots', true));
        $ogTitle = trim((string) get_post_meta($postId, '_cpulse_og_title', true));
        $ogDescription = trim((string) get_post_meta($postId, '_cpulse_og_description', true));
        $twitterTitle = trim((string) get_post_meta($postId, '_cpulse_twitter_title', true));
        $twitterDescription = trim((string) get_post_meta($postId, '_cpulse_twitter_description', true));

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

    public function enqueueFeaturedImageStyleFix(): void
    {
        if (is_admin()) {
            return;
        }

        $handle = 'cpulse-featured-image-fix';
        wp_register_style($handle, false, [], CPULSE_VERSION);
        wp_enqueue_style($handle);

        $css = 'figure.wp-block-post-featured-image{aspect-ratio:auto !important;}'
            .'figure.wp-block-post-featured-image[style*="aspect-ratio"]{aspect-ratio:auto !important;}'
            .'figure.wp-block-post-featured-image img.wp-post-image{height:auto !important;max-height:none !important;object-fit:contain !important;object-position:center center !important;}';

        wp_add_inline_style($handle, $css);
    }

    public function registerSettingsPage(): void
    {
        add_options_page(
            __('ContentPulse Settings', 'contentpulse-ai-seo-autoblogger'),
            __('ContentPulse', 'contentpulse-ai-seo-autoblogger'),
            'manage_options',
            'cpulse-settings',
            [$this, 'renderSettingsPage'],
        );
    }

    public function renderSettingsPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settingsApiKey = (string) get_option('cpulse_api_key', '');
        $recentSyncs = (new SyncHistoryService)->latest(10);
        $readyContents = [];
        $readyContentsError = '';

        if ($settingsApiKey !== '') {
            [$readyContents, $readyContentsError] = $this->fetchReadyContents($settingsApiKey);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, sanitized and escaped on output.
        $noticeMessage = isset($_GET['cpulse_notice']) ? sanitize_text_field(wp_unslash($_GET['cpulse_notice'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, sanitized and escaped on output.
        $noticeType = isset($_GET['cpulse_notice_type']) ? sanitize_key(wp_unslash($_GET['cpulse_notice_type'])) : '';
        $noticeClass = $noticeType === 'success' ? 'notice notice-success' : 'notice notice-error';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ContentPulse Settings', 'contentpulse-ai-seo-autoblogger'); ?></h1>

            <?php if ($noticeMessage !== '') { ?>
                <div class="<?php echo esc_attr($noticeClass); ?>">
                    <p><?php echo esc_html($noticeMessage); ?></p>
                </div>
            <?php } ?>

            <h2><?php echo esc_html__('1) Configure settings API key', 'contentpulse-ai-seo-autoblogger'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('cpulse_settings'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label for="cpulse_api_key"><?php echo esc_html__('Settings API Key', 'contentpulse-ai-seo-autoblogger'); ?></label>
                        </th>
                        <td>
                            <input
                                id="cpulse_api_key"
                                type="password"
                                name="cpulse_api_key"
                                value="<?php echo esc_attr($settingsApiKey); ?>"
                                class="regular-text"
                                autocomplete="off"
                            >
                            <p class="description">
                                <?php echo esc_html__('Use one key for both directions: ContentPulse -> WordPress ingestion and WordPress -> ContentPulse publish-ready requests.', 'contentpulse-ai-seo-autoblogger'); ?>
                            </p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings API Key', 'contentpulse-ai-seo-autoblogger')); ?>
            </form>

            <h2><?php echo esc_html__('2) Run quick checks', 'contentpulse-ai-seo-autoblogger'); ?></h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="cpulse_test_connection">
                    <?php wp_nonce_field('cpulse_test_connection'); ?>
                    <?php submit_button(__('Test Connection', 'contentpulse-ai-seo-autoblogger'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="cpulse_test_api_key">
                    <?php wp_nonce_field('cpulse_test_api_key'); ?>
                    <?php submit_button(__('Test API Key (ContentPulse)', 'contentpulse-ai-seo-autoblogger'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2><?php echo esc_html__('3) Publish ready ContentPulse content', 'contentpulse-ai-seo-autoblogger'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Ready contents are loaded from ContentPulse automatically via SDK.', 'contentpulse-ai-seo-autoblogger'); ?>
            </p>
            <?php if ($settingsApiKey === '') { ?>
                <p><?php echo esc_html__('Save your settings API key to load ready contents.', 'contentpulse-ai-seo-autoblogger'); ?></p>
            <?php } elseif ($readyContentsError !== '') { ?>
                <div class="notice notice-error inline">
                    <p><?php echo esc_html($readyContentsError); ?></p>
                </div>
            <?php } elseif (empty($readyContents)) { ?>
                <p><?php echo esc_html__('No ready contents found right now.', 'contentpulse-ai-seo-autoblogger'); ?></p>
            <?php } else { ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Title', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Status', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Date', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Actions', 'contentpulse-ai-seo-autoblogger'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                        $upsertService = new PostUpsertService;
                foreach ($readyContents as $readyContent) {
                    $contentId = (string) ($readyContent['id'] ?? '');
                    $contentPulseViewUrl = $this->buildContentPulseContentUrl($contentId);
                    $existingPostId = $upsertService->findByContentPulseId($contentId);
                    $existingPostUrl = $existingPostId ? get_permalink($existingPostId) : '';
                    $readyStatus = (string) ($readyContent['status'] ?? '');
                    $readyDate = match ($readyStatus) {
                        'scheduled' => (string) ($readyContent['scheduled_at'] ?? ''),
                        'published' => (string) ($readyContent['published_at'] ?? ''),
                        default => '',
                    };
                    ?>
                        <tr>
                            <td><?php echo esc_html((string) ($readyContent['id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($readyContent['title'] ?? '')); ?></td>
                            <td>
                                <?php echo esc_html($readyStatus); ?>
                                <?php if ($existingPostId) { ?>
                                    <span style="display:inline-block; margin-left:6px; padding:1px 6px; border-radius:9999px; background:#d1fae5; color:#065f46; font-size:11px; font-weight:600;">
                                        <?php echo esc_html__('Imported', 'contentpulse-ai-seo-autoblogger'); ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td><?php echo esc_html($readyDate); ?></td>
                            <td>
                                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="cpulse_publish_ready">
                                        <input type="hidden" name="cpulse_content_id" value="<?php echo esc_attr((string) ($readyContent['id'] ?? '')); ?>">
                                        <?php wp_nonce_field('cpulse_publish_ready'); ?>
                                        <button type="submit" class="button button-secondary">
                                            <?php echo $existingPostId
                                        ? esc_html__('Re-publish', 'contentpulse-ai-seo-autoblogger')
                                        : esc_html__('Publish to WordPress', 'contentpulse-ai-seo-autoblogger'); ?>
                                        </button>
                                    </form>
                                    <?php if ($existingPostId && $existingPostUrl) { ?>
                                        <a href="<?php echo esc_url((string) $existingPostUrl); ?>" class="button" target="_blank" rel="noreferrer">
                                            <?php echo esc_html__('View post', 'contentpulse-ai-seo-autoblogger'); ?>
                                        </a>
                                    <?php } ?>
                                    <?php if ($contentPulseViewUrl !== '') { ?>
                                        <a href="<?php echo esc_url($contentPulseViewUrl); ?>" class="button" target="_blank" rel="noreferrer">
                                            <?php echo esc_html__('View in ContentPulse', 'contentpulse-ai-seo-autoblogger'); ?>
                                        </a>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('4) Recent Syncs', 'contentpulse-ai-seo-autoblogger'); ?></h2>
            <?php if (empty($recentSyncs)) { ?>
                <p><?php echo esc_html__('No sync activity yet.', 'contentpulse-ai-seo-autoblogger'); ?></p>
            <?php } else { ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Time', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Action', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Title', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('ContentPulse ID', 'contentpulse-ai-seo-autoblogger'); ?></th>
                        <th><?php echo esc_html__('Post', 'contentpulse-ai-seo-autoblogger'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentSyncs as $sync) {
                        $syncContentId = (string) ($sync['contentpulse_id'] ?? '');
                        $isUlid = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $syncContentId) === 1;
                        $syncPostId = isset($sync['post_id']) ? (int) $sync['post_id'] : 0;
                        $syncEditUrl = $syncPostId > 0 ? get_edit_post_link($syncPostId, '') : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($sync['synced_at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($sync['action'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($sync['title'] ?? '')); ?></td>
                            <td><?php echo $isUlid ? esc_html($syncContentId) : '—'; ?></td>
                            <td>
                                <?php if ($syncEditUrl !== '') { ?>
                                    <a href="<?php echo esc_url((string) $syncEditUrl); ?>">
                                        <?php echo esc_html__('Edit', 'contentpulse-ai-seo-autoblogger'); ?>
                                    </a>
                                <?php } elseif (! empty($sync['url'])) { ?>
                                    <a href="<?php echo esc_url((string) $sync['url']); ?>" target="_blank" rel="noreferrer">
                                        <?php echo esc_html__('View', 'contentpulse-ai-seo-autoblogger'); ?>
                                    </a>
                                <?php } else { ?>
                                    -
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <h2><?php echo esc_html__('Quick Start', 'contentpulse-ai-seo-autoblogger'); ?></h2>
            <ol>
                <li><?php echo esc_html__('Save one Settings API Key above.', 'contentpulse-ai-seo-autoblogger'); ?></li>
                <li><?php echo esc_html__('Use Test Connection to verify end-to-end flow.', 'contentpulse-ai-seo-autoblogger'); ?></li>
                <li><?php echo esc_html__('Choose any ready content from the list and click Publish to WordPress.', 'contentpulse-ai-seo-autoblogger'); ?></li>
            </ol>
        </div>
        <?php
    }

    public function registerSettings(): void
    {
        register_setting('cpulse_settings', 'cpulse_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
    }

    public function handleTestConnection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-ai-seo-autoblogger'));
        }
        check_admin_referer('cpulse_test_connection');

        $key = (string) get_option('cpulse_api_key', '');
        if ($key === '') {
            $this->redirectWithNotice('error', __('Please save your settings API key first.', 'contentpulse-ai-seo-autoblogger'));
        }

        $response = $this->dispatchInternalRestRequest('GET', '/contentpulse/v1/plugin-info', [], $key);
        $status = $response->get_status();
        $body = $response->get_data();
        if ($status !== 200) {
            $message = __('Connection failed with HTTP ', 'contentpulse-ai-seo-autoblogger').$status;
            if (is_array($body) && isset($body['message']) && is_string($body['message'])) {
                $message .= ': '.$body['message'];
            }
            $this->redirectWithNotice('error', $message);
        }

        $pluginVersion = is_array($body) ? (string) ($body['plugin_version'] ?? CPULSE_VERSION) : CPULSE_VERSION;
        $this->redirectWithNotice('success', sprintf(
            /* translators: %s: plugin version */
            __('Connection successful. Plugin version: %s', 'contentpulse-ai-seo-autoblogger'),
            $pluginVersion,
        ));
    }

    public function handleTestApiKey(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-ai-seo-autoblogger'));
        }
        check_admin_referer('cpulse_test_api_key');

        $apiKey = trim((string) get_option('cpulse_api_key', ''));
        if ($apiKey === '') {
            $this->redirectWithNotice('error', __('Please save your settings API key first.', 'contentpulse-ai-seo-autoblogger'));
        }

        $baseUrl = $this->resolveContentPulseApiBaseUrl();

        try {
            $client = new ContentPulseClient(apiKey: $apiKey, baseUrl: $baseUrl);
            $feed = $client->getContentFeed(new ContentFilters(
                page: 1,
                perPage: 1,
            ));
            $itemCount = count($feed->items);

            $this->redirectWithNotice('success', sprintf(
                /* translators: %d: number of items returned from first page */
                __('API key test successful. Connected to ContentPulse and fetched %d item(s) from page 1.', 'contentpulse-ai-seo-autoblogger'),
                $itemCount
            ));
        } catch (\Throwable $exception) {
            $this->redirectWithNotice('error', __('API key test failed: ', 'contentpulse-ai-seo-autoblogger').$exception->getMessage());
        }
    }

    public function handlePublishReadyContent(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'contentpulse-ai-seo-autoblogger'));
        }
        check_admin_referer('cpulse_publish_ready');

        $contentId = isset($_POST['cpulse_content_id'])
            ? sanitize_text_field((string) wp_unslash($_POST['cpulse_content_id']))
            : '';
        if ($contentId === '') {
            $this->redirectWithNotice('error', __('Please provide a valid ContentPulse content ID.', 'contentpulse-ai-seo-autoblogger'));
        }

        $sourceApiUrl = $this->resolveContentPulseApiBaseUrl();
        $sourceApiKey = trim((string) get_option('cpulse_api_key', ''));
        if ($sourceApiKey === '') {
            $this->redirectWithNotice('error', __('Please save your settings API key first.', 'contentpulse-ai-seo-autoblogger'));
        }
        $endpoint = ContentPulseEndpointResolver::buildPublishWordPressEndpoint($sourceApiUrl, $contentId);
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
            $this->redirectWithNotice('error', __('Ready content publish failed: ', 'contentpulse-ai-seo-autoblogger').$response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            $message = __('Ready content publish failed with HTTP ', 'contentpulse-ai-seo-autoblogger').$status;
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

        $message = __('Ready content published to WordPress successfully.', 'contentpulse-ai-seo-autoblogger');
        if (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
            $message = $decoded['message'];
        }
        if ($remoteUrl !== '') {
            $message .= ' '.$remoteUrl;
        }

        $this->redirectWithNotice('success', $message);
    }

    private function resolveContentPulseApiBaseUrl(): string
    {
        $resolved = ContentPulseEndpointResolver::resolveApiBaseUrlFromEnvironment();
        $filtered = (string) apply_filters('cpulse_api_base_url', $resolved);

        return ContentPulseEndpointResolver::resolveApiBaseUrl($filtered);
    }

    /**
     * @return array{0: array<int, array{id: string, title: string, status: string, updated_at: string, published_at: string, scheduled_at: string}>, 1: string}
     */
    private function fetchReadyContents(string $apiKey): array
    {
        $baseUrl = $this->resolveContentPulseApiBaseUrl();

        try {
            $client = new ContentPulseClient(apiKey: $apiKey, baseUrl: $baseUrl);
            $items = [];
            $feed = $client->getContentFeed(new ContentFilters(
                page: 1,
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
                    'published_at' => $item->publishedAt?->format('Y-m-d H:i') ?? '',
                    'scheduled_at' => $item->scheduledAt?->format('Y-m-d H:i') ?? '',
                ];
            }

            return [$items, ''];
        } catch (\Throwable $exception) {
            return [[], __('Failed to load ready contents: ', 'contentpulse-ai-seo-autoblogger').$exception->getMessage()];
        }
    }

    private function buildContentPulseContentUrl(string $contentId): string
    {
        if (trim($contentId) === '') {
            return '';
        }

        $resolved = ContentPulseEndpointResolver::resolveAppBaseUrlFromEnvironment();
        $filtered = (string) apply_filters('cpulse_app_base_url', $resolved);

        return ContentPulseEndpointResolver::buildContentUrl($filtered, $contentId);
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
            'page' => 'cpulse-settings',
            'cpulse_notice_type' => $type === 'success' ? 'success' : 'error',
            'cpulse_notice' => $message,
        ], admin_url('options-general.php'));

        wp_safe_redirect($redirectUrl);
        exit;
    }
}

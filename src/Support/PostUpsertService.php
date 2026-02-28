<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Support;

use WP_Error;

/**
 * Creates or updates WordPress posts from ContentPulse payloads.
 *
 * Uses `_contentpulse_id` post meta to track the mapping between
 * ContentPulse content IDs and WordPress post IDs.
 */
class PostUpsertService
{
    /**
     * Create or update a post based on the ContentPulse payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{action: string, post_id: int, url: string}|WP_Error
     */
    public function upsert(array $payload, ?int $featuredImageId = null): array|WP_Error
    {
        $contentPulseId = $payload['contentpulse_id'] ?? null;
        $existingPostId = $this->findByContentPulseId($contentPulseId);

        $postData = [
            'post_title' => sanitize_text_field($payload['title'] ?? ''),
            'post_content' => wp_kses_post($payload['body_html'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($payload['excerpt'] ?? ''),
            'post_name' => sanitize_title($payload['slug'] ?? ''),
            'post_status' => $this->resolvePostStatus($payload),
            'post_author' => $this->resolvePostAuthorId($payload),
            'post_type' => 'post',
        ];

        if (! empty($payload['scheduled_at']) && $postData['post_status'] === 'future') {
            $postData['post_date'] = $payload['scheduled_at'];
            $postData['post_date_gmt'] = get_gmt_from_date($payload['scheduled_at']);
        } elseif (! empty($payload['published_at']) && $postData['post_status'] === 'publish') {
            $postData['post_date'] = $payload['published_at'];
            $postData['post_date_gmt'] = get_gmt_from_date($payload['published_at']);
        }

        if ($existingPostId) {
            $postData['ID'] = $existingPostId;
            $result = wp_update_post($postData, true);
            $action = 'updated';
        } else {
            $result = wp_insert_post($postData, true);
            $action = 'created';
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $postId = (int) $result;

        if ($contentPulseId) {
            update_post_meta($postId, '_contentpulse_id', (int) $contentPulseId);
        }

        if ($featuredImageId) {
            set_post_thumbnail($postId, $featuredImageId);
        }

        $this->applySeoMeta($postId, $payload['seo'] ?? []);
        $this->applyTaxonomies($postId, $payload);
        $this->updateSyncTracking($action, $postId, $payload);

        return [
            'action' => $action,
            'post_id' => $postId,
            'url' => get_permalink($postId) ?: '',
        ];
    }

    /**
     * Find a WordPress post ID by its ContentPulse content ID.
     */
    public function findByContentPulseId(int|string|null $contentPulseId): ?int
    {
        if ($contentPulseId === null) {
            return null;
        }

        global $wpdb;

        $postId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_contentpulse_id' AND meta_value = %d LIMIT 1",
                (int) $contentPulseId,
            ),
        );

        return $postId ? (int) $postId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePostStatus(array $payload): string
    {
        $requested = sanitize_key((string) ($payload['post_status'] ?? 'draft'));
        $allowed = ['publish', 'future', 'draft', 'pending', 'private'];

        return in_array($requested, $allowed, true) ? $requested : 'draft';
    }

    /**
     * Apply SEO meta fields to the post.
     *
     * @param  array<string, mixed>  $seo
     */
    private function applySeoMeta(int $postId, array $seo): void
    {
        if (empty($seo)) {
            return;
        }

        $metaMap = [
            'meta_title' => '_contentpulse_meta_title',
            'meta_description' => '_contentpulse_meta_description',
            'meta_keywords' => '_contentpulse_meta_keywords',
            'og_title' => '_contentpulse_og_title',
            'og_description' => '_contentpulse_og_description',
            'twitter_title' => '_contentpulse_twitter_title',
            'twitter_description' => '_contentpulse_twitter_description',
            'meta_robots' => '_contentpulse_meta_robots',
        ];

        foreach ($metaMap as $seoKey => $metaKey) {
            if (isset($seo[$seoKey])) {
                $value = is_array($seo[$seoKey]) ? implode(', ', $seo[$seoKey]) : $seo[$seoKey];
                update_post_meta($postId, $metaKey, sanitize_text_field((string) $value));
            }
        }

        // Yoast SEO integration
        if (function_exists('wpseo_init') || defined('WPSEO_VERSION')) {
            if (isset($seo['meta_title'])) {
                update_post_meta($postId, '_yoast_wpseo_title', sanitize_text_field($seo['meta_title']));
            }
            if (isset($seo['meta_description'])) {
                update_post_meta($postId, '_yoast_wpseo_metadesc', sanitize_text_field($seo['meta_description']));
            }
        }

        // Rank Math integration
        if (defined('RANK_MATH_VERSION')) {
            if (isset($seo['meta_title'])) {
                update_post_meta($postId, 'rank_math_title', sanitize_text_field($seo['meta_title']));
            }
            if (isset($seo['meta_description'])) {
                update_post_meta($postId, 'rank_math_description', sanitize_text_field($seo['meta_description']));
            }
        }
    }

    /**
     * Resolve a valid author ID to avoid empty bylines (author=0).
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolvePostAuthorId(array $payload): int
    {
        $requestedAuthor = isset($payload['post_author']) ? (int) $payload['post_author'] : 0;
        if ($requestedAuthor > 0 && get_user_by('id', $requestedAuthor) instanceof \WP_User) {
            return $requestedAuthor;
        }

        $admins = get_users([
            'role' => 'administrator',
            'number' => 1,
            'fields' => 'ids',
        ]);

        if (is_array($admins) && isset($admins[0])) {
            return (int) $admins[0];
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyTaxonomies(int $postId, array $payload): void
    {
        if (! empty($payload['categories'])) {
            $categoryIds = [];
            foreach ($payload['categories'] as $cat) {
                $name = is_array($cat) ? ($cat['name'] ?? '') : (string) $cat;
                if ($name) {
                    $term = term_exists($name, 'category');
                    if (! $term) {
                        $term = wp_insert_term($name, 'category');
                    }
                    if (! is_wp_error($term)) {
                        $categoryIds[] = (int) $term['term_id'];
                    }
                }
            }
            if ($categoryIds) {
                wp_set_post_categories($postId, $categoryIds);
            }
        }

        if (! empty($payload['tags'])) {
            $tagNames = array_map(
                fn ($tag) => is_array($tag) ? ($tag['name'] ?? '') : (string) $tag,
                $payload['tags'],
            );
            wp_set_post_tags($postId, array_filter($tagNames));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateSyncTracking(string $action, int $postId, array $payload): void
    {
        update_option('contentpulse_last_sync', current_time('mysql'));
        $count = (int) get_option('contentpulse_sync_count', 0);
        update_option('contentpulse_sync_count', $count + 1);

        $history = new SyncHistoryService;
        $history->record([
            'action' => $action,
            'post_id' => $postId,
            'url' => get_permalink($postId) ?: '',
            'title' => (string) ($payload['title'] ?? ''),
            'status' => (string) ($payload['post_status'] ?? 'draft'),
            'contentpulse_id' => isset($payload['contentpulse_id']) ? (string) $payload['contentpulse_id'] : null,
            'synced_at' => current_time('mysql'),
        ]);
    }
}

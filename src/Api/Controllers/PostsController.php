<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Api\Controllers;

use ContentPulse\WordPress\Support\MediaSideloadService;
use ContentPulse\WordPress\Support\PostUpsertService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class PostsController
{
    private PostUpsertService $upsertService;

    private MediaSideloadService $mediaService;

    public function __construct()
    {
        $this->upsertService = new PostUpsertService;
        $this->mediaService = new MediaSideloadService;
    }

    /**
     * Create or update a WordPress post from ContentPulse payload.
     */
    public function upsert(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();

        if (empty($payload['title'])) {
            return new WP_Error(
                'missing_title',
                __('Title is required.', 'contentpulse-wp'),
                ['status' => 422],
            );
        }

        $featuredImageId = null;
        if (! empty($payload['featured_image'])) {
            $featuredImageId = $this->mediaService->sideload(
                $payload['featured_image'],
                $payload['title'] ?? 'ContentPulse Image',
            );
        }

        $result = $this->upsertService->upsert($payload, $featuredImageId);

        if (is_wp_error($result)) {
            return $result;
        }

        $statusCode = ($result['action'] === 'created') ? 201 : 200;

        return new WP_REST_Response($result, $statusCode);
    }

    /**
     * Retrieve a single post by its WordPress ID.
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post) {
            return new WP_Error(
                'not_found',
                __('Post not found.', 'contentpulse-wp'),
                ['status' => 404],
            );
        }

        return new WP_REST_Response([
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full') ?: null,
            'contentpulse_id' => get_post_meta($post->ID, '_contentpulse_id', true) ?: null,
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
        ], 200);
    }

    /**
     * Delete a post by its WordPress ID.
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post) {
            return new WP_Error(
                'not_found',
                __('Post not found.', 'contentpulse-wp'),
                ['status' => 404],
            );
        }

        $deleted = wp_delete_post($postId, true);

        if (! $deleted) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete post.', 'contentpulse-wp'),
                ['status' => 500],
            );
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $postId], 200);
    }
}

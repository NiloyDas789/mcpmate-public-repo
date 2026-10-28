<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\SiteContext\PostStructure;

/**
 * GET /post-structure?post_id= — type, status, template, builder, meta keys
 * and block tree of one post.
 *
 * Needs edit_posts for the route and edit_post on the post itself (drafts
 * and meta are editing data). Only PostStructure::allowedPostTypes() are read.
 */
final class PostStructureController extends RestController
{
    protected const CAPABILITIES = [
        'show' => 'edit_posts',
    ];

    public function show(Request $request): Response
    {
        $post = get_post((int) $request->get('post_id'));

        if (!$post instanceof \WP_Post) {
            return $this->error(__('Post not found.', 'mcpmate'), 404, 'mcpmate_post_not_found');
        }

        $denied = $this->authorize('edit_post', $post->ID);
        if ($denied) {
            return $denied;
        }

        if (!in_array($post->post_type, PostStructure::allowedPostTypes(), true)) {
            return $this->error(
                __('This post type is not available.', 'mcpmate'),
                400,
                'mcpmate_post_type_not_allowed',
                __('Only public post types, reusable blocks and block templates can be inspected.', 'mcpmate')
            );
        }

        return $this->ok(PostStructure::describe($post));
    }

    /**
     * REST `args` schema for show().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function showArgs(): array
    {
        return [
            'post_id' => [
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ],
        ];
    }
}

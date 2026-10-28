<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\SiteContext\PageFetcher;
use McpMate\Services\SiteContext\PageUrl;

/**
 * GET /page-html?url=|post_id= — the server-rendered HTML of a front-end page
 * on this site, fetched as an anonymous visitor.
 *
 * Only this site's host is fetched (PageUrl). The request goes out without
 * cookies or credentials, so it returns what the public sees.
 */
final class PageHtmlController extends RestController
{
    protected const CAPABILITIES = [
        'show' => 'edit_posts',
    ];

    /** PageUrl error → response code (all are 400s). */
    private const URL_ERRORS = [
        PageUrl::ERROR_INVALID      => 'mcpmate_invalid_url',
        PageUrl::ERROR_FOREIGN_HOST => 'mcpmate_foreign_host',
        PageUrl::ERROR_BLOCKED_PATH => 'mcpmate_path_not_allowed',
    ];

    public function show(Request $request): Response
    {
        if ($request->has('url') === $request->has('post_id')) {
            return $this->error(
                __('Pass either url or post_id.', 'mcpmate'),
                400,
                'mcpmate_invalid_target',
                __('Send exactly one of "url" (a URL or path on this site) or "post_id".', 'mcpmate')
            );
        }

        if ($request->has('post_id')) {
            $post = get_post((int) $request->get('post_id'));

            if (!$post instanceof \WP_Post) {
                return $this->error(__('Post not found.', 'mcpmate'), 404, 'mcpmate_post_not_found');
            }

            $denied = $this->authorize('read_post', $post->ID);
            if ($denied) {
                return $denied;
            }

            if (!is_post_publicly_viewable($post)) {
                return $this->error(
                    __('This post has no public page.', 'mcpmate'),
                    400,
                    'mcpmate_post_not_public',
                    __('Only published, public posts can be fetched as a visitor. Use /post-structure to inspect drafts.', 'mcpmate')
                );
            }

            $input = (string) get_permalink($post);
        } else {
            $input = (string) $request->get('url');
        }

        $resolved = PageUrl::resolve($input, home_url('/'));

        if ($resolved['error'] !== '') {
            return $this->urlError($resolved['error']);
        }

        $result = PageFetcher::fetch(
            $resolved['url'],
            (bool) $request->get('strip_scripts', true),
            (int) $request->get('max_bytes', PageFetcher::DEFAULT_MAX_BYTES)
        );

        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();

            return $this->error(
                $result->get_error_message(),
                (int) ($data['status'] ?? 502),
                (string) $result->get_error_code(),
                (string) ($data['hint'] ?? '')
            );
        }

        return $this->ok($result);
    }

    /**
     * REST `args` schema for show().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function showArgs(): array
    {
        return [
            'url'           => [
                'type'        => 'string',
                'maxLength'   => PageUrl::MAX_LENGTH,
                'description' => 'Absolute URL on this site, or a path starting with "/". Other hosts are rejected.',
            ],
            'post_id'       => [
                'type'    => 'integer',
                'minimum' => 1,
            ],
            'strip_scripts' => [
                'type'    => 'boolean',
                'default' => true,
            ],
            'max_bytes'     => [
                'type'    => 'integer',
                'minimum' => PageFetcher::MIN_BYTES,
                'maximum' => PageFetcher::MAX_BYTES,
                'default' => PageFetcher::DEFAULT_MAX_BYTES,
            ],
        ];
    }

    private function urlError(string $error): Response
    {
        $code = self::URL_ERRORS[$error] ?? 'mcpmate_invalid_url';

        if ($error === PageUrl::ERROR_FOREIGN_HOST) {
            return $this->error(
                __('Only pages on this site can be fetched.', 'mcpmate'),
                400,
                $code,
                /* translators: %s: the site's home URL. */
                sprintf(__('Use a URL under %s, or a path starting with "/".', 'mcpmate'), home_url('/'))
            );
        }

        if ($error === PageUrl::ERROR_BLOCKED_PATH) {
            return $this->error(
                __('That path is not a front-end page.', 'mcpmate'),
                400,
                $code,
                __('wp-admin, wp-json, wp-includes, wp-content, *.php files and rest_route URLs cannot be fetched.', 'mcpmate')
            );
        }

        return $this->error(
            __('The URL is not valid.', 'mcpmate'),
            400,
            $code,
            __('Use an http(s) URL on this site, or a path starting with "/", without spaces or credentials.', 'mcpmate')
        );
    }
}

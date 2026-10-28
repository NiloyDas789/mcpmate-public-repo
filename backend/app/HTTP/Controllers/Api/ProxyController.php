<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\CoreProxy\CoreProxy;
use McpMate\Services\CoreProxy\CoreProxySettings;

/**
 * POST /proxy {method, route, query, body}: one core REST route from the
 * CoreRoutes table, run as the current user (connection v2, §6.4).
 *
 * `edit_posts` is the floor; core's own capability and object checks run
 * inside. Off until a site admin turns on Tools → Core, Posts and pages. Drafts
 * only (rule 2), trash only (rule 3). An inner GET is a read: it needs only
 * `site:read` and writes no audit row; anything else writes one row,
 * `proxy:{METHOD}:{route}`, with the body cut to its keys and lengths.
 */
final class ProxyController extends RestController
{
    protected const CAPABILITIES = [
        'proxy' => 'edit_posts',
    ];

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function proxy(Request $request): Response
    {
        if (!CoreProxySettings::isEnabled()) {
            return $this->error(
                __('Core content access is turned off on this site.', 'mcpmate'),
                403,
                CoreProxy::DISABLED,
                __('A site admin can turn it on under McpMate → Tools → Core (Posts and pages).', 'mcpmate')
            );
        }

        $method = strtoupper((string) $request->get('method', ''));
        $route  = (string) $request->get('route', '');
        $query  = (array) $request->get('query', []);
        $body   = (array) $request->get('body', []);

        $this->auditAction('proxy:' . $method . ':' . $route);

        if (strlen((string) wp_json_encode($body)) > CoreProxy::MAX_BODY_BYTES) {
            return $this->error(__('The body is larger than 1 MiB.', 'mcpmate'), 400, 'mcpmate_proxy_body_too_large');
        }

        $result = CoreProxy::run($method, $route, $query, $body);

        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            $hint   = is_array($data) && isset($data['hint']) && is_string($data['hint']) ? $data['hint'] : '';

            return $this->error($result->get_error_message(), $status, (string) $result->get_error_code(), $hint);
        }

        $id = $result['match']['id'];
        if ($id === 0 && is_array($result['body']) && isset($result['body']['id']) && $result['match']['post_type'] !== '') {
            $id = (int) $result['body']['id'];
        }

        if ($id > 0 && $result['match']['post_type'] !== '') {
            $this->auditTarget('post', $id);
        }

        return $this->ok([
            'status'      => $result['status'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'body'        => $result['body'],
        ], $result['status'] === 201 ? 201 : 200);
    }

    /**
     * An inner GET only reads.
     */
    public static function readsOnly(string $method, Request $request): bool
    {
        return parent::readsOnly($method, $request) || strtoupper((string) $request->get('method', '')) === 'GET';
    }

    /**
     * Keys and lengths of the body, never its text.
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    protected static function auditArgs(array $args): array
    {
        $body = [];
        foreach ((array) ($args['body'] ?? []) as $key => $value) {
            $body[$key] = is_scalar($value) ? strlen((string) $value) : strlen((string) wp_json_encode($value));
        }

        return [
            'method' => $args['method'] ?? '',
            'route'  => $args['route'] ?? '',
            'query'  => array_keys((array) ($args['query'] ?? [])),
            'body'   => $body,
        ];
    }

    // ──────────────────────────────────────────────
    //  Route args
    // ──────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function proxyArgs(): array
    {
        return [
            'method' => [
                'type'     => 'string',
                'required' => true,
                'enum'     => self::METHODS,
            ],
            'route'  => [
                'type'      => 'string',
                'required'  => true,
                'maxLength' => 200,
            ],
            'query'  => [
                'type'    => 'object',
                'default' => [],
            ],
            'body'   => [
                'type'    => 'object',
                'default' => [],
            ],
        ];
    }
}

<?php

namespace MCPMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Controllers\Api\RestController;

/**
 * The plugin routes the tools call, in-process (docs/mcp-in-plugin.md
 * §7.1, item 4; the port of the cloud's src/sites/client.ts). Each method
 * is one route with fixed path segments; only ids and table names vary.
 * MCPMate Pro's tools reach their routes through call() and pendingOr()
 * (its ProClient), the same way.
 *
 * Requests go through McpContext::dispatch() (rest_do_request()), so each
 * route keeps every check it has over HTTP: its args schema, the router's
 * tool and scope checks, its capability, the audit row and change history.
 * Queries are sent as strings and bodies as JSON, exactly as the cloud sent
 * them. The envelope is unwrapped; an error becomes a SiteError, and an
 * answer that does not have the expected shape an `invalid_response`.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON tool output or admin-ajax JSON, never HTML.
final class InternalClient
{
    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function siteInfo(): array
    {
        return $this->call('GET', ['site-info'], [], null, SiteSchemas::siteInfo(), 'site-info');
    }

    /**
     * @param array{url?: string, post_id?: int} $target
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function pageHtml(array $target, bool $stripScripts, int $maxBytes): array
    {
        return $this->call('GET', ['page-html'], $target + ['strip_scripts' => $stripScripts, 'max_bytes' => $maxBytes], null, SiteSchemas::pageHtml(), 'page-html');
    }

    /**
     * @param array<string, mixed> $filters tool, result, from, to, page, per_page.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function auditLog(array $filters): array
    {
        return $this->call('GET', ['audit-log'], $filters, null, SiteSchemas::auditLog(), 'audit-log');
    }

    /**
     * POST /cli: the result, or a 202 waiting for approval (`pending` says which).
     *
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function cli(string $command, ?bool $dryRun): array
    {
        return $this->pendingOr('POST', ['cli'], self::defined(['command' => $command, 'dry_run' => $dryRun]), SiteSchemas::cliPending(), SiteSchemas::cliRan(), 'cli');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function dbTables(): array
    {
        return $this->call('GET', ['db', 'tables'], [], null, SiteSchemas::dbTables(), 'db/tables');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function dbSchema(string $table): array
    {
        return $this->call('GET', ['db', 'tables', $table, 'schema'], [], null, SiteSchemas::dbSchema(), 'db schema');
    }

    /**
     * @param array<string, mixed> $query columns, where, order_by, order, limit, offset.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function dbRows(string $table, array $query): array
    {
        return $this->call('GET', ['db', 'tables', $table, 'rows'], $query, null, SiteSchemas::dbRows(), 'db rows');
    }

    /**
     * @param array<string, mixed> $filters search, category, page, per_page.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function abilities(array $filters): array
    {
        return $this->call('GET', ['abilities'], $filters, null, SiteSchemas::abilities(), 'abilities');
    }

    /**
     * @param array{name: string, input?: mixed} $body
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function runAbility(array $body): array
    {
        return $this->pendingOr('POST', ['abilities', 'run'], $body, SiteSchemas::abilityPending(), SiteSchemas::abilityRan(), 'abilities/run');
    }

    /**
     * @param array<string, mixed> $filters namespace, search, page, per_page.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function blockTypes(array $filters): array
    {
        return $this->call('GET', ['blocks', 'types'], $filters, null, SiteSchemas::blockTypes(), 'blocks/types');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function validateBlocks(string $content): array
    {
        return $this->call('POST', ['blocks', 'validate'], [], ['content' => $content], SiteSchemas::blockValidation(), 'blocks/validate');
    }

    // ──────────────────────────────────────────────
    //  Dispatch
    // ──────────────────────────────────────────────

    /**
     * A route that answers 202 with an approval request, or 200 with its result.
     *
     * @param array<string, mixed> $body
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function pendingOr(string $method, array $segments, array $body, callable $pending, callable $ran, string $name): array
    {
        $reply  = $this->call($method, $segments, [], $body, Shape::unknown(), $name);
        $parsed = $this->shape($reply['status'] === 202 ? $pending : $ran, $reply['data'], $name, $reply['status']);

        $reply['data'] = ['pending' => $reply['status'] === 202] + $parsed;

        return $reply;
    }

    /**
     * @param string[]                  $segments
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     * @return array{data: mixed, status: int, truncated: bool}
     * @throws SiteError
     */
    public function call(string $method, array $segments, array $query, ?array $body, callable $schema, string $name): array
    {
        $request = new \WP_REST_Request($method, '/' . RestController::NAMESPACE . '/' . implode('/', $segments));
        $request->set_query_params(self::queryOf($query));

        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(Js::stringify(Js::fromEntries(array_map(null, array_keys($body), array_values($body)))));
        }

        $response = McpContext::dispatch($request);
        $status   = $response->get_status();
        $envelope = $response->get_data();

        if (!is_array($envelope) || !array_key_exists('ok', $envelope)) {
            // Not enveloped: no route matched (rest_no_route) or core refused before it.
            $error = is_array($envelope) ? $envelope : [];

            throw new SiteError(
                is_string($error['code'] ?? null) ? $error['code'] : 'invalid_response',
                is_string($error['message'] ?? null) ? $error['message'] : 'The site’s ' . $name . ' answer is not in the expected format.',
                '',
                $status
            );
        }

        if ($envelope['ok'] !== true) {
            $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];

            throw new SiteError(
                (string) ($error['code'] ?? 'unknown_error'),
                (string) ($error['message'] ?? ''),
                is_string($error['hint'] ?? null) ? $error['hint'] : '',
                $status
            );
        }

        return [
            'data'      => $this->shape($schema, Js::fromPhp($envelope['data'] ?? null), $name, $status),
            'status'    => $status,
            'truncated' => ($envelope['truncated'] ?? false) === true,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function shape(callable $schema, $value, string $name, int $status)
    {
        try {
            return Shape::parse($schema, $value);
        } catch (ShapeException $e) {
            throw new SiteError('invalid_response', 'The site’s ' . $name . ' answer is not in the expected format.', 'Update the MCPMate plugin on the site.', $status);
        }
    }

    /**
     * Query values as the URL carried them: strings, `key[]` lists and
     * `key[name]` objects; null (undefined) left out.
     *
     * @param array<array-key, mixed> $query
     * @return array<array-key, mixed>
     */
    private static function queryOf(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($value instanceof \stdClass) {
                $value = get_object_vars($value);
            }

            if (is_array($value)) {
                $out[$key] = self::queryOf($value);
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } else {
                $out[$key] = is_string($value) ? $value : Js::number($value);
            }
        }

        return $out;
    }

    /**
     * $values without the keys that are null (undefined in the cloud's calls).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function defined(array $values): array
    {
        return array_filter($values, static fn ($value): bool => $value !== null);
    }
}
// phpcs:enable

<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Controllers\Api\RestController;

/**
 * The plugin routes the tools call, in-process (docs/mcp-in-plugin.md
 * §7.1, item 4; the port of the cloud's src/sites/client.ts). Each method
 * is one route with fixed path segments; only ids and table names vary.
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
     * @param array<string, mixed> $upload url | data, filename, title, alt, caption, post_id, featured, insert.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function uploadMedia(array $upload): array
    {
        return $this->call('POST', ['media'], [], self::defined($upload), SiteSchemas::media(), 'media');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function createThemeDraft(): array
    {
        return $this->call('POST', ['theme-draft'], [], null, SiteSchemas::themeDraftCreated(), 'theme-draft');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function themeFiles(string $draftId, string $path, bool $recursive): array
    {
        return $this->call('GET', ['theme-draft', $draftId, 'files'], ['path' => $path, 'recursive' => $recursive], null, SiteSchemas::themeFiles(), 'theme-draft files');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function readThemeFile(string $draftId, string $path, int $line): array
    {
        return $this->call('GET', ['theme-draft', $draftId, 'file'], ['path' => $path, 'line' => $line], null, SiteSchemas::themeFile(), 'theme-draft file');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function writeThemeFile(string $draftId, string $path, string $content): array
    {
        return $this->call('PUT', ['theme-draft', $draftId, 'file'], [], ['path' => $path, 'content' => $content], SiteSchemas::themeFileSaved(), 'theme-draft file');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function editThemeFile(string $draftId, string $path, string $find, string $replace): array
    {
        $body = ['path' => $path, 'find' => $find, 'replace' => $replace];

        return $this->call('POST', ['theme-draft', $draftId, 'file', 'replace'], [], $body, SiteSchemas::themeFileSaved(), 'theme-draft file replace');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function themePreview(string $draftId, string $path, int $expiresIn): array
    {
        return $this->call('POST', ['theme-draft', $draftId, 'preview'], [], ['path' => $path, 'expires_in' => $expiresIn], SiteSchemas::themePreview(), 'theme-draft preview');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function publishThemeDraft(string $draftId, bool $dryRun): array
    {
        return $this->pendingOr('POST', ['theme-draft', 'publish'], ['draft_id' => $draftId, 'dry_run' => $dryRun], SiteSchemas::themePending(), SiteSchemas::themePlan(), 'theme-draft publish');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function rollbackTheme(?string $backupId, bool $dryRun): array
    {
        $body = self::defined(['backup_id' => $backupId, 'dry_run' => $dryRun]);

        return $this->pendingOr('POST', ['theme-draft', 'rollback'], $body, SiteSchemas::themePending(), SiteSchemas::themePlan(), 'theme-draft rollback');
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

    /**
     * @param array<string, mixed> $filters search, category, page, per_page.
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function elementorWidgets(array $filters): array
    {
        return $this->call('GET', ['elementor', 'widgets'], $filters, null, SiteSchemas::elementorWidgets(), 'elementor/widgets');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function elementorWidget(string $name, ?string $tab): array
    {
        return $this->call('GET', ['elementor', 'widgets'], ['name' => $name, 'tab' => $tab], null, SiteSchemas::elementorWidget(), 'elementor/widgets');
    }

    /**
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function elementorPage(int $postId, ?string $elementId): array
    {
        return $this->call('GET', ['elementor', (string) $postId], ['element_id' => $elementId], null, SiteSchemas::elementorPage(), 'elementor page');
    }

    /**
     * POST /elementor/{post_id} (with $postId) or POST /elementor.
     *
     * @param array<string, mixed> $body
     * @return array{data: mixed, status: int, truncated: bool}
     */
    public function elementorWrite(?int $postId, array $body, bool $dryRun): array
    {
        $segments = $postId === null ? ['elementor'] : ['elementor', (string) $postId];

        return $this->call('POST', $segments, [], $body, $dryRun ? SiteSchemas::elementorDryRun() : SiteSchemas::elementorSaved(), 'elementor save');
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
    private function pendingOr(string $method, array $segments, array $body, callable $pending, callable $ran, string $name): array
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
    private function call(string $method, array $segments, array $query, ?array $body, callable $schema, string $name): array
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
            throw new SiteError('invalid_response', 'The site’s ' . $name . ' answer is not in the expected format.', 'Update the McpMate plugin on the site.', $status);
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

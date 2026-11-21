<?php

namespace MCPMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Services\Mcp\Shape as S;

/**
 * The shapes of the route answers the tools read (the cloud's
 * src/sites/client.ts schemas, ported key for key). Each answer is site
 * content: bounded, unknown fields dropped, long strings cut. Parts whose
 * shape belongs to the plugin (command results, rows, previews) stay
 * unknown and are compacted by the tools.
 */
final class SiteSchemas
{
    public const AUDIT_RESULTS = ['ok', 'error', 'pending_approval'];

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'expired', 'executed'];

    public const ABILITY_RISKS = ['read', 'write', 'destructive'];

    public const ABILITY_NAME = '/^[a-z0-9-]+(\/[a-z0-9-]+)+$/D';

    public const BLOCK_NAMESPACE = '/^[a-z][a-z0-9_-]*$/D';

    private const SHA256 = '/^[a-f0-9]{64}$/D';

    // ──────────────────────────────────────────────
    //  Site context
    // ──────────────────────────────────────────────

    private static function theme(): array
    {
        return [
            'name'             => S::text(200),
            'stylesheet'       => S::text(200),
            'version'          => S::text(50),
            'update_available' => S::bool(),
            'new_version'      => S::nullable(S::text(50)),
        ];
    }

    private static function plugin(): callable
    {
        return S::object([
            'file'             => S::text(300),
            'slug'             => S::text(200),
            'name'             => S::text(200),
            'version'          => S::text(50),
            'update_available' => S::bool(),
            'new_version'      => S::nullable(S::text(50)),
        ]);
    }

    private static function detected(): callable
    {
        return S::object([
            'id'      => S::text(100),
            'name'    => S::text(200),
            'version' => S::nullish(S::text(50)),
            'sources' => S::optional(S::lst(S::text(100), 20)),
        ]);
    }

    public static function siteInfo(): callable
    {
        return S::object([
            'wp_version'    => S::text(50),
            'php_version'   => S::text(50),
            'database'      => S::object(['type' => S::text(50), 'version' => S::text(50)]),
            'multisite'     => S::bool(),
            'home_url'      => S::text(2048),
            'site_url'      => S::text(2048),
            'locale'        => S::text(20),
            'theme'         => S::object(self::theme() + ['block_theme' => S::bool(), 'parent' => S::nullable(S::object(self::theme()))]),
            'plugins'       => S::object([
                'active'             => S::lst(self::plugin(), 5000),
                'inactive'           => S::lst(self::plugin(), 5000),
                'must_use'           => S::lst(S::object(['file' => S::text(300), 'name' => S::text(200), 'version' => S::text(50)]), 5000),
                'updates_checked_at' => S::nullable(S::text(40)),
            ]),
            'page_builders' => S::lst(self::detected(), 100),
            'seo_plugins'   => S::lst(self::detected(), 100),
            'woocommerce'   => S::object(['installed' => S::bool(), 'active' => S::bool(), 'version' => S::nullable(S::text(50))]),
            'permalinks'    => S::object(['structure' => S::text(300), 'plain' => S::bool()]),
            'site_health'   => S::object([
                'critical'    => S::nullable(S::count()),
                'recommended' => S::nullable(S::count()),
                'good'        => S::nullable(S::count()),
                'source'      => S::text(50),
            ]),
        ]);
    }

    public static function pageHtml(): callable
    {
        return S::object([
            'url'              => S::text(2048),
            'final_url'        => S::text(2048),
            'status_code'      => S::int(),
            'content_type'     => S::text(200),
            'redirects'        => S::lst(S::object(['url' => S::text(2048), 'status_code' => S::int(), 'location' => S::text(2048)]), 10),
            'html'             => S::string(400000),
            'bytes'            => S::count(),
            'html_truncated'   => S::bool(),
            'scripts_stripped' => S::count(),
            'nonces_redacted'  => S::count(),
        ]);
    }

    public static function auditLog(): callable
    {
        return S::object([
            'items'       => S::lst(S::object([
                'id'         => S::int(),
                'created_at' => S::text(40),
                'user_login' => S::nullable(S::text(60)),
                'request_id' => S::text(100),
                'tool'       => S::text(100),
                'action'     => S::text(100),
                'target'     => S::text(191),
                'args'       => S::unknown(),
                'result'     => S::text(40),
                'message'    => S::text(1000),
            ]), 100),
            'total'       => S::count(),
            'page'        => S::positive(),
            'per_page'    => S::positive(),
            'total_pages' => S::count(),
        ]);
    }

    /**
     * The approval as check_approval reads it (Approvals::forGrant()).
     */
    public static function approval(): callable
    {
        return S::object([
            'id'           => S::positive(),
            'status'       => S::enum(self::APPROVAL_STATUSES),
            'tool'         => S::text(100),
            'action'       => S::text(100),
            'target'       => S::text(191),
            'created_at'   => S::nullable(S::text(40)),
            'expires_at'   => S::nullable(S::text(40)),
            'decided_at'   => S::nullable(S::text(40)),
            'executed_at'  => S::nullable(S::text(40)),
            'result'       => S::unknown(),
            'approval_url' => S::optional(S::text(2048)),
        ]);
    }

    // ──────────────────────────────────────────────
    //  Commands
    // ──────────────────────────────────────────────

    private static function cliSummary(): array
    {
        return [
            'command' => S::text(100),
            'risk'    => S::text(20),
            'dry_run' => S::bool(),
            'args'    => S::unknown(),
            'flags'   => S::unknown(),
        ];
    }

    public static function cliRan(): callable
    {
        return S::object(self::cliSummary() + ['result' => S::unknown()]);
    }

    private static function pending(): array
    {
        return [
            'pending_approval' => S::literal(true),
            'approval_id'      => S::positive(),
            'approval_token'   => S::regex(self::SHA256),
            'approval_url'     => S::text(2048),
            'expires_at'       => S::text(40),
        ];
    }

    public static function cliPending(): callable
    {
        return S::object(self::cliSummary() + self::pending() + ['preview' => S::unknown()]);
    }

    // ──────────────────────────────────────────────
    //  Database
    // ──────────────────────────────────────────────

    private static function table(): callable
    {
        return S::object([
            'name'          => S::text(64),
            'engine'        => S::text(40),
            'rows_estimate' => S::count(),
            'data_bytes'    => S::count(),
            'index_bytes'   => S::count(),
        ]);
    }

    public static function dbTables(): callable
    {
        return S::object(['tables' => S::lst(self::table(), 10000)]);
    }

    public static function dbSchema(): callable
    {
        $default = S::transform(
            S::nullable(S::union([S::string(), self::number()])),
            static fn ($value): ?string => $value === null ? null : Js::slice(is_string($value) ? $value : Js::number($value), 200)
        );

        return S::object([
            'table'       => self::table(),
            'primary_key' => S::lst(S::text(64), 64),
            'columns'     => S::lst(S::object([
                'name'      => S::text(64),
                'type'      => S::text(200),
                'nullable'  => S::bool(),
                'key'       => S::text(10),
                'default'   => $default,
                'extra'     => S::text(200),
                'protected' => S::bool(),
            ]), 1000),
            'indexes'     => S::lst(S::object(['name' => S::text(64), 'unique' => S::bool(), 'columns' => S::lst(S::text(64), 64)]), 200),
            'masking'     => S::nullable(S::object(['key_column' => S::text(64), 'value_column' => S::text(64)])),
        ]);
    }

    public static function dbRows(): callable
    {
        return S::object([
            'table'             => S::text(64),
            'columns'           => S::lst(S::text(64), 1000),
            'rows'              => S::lst(S::record(S::unknown()), 1000),
            'count'             => S::count(),
            'limit'             => S::int(),
            'offset'            => S::count(),
            'has_more'          => S::bool(),
            'next_offset'       => S::nullable(S::count()),
            'protected_columns' => S::lst(S::text(64), 1000),
            'masked_rows'       => S::count(),
            'clipped_cells'     => S::count(),
        ]);
    }

    // ──────────────────────────────────────────────
    //  Abilities and blocks
    // ──────────────────────────────────────────────

    public static function abilities(): callable
    {
        $annotation = S::nullable(S::bool());

        return S::object([
            'abilities'   => S::lst(S::object([
                'name'          => S::text(200),
                'label'         => S::text(200),
                'description'   => S::text(2000),
                'category'      => S::text(100),
                'risk'          => S::enum(self::ABILITY_RISKS),
                'enabled'       => S::bool(),
                'annotations'   => S::object(['readonly' => $annotation, 'destructive' => $annotation, 'idempotent' => $annotation]),
                'input_schema'  => S::unknown(),
                'output_schema' => S::unknown(),
            ]), 100),
            'total'       => S::count(),
            'page'        => S::positive(),
            'per_page'    => S::positive(),
            'total_pages' => S::count(),
        ]);
    }

    public static function abilityRan(): callable
    {
        return S::object(['name' => S::text(200), 'risk' => S::enum(self::ABILITY_RISKS), 'result' => S::unknown()]);
    }

    public static function abilityPending(): callable
    {
        return S::object(['name' => S::text(200), 'risk' => S::enum(self::ABILITY_RISKS)] + self::pending() + ['preview' => S::unknown()]);
    }

    public static function blockTypes(): callable
    {
        $names = static fn (int $max): callable => S::nullable(S::lst(S::text(200), $max));

        return S::object([
            'block_types' => S::lst(S::object([
                'name'           => S::text(200),
                'title'          => S::text(200),
                'description'    => S::text(300),
                'category'       => S::nullable(S::text(100)),
                'parent'         => $names(50),
                'ancestor'       => $names(50),
                'allowed_blocks' => $names(200),
                'dynamic'        => S::bool(),
                'attributes'     => S::record(S::object([
                    'type'      => S::optional(S::union([S::text(50), S::lst(S::text(50), 10)])),
                    'enum'      => S::optional(S::lst(S::unknown(), 50)),
                    'default'   => S::unknown(),
                    'source'    => S::optional(S::text(50)),
                    'selector'  => S::optional(S::text(300)),
                    'attribute' => S::optional(S::text(100)),
                ]), 200, 100),
            ]), 100),
            'total'       => S::count(),
            'page'        => S::positive(),
            'per_page'    => S::positive(),
            'total_pages' => S::count(),
            'namespaces'  => S::record(S::count(), 500, 100),
        ]);
    }

    private static function blockIssue(): callable
    {
        return S::object([
            'code'      => S::text(60),
            'message'   => S::text(500),
            'path'      => S::text(500),
            'block'     => S::nullable(S::text(200)),
            'attribute' => S::nullable(S::text(100)),
            'line'      => S::nullable(S::positive()),
            'hint'      => S::text(1000),
        ]);
    }

    public static function blockValidation(): callable
    {
        return S::object([
            'valid'            => S::bool(),
            'blocks'           => S::count(),
            'error_count'      => S::count(),
            'warning_count'    => S::count(),
            'errors'           => S::lst(self::blockIssue(), 100),
            'warnings'         => S::lst(self::blockIssue(), 100),
            'issues_truncated' => S::bool(),
        ]);
    }

    private static function number(): callable
    {
        return static function ($value) {
            if (!is_int($value) && !is_float($value)) {
                throw new ShapeException('expected number');
            }

            return $value;
        };
    }
}

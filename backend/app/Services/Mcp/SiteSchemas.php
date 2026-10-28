<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Shape as S;

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

    public const THEME_DRAFT_ID = '/^[a-f0-9]{8}$/D';

    public const THEME_BACKUP_ID = '/^[a-f0-9]{12}$/D';

    public const ABILITY_NAME = '/^[a-z0-9-]+(\/[a-z0-9-]+)+$/D';

    public const BLOCK_NAMESPACE = '/^[a-z][a-z0-9_-]*$/D';

    public const ELEMENTOR_ELEMENT_ID = '/^[A-Za-z0-9]{1,32}$/D';

    public const ELEMENTOR_WIDGET_NAME = '/^[A-Za-z0-9_-]{1,100}$/D';

    public const ELEMENTOR_VERSION = '/^[0-9a-f]{16}$/D';

    public const ELEMENTOR_TABS = ['content', 'style', 'advanced', 'layout'];

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

    // ──────────────────────────────────────────────
    //  Commands and approvals
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
    //  Media
    // ──────────────────────────────────────────────

    public static function media(): callable
    {
        $dimension = S::nullable(S::count());

        return S::object([
            'id'            => S::positive(),
            'url'           => S::text(2048),
            'title'         => S::text(200),
            'alt'           => S::text(500),
            'caption'       => S::text(1000),
            'mime_type'     => S::text(100),
            'filesize'      => S::nullable(S::count()),
            'width'         => $dimension,
            'height'        => $dimension,
            // PHP sends an empty array, not an object, when there are no sizes.
            'sizes'         => S::union([
                S::record(S::object(['url' => S::text(2048), 'width' => S::count(), 'height' => S::count(), 'mime_type' => S::text(100)]), null, 100),
                S::transform(S::lst(S::literal("\0never"), 0), static fn (): \stdClass => new \stdClass()),
            ]),
            'edit_url'      => S::text(2048),
            'source'        => S::enum(['url', 'data']),
            'svg_sanitized' => S::nullable(S::object(['removed_nodes' => S::count(), 'removed_attributes' => S::count()])),
            'post'          => S::nullable(S::object([
                'id'       => S::positive(),
                'status'   => S::text(20),
                'featured' => S::bool(),
                'inserted' => S::bool(),
                'edit_url' => S::text(2048),
                'error'    => S::text(500),
            ])),
        ]);
    }

    // ──────────────────────────────────────────────
    //  Theme drafts
    // ──────────────────────────────────────────────

    private static function themeDraftShape(): array
    {
        return [
            'id'           => S::regex(self::THEME_DRAFT_ID),
            'slug'         => S::text(200),
            'status'       => S::enum(['draft', 'published']),
            'live'         => S::bool(),
            'exists'       => S::bool(),
            'folder'       => S::text(300),
            'source'       => S::object(['stylesheet' => S::text(200), 'name' => S::text(200)]),
            'parent'       => S::nullable(S::text(200)),
            'created_at'   => S::nullable(S::text(40)),
            'published_at' => S::nullable(S::text(40)),
            'backup_id'    => S::nullable(S::text(20)),
        ];
    }

    public static function themeDraftCreated(): callable
    {
        return S::object(self::themeDraftShape() + ['copied' => S::object(['files' => S::count(), 'bytes' => S::count(), 'skipped' => S::count()])]);
    }

    public static function themeFiles(): callable
    {
        return S::object([
            'path'      => S::text(300),
            'recursive' => S::bool(),
            'entries'   => S::lst(S::object([
                'path'     => S::text(300),
                'type'     => S::enum(['file', 'dir']),
                'size'     => S::nullable(S::count()),
                'modified' => S::text(40),
                'editable' => S::bool(),
            ]), 2000),
            'count'     => S::count(),
            'truncated' => S::bool(),
        ]);
    }

    public static function themeFile(): callable
    {
        return S::object([
            'path'        => S::text(300),
            'size'        => S::count(),
            'sha256'      => S::regex(self::SHA256),
            'total_lines' => S::count(),
            'start_line'  => S::positive(),
            'end_line'    => S::count(),
            'content'     => S::string(70000),
            'clipped'     => S::bool(),
            'has_more'    => S::bool(),
            'next_line'   => S::nullable(S::positive()),
        ]);
    }

    public static function themeFileSaved(): callable
    {
        return S::object([
            'path'    => S::text(300),
            'size'    => S::count(),
            'sha256'  => S::regex(self::SHA256),
            'created' => S::optional(S::bool()),
            'line'    => S::optional(S::positive()),
        ]);
    }

    public static function themePreview(): callable
    {
        return S::object([
            'url'        => S::text(2048),
            'expires_at' => S::text(40),
            'expires_in' => S::count(),
            'draft'      => S::object(self::themeDraftShape()),
        ]);
    }

    /**
     * The fields of a publish or rollback plan; all optional in a pending preview.
     */
    private static function themePlanFields(bool $partial): array
    {
        $optional = static fn (callable $schema): callable => S::optional($schema);
        $required = static fn (callable $schema): callable => $partial ? S::optional($schema) : $schema;

        return [
            'operation'      => $required(S::enum(['publish', 'rollback'])),
            'steps'          => $required(S::lst(S::text(500), 10)),
            'changes'        => $optional(S::object([
                'added'    => S::lst(S::text(300), 200),
                'modified' => S::lst(S::text(300), 200),
                'removed'  => S::lst(S::text(300), 200),
                'counts'   => S::record(S::count(), null, 20),
            ])),
            'draft'          => $optional(S::object(['id' => S::text(20), 'slug' => S::text(200)])),
            'live_theme'     => $optional(S::object(['stylesheet' => S::text(200), 'name' => S::text(200)])),
            'source_changed' => $optional(S::bool()),
            'backup'         => $optional(S::object(['id' => S::text(20), 'stylesheet' => S::text(200), 'file' => S::text(300), 'created_at' => S::nullable(S::text(40))])),
            'restore'        => $optional(S::text(200)),
            'folder'         => $optional(S::text(20)),
            'moved_aside_to' => $optional(S::nullable(S::text(300))),
        ];
    }

    public static function themePlan(): callable
    {
        $fields = self::themePlanFields(false);

        return S::object(['status' => S::literal('dry_run'), 'dry_run' => S::literal(true), 'operation' => $fields['operation'], 'steps' => $fields['steps']] + $fields);
    }

    public static function themePending(): callable
    {
        return S::object([
            'status'           => S::literal('pending_approval'),
            'pending_approval' => S::literal(true),
            'operation'        => S::enum(['publish', 'rollback']),
            'approval_id'      => S::positive(),
            'approval_token'   => S::regex(self::SHA256),
            'approval_url'     => S::text(2048),
            'expires_at'       => S::text(40),
            'preview'          => S::object(self::themePlanFields(true)),
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

    // ──────────────────────────────────────────────
    //  Elementor
    // ──────────────────────────────────────────────

    private static function controlBase(): array
    {
        return [
            'key'            => S::text(200),
            'type'           => S::text(60),
            'tab'            => S::text(40),
            'label'          => S::text(200),
            'responsive'     => S::optional(S::bool()),
            'default'        => S::unknown(),
            'options'        => S::optional(S::lst(S::text(200), 60)),
            'size_units'     => S::optional(S::lst(S::text(20), 20)),
            'return_value'   => S::optional(S::text(100)),
            'multiple'       => S::optional(S::bool()),
            'group'          => S::optional(S::text(60)),
            'global_default' => S::optional(S::text(200)),
            'condition'      => S::unknown(),
            'writable'       => S::optional(S::bool()),
        ];
    }

    private static function elementorItem(): array
    {
        return [
            'name'       => S::text(100),
            'kind'       => S::enum(['element', 'widget']),
            'title'      => S::text(200),
            'categories' => S::lst(S::text(100), 20),
            'keywords'   => S::lst(S::text(100), 50),
        ];
    }

    public static function elementorWidgets(): callable
    {
        return S::object([
            'widgets'     => S::lst(S::object(self::elementorItem() + ['content_controls' => S::record(S::text(60), 100, 100)]), 100),
            'total'       => S::count(),
            'page'        => S::positive(),
            'per_page'    => S::positive(),
            'total_pages' => S::count(),
            'categories'  => S::record(S::count(), 200, 100),
            'containers'  => S::bool(),
        ]);
    }

    public static function elementorWidget(): callable
    {
        $control = S::object(self::controlBase() + ['fields' => S::optional(S::lst(S::object(self::controlBase()), 100))]);

        $widget  = S::object(self::elementorItem() + [
            'accepts_children' => S::bool(),
            'breakpoints'      => S::lst(S::text(30), 10),
            'controls'         => S::lst($control, 1000),
        ]);

        return S::transform(S::object(['widget' => $widget]), static fn (array $data): array => $data['widget']);
    }

    private static function elementorSummary(): array
    {
        return [
            'post_id'           => S::positive(),
            'title'             => S::text(300),
            'post_type'         => S::text(50),
            'status'            => S::text(30),
            'editable'          => S::bool(),
            'template'          => S::text(200),
            'version'           => S::regex(self::ELEMENTOR_VERSION),
            'element_count'     => S::count(),
            'elementor_version' => S::text(30),
        ];
    }

    public static function elementorPage(): callable
    {
        return S::object(self::elementorSummary() + [
            'page_settings'      => S::record(S::unknown(), 50, 100),
            'elements'           => S::lst(S::unknown(), 2000),
            'truncated_settings' => S::lst(S::text(300), 5000),
        ]);
    }

    private static function elementorIssue(): callable
    {
        return S::object([
            'code'       => S::text(60),
            'message'    => S::text(500),
            'path'       => S::text(500),
            'element_id' => S::text(40),
            'setting'    => S::text(300),
            'hint'       => S::text(1000),
        ]);
    }

    public static function elementorSaved(): callable
    {
        return S::object(self::elementorSummary() + [
            'saved'         => S::literal(true),
            'css'           => S::object(['status' => S::text(30)]),
            'warning_count' => S::count(),
            'warnings'      => S::lst(self::elementorIssue(), 100),
            'copied_from'   => S::optional(S::positive()),
        ]);
    }

    public static function elementorDryRun(): callable
    {
        return S::object([
            'dry_run'       => S::literal(true),
            'valid'         => S::bool(),
            'element_count' => S::count(),
            'error_count'   => S::count(),
            'warning_count' => S::count(),
            'errors'        => S::lst(self::elementorIssue(), 100),
            'warnings'      => S::lst(self::elementorIssue(), 100),
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

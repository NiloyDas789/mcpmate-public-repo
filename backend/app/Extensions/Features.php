<?php

namespace MCPMate\Extensions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one extension point of the plugin's allowlists (ToolCatalog, the MCP
 * ToolRegistry, Policy, Approvals' executors): the features MCPMate Pro
 * registers. It is not a filter. A feature is accepted only when its class
 * is defined in a file inside the MCPMate Pro plugin's folder, and only
 * until `plugins_loaded` is over (seal()), so no other plugin can add a
 * route, a tool or an executor.
 *
 * Pure apart from the file check: no WordPress calls.
 */
final class Features
{
    /** MCPMate Pro's folder name, next to this plugin (and `pro/` inside it while developing). */
    public const PRO_FOLDER = 'mcpmate-pro';

    /** @var array<string, Feature> */
    private static array $features = [];

    private static bool $sealed = false;

    /**
     * Register one of MCPMate Pro's features.
     *
     * @return bool Whether it was accepted.
     */
    public static function register(Feature $feature): bool
    {
        if (self::$sealed || isset(self::$features[$feature->id()]) || !self::isTrusted($feature)) {
            return false;
        }

        self::$features[$feature->id()] = $feature;

        return true;
    }

    /**
     * Close registration (on `plugins_loaded`, after MCPMate Pro booted).
     */
    public static function seal(): void
    {
        self::$sealed = true;
    }

    /**
     * @return Feature[] In registration order.
     */
    public static function all(): array
    {
        return array_values(self::$features);
    }

    /**
     * Whether any feature is registered (MCPMate Pro active with a valid license).
     */
    public static function any(): bool
    {
        return self::$features !== [];
    }

    /**
     * Forget every feature and open registration again. Tests only.
     */
    public static function reset(): void
    {
        self::$features = [];
        self::$sealed   = false;
    }

    // ──────────────────────────────────────────────
    //  Merged views
    // ──────────────────────────────────────────────

    /**
     * Every feature's tools(), merged in registration order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->tools());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->groups());
    }

    /**
     * @return array<string, string>
     */
    public static function routes(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->routes());
    }

    /**
     * @return array<string, class-string>
     */
    public static function handlers(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->handlers());
    }

    /**
     * @return array<string, string>
     */
    public static function policy(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->policy());
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function executors(): array
    {
        return self::merge(static fn (Feature $feature): array => $feature->executors());
    }

    /**
     * What the audit row of an approved $tool request keeps: the owning
     * feature's choice (the one whose executors() hold $tool), else $args.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function approvalAuditArgs(string $tool, array $args): array
    {
        foreach (self::$features as $feature) {
            if (isset($feature->executors()[$tool])) {
                return $feature->approvalAuditArgs($tool, $args) ?? $args;
            }
        }

        return $args;
    }

    /**
     * The feature whose tools() hold $tool, or null.
     */
    public static function ownerOfTool(string $tool): ?Feature
    {
        foreach (self::$features as $feature) {
            if (isset($feature->tools()[$tool]) || isset($feature->policy()[$tool])) {
                return $feature;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param callable(Feature): array<string, mixed> $read
     * @return array<string, mixed>
     */
    private static function merge(callable $read): array
    {
        $merged = [];

        foreach (self::$features as $feature) {
            // A feature never replaces an earlier entry (free or another feature's).
            $merged += $read($feature);
        }

        return $merged;
    }

    /**
     * Whether the feature's class file is inside MCPMate Pro's folder.
     */
    private static function isTrusted(Feature $feature): bool
    {
        $file = (new \ReflectionClass($feature))->getFileName();
        $file = is_string($file) ? realpath($file) : false;

        if ($file === false) {
            return false;
        }

        foreach (self::trustedDirectories() as $dir) {
            if (str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * MCPMate Pro next to this plugin, and pro/ inside it (the development checkout).
     *
     * @return string[]
     */
    private static function trustedDirectories(): array
    {
        $root = dirname(__DIR__, 3);
        $dirs = [];

        foreach ([dirname($root) . DIRECTORY_SEPARATOR . self::PRO_FOLDER, $root . DIRECTORY_SEPARATOR . 'pro'] as $dir) {
            $real = realpath($dir);
            if ($real !== false && is_dir($real)) {
                $dirs[] = $real;
            }
        }

        return array_unique($dirs);
    }
}

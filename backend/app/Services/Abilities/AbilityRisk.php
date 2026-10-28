<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How risky running an ability is: `read`, `write` or `destructive`.
 *
 * Annotations are the ability author's own claims and are not guaranteed,
 * so they can only make an ability riskier, never safer than its name:
 *
 * - destructive: the name contains one of DESTRUCTIVE_WORDS, or the
 *   `destructive` annotation is true. These wait for a site admin's approval.
 * - read: the `readonly` annotation is true (and nothing above applies).
 * - write: everything else.
 *
 * The MCP layer's Policy classifies `run_ability` calls by the same list.
 */
final class AbilityRisk
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const DESTRUCTIVE = 'destructive';

    /** Matched anywhere in the lowercased name, so `bulk-delete-posts` and `removeitem` count. */
    public const DESTRUCTIVE_WORDS = ['delete', 'remove', 'trash', 'destroy', 'purge', 'erase', 'wipe', 'uninstall'];

    /**
     * @param array<string, mixed> $annotations The ability's `meta.annotations`.
     */
    public static function classify(string $name, array $annotations): string
    {
        $lower = strtolower($name);

        foreach (self::DESTRUCTIVE_WORDS as $word) {
            if (strpos($lower, $word) !== false) {
                return self::DESTRUCTIVE;
            }
        }

        if (($annotations['destructive'] ?? null) === true) {
            return self::DESTRUCTIVE;
        }

        return ($annotations['readonly'] ?? null) === true ? self::READ : self::WRITE;
    }
}

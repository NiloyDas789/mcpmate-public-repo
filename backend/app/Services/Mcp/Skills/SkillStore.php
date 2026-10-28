<?php

namespace McpMate\Services\Mcp\Skills;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Database\Connection as DB;

/**
 * Playbooks and the skills WordPress users saved with save_skill (table
 * `mcpmate_skills`; the cloud's src/skills/store.ts). Every read is
 * scoped to one user: the playbooks, their own skills for all sites, and
 * their skills for this site (`this_site_only`), never another user's.
 *
 * A skill as the formatters take it: {owner: system|user, siteId (this
 * site's id for a this-site skill, else null), name, description,
 * updatedAt (ISO 8601), body?}.
 */
final class SkillStore
{
    public const TABLE = 'skills';

    /** Saved skills per user; a list_skills answer with all of them stays far under the cap. */
    public const MAX_PER_USER = 50;

    public static function table(): string
    {
        return Config::withDBPrefix(self::TABLE);
    }

    /**
     * What $userId sees: playbooks, then theirs for all sites, then theirs
     * for this site; by name within each. Without bodies.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(int $userId, string $siteId): array
    {
        $system = array_map(static function (array $skill): array {
            unset($skill['body']);

            return $skill;
        }, SystemSkills::all());

        $rows = DB::getResults(
            'SELECT id, this_site_only, name, description, updated_at FROM `' . self::table() . '` WHERE wp_user_id = %d ORDER BY this_site_only ASC, name ASC',
            [$userId],
            ARRAY_A
        );

        return array_merge($system, array_map(static fn (array $row): array => self::fromRow($row, $siteId), $rows));
    }

    /**
     * The skill named $name that $userId sees: a playbook wins over a saved
     * skill of the same name, and a this-site skill over an all-sites one.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $userId, string $name, string $siteId): ?array
    {
        foreach (SystemSkills::all() as $skill) {
            if ($skill['name'] === $name) {
                return $skill;
            }
        }

        $row = DB::getRow(
            'SELECT * FROM `' . self::table() . '` WHERE wp_user_id = %d AND name = %s ORDER BY this_site_only DESC LIMIT 1',
            [$userId, $name],
            ARRAY_A
        );

        return is_array($row) ? self::fromRow($row, $siteId) : null;
    }

    /**
     * The user's own skill with exactly this name and scope.
     *
     * @return array<string, mixed>|null
     */
    public static function findOwn(int $userId, bool $thisSiteOnly, string $name, string $siteId): ?array
    {
        $row = DB::getRow(
            'SELECT * FROM `' . self::table() . '` WHERE wp_user_id = %d AND this_site_only = %d AND name = %s',
            [$userId, $thisSiteOnly ? 1 : 0, $name],
            ARRAY_A
        );

        return is_array($row) ? self::fromRow($row, $siteId) : null;
    }

    public static function countForUser(int $userId): int
    {
        return (int) DB::getVar('SELECT COUNT(*) FROM `' . self::table() . '` WHERE wp_user_id = %d', [$userId]);
    }

    /**
     * Insert the user's skill, or replace their skill with the same name and scope.
     *
     * @return array<string, mixed>|null The stored skill.
     */
    public static function save(int $userId, bool $thisSiteOnly, string $name, string $description, string $body, string $siteId): ?array
    {
        $now = gmdate('Y-m-d H:i:s');

        DB::query(
            'INSERT INTO `' . self::table() . '` (wp_user_id, this_site_only, name, description, body_markdown, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE description = VALUES(description), body_markdown = VALUES(body_markdown), updated_at = VALUES(updated_at)',
            [$userId, $thisSiteOnly ? 1 : 0, $name, $description, $body, $now, $now]
        );

        return self::findOwn($userId, $thisSiteOnly, $name, $siteId);
    }

    // ──────────────────────────────────────────────
    //  Site admins (Tools → Skills)
    // ──────────────────────────────────────────────

    /**
     * Every saved skill on this site, newest first, for a site admin.
     *
     * @return array<int, array{id: int, user: array{id: int, login: string|null}, name: string, description: string, this_site_only: bool, bytes: int, updated_at: string}>
     */
    public static function all(): array
    {
        $rows = DB::getResults(
            'SELECT id, wp_user_id, this_site_only, name, description, LENGTH(body_markdown) AS bytes, updated_at FROM `' . self::table() . '` ORDER BY updated_at DESC LIMIT 500',
            [],
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            $user = get_userdata((int) $row['wp_user_id']);

            return [
                'id'             => (int) $row['id'],
                'user'           => ['id' => (int) $row['wp_user_id'], 'login' => $user ? $user->user_login : null],
                'name'           => (string) $row['name'],
                'description'    => (string) $row['description'],
                'this_site_only' => (bool) $row['this_site_only'],
                'bytes'          => (int) $row['bytes'],
                'updated_at'     => str_replace(' ', 'T', (string) $row['updated_at']) . 'Z',
            ];
        }, $rows);
    }

    /**
     * Delete one saved skill (a site admin's decision; closes cloud known gap 20).
     */
    public static function delete(int $id): bool
    {
        return (bool) DB::query('DELETE FROM `' . self::table() . '` WHERE id = %d', [$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function fromRow(array $row, string $siteId): array
    {
        $skill = [
            'owner'       => 'user',
            'siteId'      => (int) $row['this_site_only'] === 1 ? $siteId : null,
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'updatedAt'   => str_replace(' ', 'T', (string) $row['updated_at']) . '.000Z',
        ];

        if (array_key_exists('body_markdown', $row)) {
            $skill['body'] = (string) $row['body_markdown'];
        }

        return $skill;
    }
}

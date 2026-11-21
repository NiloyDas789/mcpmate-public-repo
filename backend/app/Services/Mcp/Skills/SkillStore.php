<?php

namespace MCPMate\Services\Mcp\Skills;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Database\Connection as DB;

/**
 * Playbooks and the skills WordPress users saved with save_skill (table
 * `mcpmate_skills`; the cloud's src/skills/store.ts). Every read is
 * scoped to one user: the playbooks, their own skills for all sites, and
 * their skills for this site (`this_site_only`), never another user's.
 *
 * A skill as the formatters take it: {owner: system|user, siteId (this
 * site's id for a this-site skill, else null), name, description,
 * updatedAt (ISO 8601), body?}.
 *
 * A trashed skill (`trashed_at`, MCPMate Pro's delete_skill or a site
 * admin) is invisible to every AI-facing read and does not count against
 * MAX_PER_USER; saving the same name and scope again brings it back with
 * the new text. Only a site admin removes a row for good (delete(), trashed
 * rows only), or Retention once it has been in the trash TRASH_DAYS days.
 */
final class SkillStore
{
    public const TABLE = 'skills';

    /** Saved skills per user; a list_skills answer with all of them stays far under the cap. */
    public const MAX_PER_USER = 50;

    /** Days a trashed skill is kept before Retention deletes it (filter `MCPMATE_skill_trash_retention_days`). */
    public const TRASH_DAYS = 30;

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
            'SELECT id, this_site_only, name, description, updated_at FROM `' . self::table() . '` WHERE wp_user_id = %d AND trashed_at IS NULL ORDER BY this_site_only ASC, name ASC',
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
            'SELECT * FROM `' . self::table() . '` WHERE wp_user_id = %d AND name = %s AND trashed_at IS NULL ORDER BY this_site_only DESC LIMIT 1',
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
            'SELECT * FROM `' . self::table() . '` WHERE wp_user_id = %d AND this_site_only = %d AND name = %s AND trashed_at IS NULL',
            [$userId, $thisSiteOnly ? 1 : 0, $name],
            ARRAY_A
        );

        return is_array($row) ? self::fromRow($row, $siteId) : null;
    }

    public static function countForUser(int $userId): int
    {
        return (int) DB::getVar('SELECT COUNT(*) FROM `' . self::table() . '` WHERE wp_user_id = %d AND trashed_at IS NULL', [$userId]);
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
                . ' ON DUPLICATE KEY UPDATE description = VALUES(description), body_markdown = VALUES(body_markdown), updated_at = VALUES(updated_at), trashed_at = NULL',
            [$userId, $thisSiteOnly ? 1 : 0, $name, $description, $body, $now, $now]
        );

        return self::findOwn($userId, $thisSiteOnly, $name, $siteId);
    }

    /**
     * The user's own live skill with this name: with $thisSiteOnly null,
     * the one get_skill would load (this site's first), with its row id.
     *
     * @return array<string, mixed>|null
     */
    public static function findOwnByName(int $userId, string $name, ?bool $thisSiteOnly, string $siteId): ?array
    {
        $scope = $thisSiteOnly === null ? '' : ' AND this_site_only = ' . ($thisSiteOnly ? '1' : '0');
        $row   = DB::getRow(
            'SELECT * FROM `' . self::table() . '` WHERE wp_user_id = %d AND name = %s AND trashed_at IS NULL' . $scope . ' ORDER BY this_site_only DESC LIMIT 1',
            [$userId, $name],
            ARRAY_A
        );

        return is_array($row) ? ['id' => (int) $row['id']] + self::fromRow($row, $siteId) : null;
    }

    /**
     * Replace a live skill's description and body.
     */
    public static function update(int $id, string $description, string $body): bool
    {
        return DB::query(
            'UPDATE `' . self::table() . '` SET description = %s, body_markdown = %s, updated_at = %s WHERE id = %d AND trashed_at IS NULL',
            [$description, $body, gmdate('Y-m-d H:i:s'), $id]
        ) !== false;
    }

    /**
     * Move a live skill to the trash; false when it was not live.
     */
    public static function trash(int $id): bool
    {
        return DB::query('UPDATE `' . self::table() . '` SET trashed_at = %s WHERE id = %d AND trashed_at IS NULL', [gmdate('Y-m-d H:i:s'), $id]) === 1;
    }

    // ──────────────────────────────────────────────
    //  Site admins (Tools → Skills)
    // ──────────────────────────────────────────────

    /**
     * Every saved skill on this site, live or (with $trashed) in the trash,
     * newest first, for a site admin.
     *
     * Each: {id, user: {id, login, name}, name, description, this_site_only, bytes, updated_at, trashed_at}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(bool $trashed = false): array
    {
        $rows = DB::getResults(
            'SELECT id, wp_user_id, this_site_only, name, description, LENGTH(body_markdown) AS bytes, updated_at, trashed_at FROM `' . self::table() . '`'
                . ' WHERE trashed_at IS ' . ($trashed ? 'NOT NULL' : 'NULL') . ' ORDER BY ' . ($trashed ? 'trashed_at' : 'updated_at') . ' DESC, id DESC LIMIT 500',
            [],
            ARRAY_A
        );

        return array_map([self::class, 'adminView'], $rows);
    }

    /**
     * Live and trashed counts.
     *
     * @return array{live: int, trash: int}
     */
    public static function counts(): array
    {
        $row = DB::getRow('SELECT SUM(trashed_at IS NULL) AS live, SUM(trashed_at IS NOT NULL) AS trash FROM `' . self::table() . '`', [], ARRAY_A);

        return ['live' => (int) ($row['live'] ?? 0), 'trash' => (int) ($row['trash'] ?? 0)];
    }

    /**
     * One saved skill with its body, live or trashed, for a site admin.
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        $row = DB::getRow(
            'SELECT id, wp_user_id, this_site_only, name, description, body_markdown, LENGTH(body_markdown) AS bytes, updated_at, trashed_at'
                . ' FROM `' . self::table() . '` WHERE id = %d',
            [$id],
            ARRAY_A
        );

        return is_array($row) ? self::adminView($row) + ['body' => (string) $row['body_markdown']] : null;
    }

    /**
     * Take a trashed skill out of the trash; false when it was not trashed.
     */
    public static function restore(int $id): bool
    {
        return DB::query('UPDATE `' . self::table() . '` SET trashed_at = NULL WHERE id = %d AND trashed_at IS NOT NULL', [$id]) === 1;
    }

    /**
     * Delete one trashed skill for good (a site admin's decision; a live
     * skill is moved to the trash first).
     */
    public static function delete(int $id): bool
    {
        return DB::query('DELETE FROM `' . self::table() . '` WHERE id = %d AND trashed_at IS NOT NULL', [$id]) === 1;
    }

    /**
     * Delete skills trashed more than $days days ago (the daily Retention run).
     */
    public static function purgeTrashedBefore(int $days): int
    {
        return (int) DB::query(
            'DELETE FROM `' . self::table() . '` WHERE trashed_at IS NOT NULL AND trashed_at < %s',
            [gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> {id, user: {id, login, name}, name, description, this_site_only, bytes, updated_at, trashed_at}
     */
    private static function adminView(array $row): array
    {
        $user = get_userdata((int) $row['wp_user_id']);

        return [
            'id'             => (int) $row['id'],
            'user'           => [
                'id'    => (int) $row['wp_user_id'],
                'login' => $user ? $user->user_login : null,
                'name'  => $user ? $user->display_name : null,
            ],
            'name'           => (string) $row['name'],
            'description'    => (string) $row['description'],
            'this_site_only' => (bool) $row['this_site_only'],
            'bytes'          => (int) $row['bytes'],
            'updated_at'     => str_replace(' ', 'T', (string) $row['updated_at']) . 'Z',
            'trashed_at'     => $row['trashed_at'] === null ? null : str_replace(' ', 'T', (string) $row['trashed_at']) . 'Z',
        ];
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

<?php

namespace McpMate\Services\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\ChangeHistory\ChangeCapture;

/**
 * Settings → Logging and history, and the rows of Access and safety and
 * Advanced that store something (docs/ui.md §5.5, B8; the OAuth rows of
 * docs/mcp-in-plugin.md §6.2). One option,
 * `MCPMATE_preferences` (not `settings`, which the 0.5.0 migration
 * removed); a missing key has its default.
 *
 * update() checks every value against SCHEMA (type, enum, range), then the
 * rules between values (crossCheck()), and saves nothing when one fails.
 * Keys it does not know are ignored. The Settings page checks the same
 * rules before it sends (frontend/src/pages/Settings/logic.ts).
 *
 * The approval capability can never be lower than publish_posts (its enum).
 * It is also locked to manage_options until security rule 9 changes (D5):
 * approveCapabilityLocked() makes any other value a 400, and
 * approveCapability() returns manage_options whatever is stored.
 */
final class Preferences
{
    public const OPTION = 'preferences';

    /** D5: lower roles may not approve AI apps until rule 9 is changed and signed off. */
    private static bool $approveCapabilityLocked = true;

    /** Allowed approval capabilities, highest first; publish_posts is the floor. */
    public const APPROVE_CAPABILITIES = ['manage_options', 'edit_others_posts', 'publish_posts'];

    /**
     * key => [type, default, allowed values (enum) or null].
     *
     * @var array<string, array{0: string, 1: mixed, 2: array<int, mixed>|null}>
     */
    private const SCHEMA = [
        'log_reads'                => ['bool', true, null],
        'audit_retention_days'     => ['int', 90, [30, 60, 90, 180, 365]],
        'read_retention_days'      => ['int', 30, [7, 14, 30, 90]],
        'change_history'           => ['bool', true, null],
        'change_history_types'     => ['list', ChangeCapture::TYPES, ChangeCapture::TYPES],
        'change_retention_days'    => ['int', 30, [7, 14, 30, 90]],
        'approve_capability'       => ['enum', 'manage_options', self::APPROVE_CAPABILITIES],
        // OAuth (docs/mcp-in-plugin.md §4.3, §4.5): access token minutes, refresh
        // days (absolute from the approval), and whether apps may register themselves.
        'access_token_minutes'     => ['int', 15, [5, 15, 30, 60]],
        'refresh_token_days'       => ['int', 30, [7, 30, 60, 90]],
        'open_registration'        => ['bool', true, null],
        // Requests per minute for each AI app on the MCP endpoint (docs/mcp-in-plugin.md §4.6).
        'rate_limit'               => ['int', 120, [30, 60, 120, 300, 600, 1000]],
        'delete_data_on_uninstall' => ['bool', true, null],
    ];

    /**
     * Every preference, defaults filled in.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved  = Config::getOption(self::OPTION, []);
        $saved  = is_array($saved) ? $saved : [];
        $values = [];

        foreach (self::SCHEMA as $key => $spec) {
            $values[$key] = array_key_exists($key, $saved) && self::check($key, $saved[$key]) === null
                ? $saved[$key]
                : $spec[1];
        }

        return $values;
    }

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Save $changes. Every known key is checked first; one invalid value
     * saves nothing.
     *
     * @param array<array-key, mixed> $changes
     * @param array<string, int>      $filtered Retention windows a filter sets in code (Retention::filtered()):
     *                                          the cross-checks use them instead of the stored values.
     * @return array<string, mixed> Every preference after the change.
     * @throws PreferencesException 400, with an error per invalid key.
     */
    public static function update(array $changes, array $filtered = []): array
    {
        $values = self::all();
        $errors = [];

        foreach ($changes as $key => $value) {
            if (!is_string($key) || !isset(self::SCHEMA[$key])) {
                continue;
            }

            $error = self::check($key, $value);

            if ($error === null && $key === 'approve_capability' && self::approveCapabilityLocked() && $value !== 'manage_options') {
                $error = __('Only administrators can approve AI apps for now.', 'mcpmate');
            }

            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }

            $values[$key] = $key === 'change_history_types' ? array_values(array_unique($value)) : $value;
        }

        $errors += self::crossCheck($values, array_filter(array_keys($changes), 'is_string'), $filtered, $errors);

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The errors become a JSON body (wp_send_json_error), never HTML.
            throw new PreferencesException($errors);
        }

        Config::updateOption(self::OPTION, $values, false);

        return $values;
    }

    // ──────────────────────────────────────────────
    //  Typed reads
    // ──────────────────────────────────────────────

    public static function logReads(): bool
    {
        return (bool) self::get('log_reads');
    }

    public static function changeHistory(): bool
    {
        return (bool) self::get('change_history');
    }

    /**
     * @return string[]
     */
    public static function changeHistoryTypes(): array
    {
        return (array) self::get('change_history_types');
    }

    /**
     * Whether AI apps may register themselves (Dynamic Client
     * Registration). Apps with a client ID metadata document can always
     * connect.
     */
    public static function openRegistration(): bool
    {
        return (bool) self::get('open_registration');
    }

    public static function deleteDataOnUninstall(): bool
    {
        return (bool) self::get('delete_data_on_uninstall');
    }

    /**
     * Whether only administrators may approve AI apps, whatever is stored (D5).
     */
    public static function approveCapabilityLocked(): bool
    {
        return self::$approveCapabilityLocked;
    }

    /**
     * The capability the AI-app approval screen needs: manage_options while
     * approveCapabilityLocked(), whatever is stored.
     */
    public static function approveCapability(): string
    {
        return self::approveCapabilityLocked() ? 'manage_options' : (string) self::get('approve_capability');
    }

    /**
     * Which keys a site admin cannot change here, and why: `locked` (D5)
     * or a filter that sets the value in code (`filtered`, with the value).
     *
     * @param array<string, int> $filtered Key => the value code sets.
     * @return array<string, array{reason: string, value?: mixed}>
     */
    public static function fixed(array $filtered = []): array
    {
        $fixed = [];

        if (self::approveCapabilityLocked()) {
            $fixed['approve_capability'] = ['reason' => 'locked', 'value' => 'manage_options'];
        }

        foreach ($filtered as $key => $value) {
            $fixed[$key] = ['reason' => 'filtered', 'value' => $value];
        }

        return $fixed;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * Rules between values, reported on a key the request changed (a stored
     * pair that already disagrees never blocks an unrelated save):
     * read calls are not kept longer than the audit log (its purge would
     * remove them first), and change history that is on records at least
     * one type.
     *
     * @param array<string, mixed>  $values   Every preference after the change.
     * @param string[]              $changed  Keys in the request.
     * @param array<string, int>    $filtered Windows set in code.
     * @param array<string, string> $errors   Per-key errors found so far.
     * @return array<string, string>
     */
    private static function crossCheck(array $values, array $changed, array $filtered, array $errors): array
    {
        $found   = [];
        $changes = static fn (string $key): bool => in_array($key, $changed, true) && !isset($errors[$key]);

        $audit = (int) ($filtered['audit_retention_days'] ?? $values['audit_retention_days']);
        $reads = (int) ($filtered['read_retention_days'] ?? $values['read_retention_days']);

        // Below 1 day a window keeps every row.
        if ($audit >= 1 && $reads >= 1 && $reads > $audit) {
            if ($changes('read_retention_days')) {
                /* translators: %d: days the audit log keeps entries. */
                $found['read_retention_days'] = sprintf(__('Read calls can\'t be kept longer than the audit log (%d days).', 'mcpmate'), $audit);
            } elseif ($changes('audit_retention_days')) {
                /* translators: %d: days read calls are kept. */
                $found['audit_retention_days'] = sprintf(__('Keep the audit log at least as long as read calls (%d days).', 'mcpmate'), $reads);
            }
        }

        if ($values['change_history'] === true && $values['change_history_types'] === []) {
            $message = __('Choose at least one kind of change to record, or turn change history off.', 'mcpmate');

            if ($changes('change_history_types')) {
                $found['change_history_types'] = $message;
            } elseif ($changes('change_history')) {
                $found['change_history'] = $message;
            }
        }

        return $found;
    }

    /**
     * Null when $value is valid for $key, else the reason.
     *
     * @param mixed $value
     */
    private static function check(string $key, $value): ?string
    {
        [$type, , $allowed] = self::SCHEMA[$key];

        switch ($type) {
            case 'bool':
                return is_bool($value) ? null : __('Must be true or false.', 'mcpmate');
            case 'int':
                return is_int($value) && in_array($value, (array) $allowed, true)
                    /* translators: %s: comma-separated list of allowed numbers. */
                    ? null : sprintf(__('Must be one of: %s.', 'mcpmate'), implode(', ', (array) $allowed));
            case 'enum':
                return is_string($value) && in_array($value, (array) $allowed, true)
                    /* translators: %s: comma-separated list of allowed values. */
                    ? null : sprintf(__('Must be one of: %s.', 'mcpmate'), implode(', ', (array) $allowed));
            default: // list
                if (!is_array($value) || array_values($value) !== $value) {
                    return __('Must be a list.', 'mcpmate');
                }

                foreach ($value as $item) {
                    if (!is_string($item) || !in_array($item, (array) $allowed, true)) {
                        /* translators: %s: comma-separated list of allowed values. */
                        return sprintf(__('Each item must be one of: %s.', 'mcpmate'), implode(', ', (array) $allowed));
                    }
                }

                return null;
        }
    }
}

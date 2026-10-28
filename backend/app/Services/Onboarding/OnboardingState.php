<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Connection\Grants;

/**
 * Progress through the first-run setup wizard (`#/setup`), stored in the
 * `MCPMATE_onboarding` option (autoload off). Site-wide, not per user:
 * the site has one connection, and every step re-reads the real state
 * (connection, grants, switches) when it opens.
 *
 *   not_started ──Get started──▶ in_progress ──Done / I'll try later──▶ completed
 *        └────────────Skip setup────────▶ skipped ◀───────┘
 *
 * Only the wizard's admin-ajax routes write it. The client may set `step`,
 * `status` (never back to `not_started`) and `grant_id`; `connect_since`,
 * `source` and the timestamps are set here.
 */
final class OnboardingState
{
    public const OPTION = 'onboarding';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_SKIPPED, self::STATUS_COMPLETED];

    /** Statuses the client may set. */
    public const CLIENT_STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_SKIPPED, self::STATUS_COMPLETED];

    public const STEPS = ['welcome', 'check', 'safety', 'add_ai', 'first_call'];

    /** Step 4: entering it sets connect_since, the baseline for "a new grant". */
    public const STEP_ADD_AI = 'add_ai';

    public const SOURCE_ACTIVATION = 'activation';

    public const SOURCE_EXISTING = 'existing';

    public const SOURCE_RERUN = 'rerun';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'v'                   => 1,
            'status'              => self::STATUS_NOT_STARTED,
            'step'                => 'welcome',
            'source'              => self::SOURCE_ACTIVATION,
            'started_at'          => null,
            'updated_at'          => null,
            'completed_at'        => null,
            'features_defaulted'  => false,
            'connect_since'       => null,
            'grant_id'            => '',
            'notice_dismissed_by' => [],
        ];
    }

    /**
     * The stored state, with defaults for anything missing or malformed.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return self::normalize(Config::getOption(self::OPTION, []));
    }

    public static function status(): string
    {
        return (string) self::get()['status'];
    }

    /**
     * Whether setup still has something to offer (notice, Dashboard card,
     * Plugins row link).
     */
    public static function isOpen(): bool
    {
        return self::status() !== self::STATUS_COMPLETED;
    }

    /**
     * Migration: create the option once. A site that already has a grant
     * was set up before the wizard existed and is marked completed.
     */
    public static function seed(bool $hasGrants): void
    {
        $state = self::defaults();

        if ($hasGrants) {
            $now                   = gmdate('Y-m-d H:i:s');
            $state['status']       = self::STATUS_COMPLETED;
            $state['source']       = self::SOURCE_EXISTING;
            $state['completed_at'] = $now;
            $state['updated_at']   = $now;
        }

        // add_option() keeps a value that is already there.
        Config::addOption(self::OPTION, $state, false);
    }

    /**
     * Apply a client update: `step` from STEPS, `status` from
     * CLIENT_STATUSES, `grant_id` of an existing grant. Anything else is
     * ignored.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error The new state.
     */
    public static function update(array $input)
    {
        $invalid = self::invalidField($input);

        if ($invalid === null && array_key_exists('grant_id', $input) && Grants::find($input['grant_id']) === null) {
            $invalid = 'grant_id';
        }

        if ($invalid !== null) {
            return self::invalid($invalid);
        }

        $state    = self::get();
        $previous = $state;
        $now      = gmdate('Y-m-d H:i:s');

        foreach (['step', 'status', 'grant_id'] as $key) {
            if (array_key_exists($key, $input)) {
                $state[$key] = $input[$key];
            }
        }

        // Run setup again after it was completed: start over from a clean baseline.
        if (
            $state['status'] === self::STATUS_IN_PROGRESS
            && $previous['status'] === self::STATUS_COMPLETED
        ) {
            $state['source']        = self::SOURCE_RERUN;
            $state['connect_since'] = null;
            $state['grant_id']      = array_key_exists('grant_id', $input) ? $state['grant_id'] : '';
            $state['completed_at']  = null;
        }

        if ($state['status'] === self::STATUS_IN_PROGRESS && $state['started_at'] === null) {
            $state['started_at'] = $now;
        }

        if ($state['status'] === self::STATUS_COMPLETED && $previous['status'] !== self::STATUS_COMPLETED) {
            $state['completed_at'] = $now;
        }

        // Step 3 was saved: its defaults have been offered once.
        if ($state['step'] === self::STEP_ADD_AI) {
            $state['features_defaulted'] = true;

            if ($state['connect_since'] === null) {
                $state['connect_since'] = $now;
            }
        }

        $state['updated_at'] = $now;

        self::save($state);

        return $state;
    }

    /**
     * The first field of a client update outside its enum or format (pure),
     * or null. Whether the grant exists is checked by update().
     *
     * @param array<string, mixed> $input
     */
    public static function invalidField(array $input): ?string
    {
        if (array_key_exists('step', $input) && (!is_string($input['step']) || !in_array($input['step'], self::STEPS, true))) {
            return 'step';
        }

        if (array_key_exists('status', $input) && (!is_string($input['status']) || !in_array($input['status'], self::CLIENT_STATUSES, true))) {
            return 'status';
        }

        if (array_key_exists('grant_id', $input) && (!is_string($input['grant_id']) || !Grants::isGrantId($input['grant_id']))) {
            return 'grant_id';
        }

        return null;
    }

    /**
     * "Don't show again" on the resume notice, for one user.
     */
    public static function dismissNotice(int $userId): void
    {
        $state = self::get();

        if ($userId <= 0 || in_array($userId, $state['notice_dismissed_by'], true)) {
            return;
        }

        $state['notice_dismissed_by'][] = $userId;
        self::save($state);
    }

    public static function noticeDismissedBy(int $userId): bool
    {
        return in_array($userId, self::get()['notice_dismissed_by'], true);
    }

    /**
     * What the admin app may see: everything except the dismissed-by list.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function forClient(array $state): array
    {
        unset($state['notice_dismissed_by']);

        return $state;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param mixed $stored
     * @return array<string, mixed>
     */
    public static function normalize($stored): array
    {
        $defaults = self::defaults();
        $state    = is_array($stored) ? array_intersect_key($stored + $defaults, $defaults) : $defaults;

        if (!in_array($state['status'], self::STATUSES, true)) {
            $state['status'] = $defaults['status'];
        }

        if (!in_array($state['step'], self::STEPS, true)) {
            $state['step'] = $defaults['step'];
        }

        if (!in_array($state['source'], [self::SOURCE_ACTIVATION, self::SOURCE_EXISTING, self::SOURCE_RERUN], true)) {
            $state['source'] = $defaults['source'];
        }

        foreach (['started_at', 'updated_at', 'completed_at', 'connect_since'] as $key) {
            $state[$key] = is_string($state[$key]) && $state[$key] !== '' ? $state[$key] : null;
        }

        $state['v']                   = 1;
        $state['features_defaulted']  = (bool) $state['features_defaulted'];
        $state['grant_id']            = is_string($state['grant_id']) ? $state['grant_id'] : '';
        $state['notice_dismissed_by'] = is_array($state['notice_dismissed_by'])
            ? array_values(array_unique(array_filter(array_map('intval', $state['notice_dismissed_by']))))
            : [];

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function save(array $state): void
    {
        Config::updateOption(self::OPTION, $state, false);
    }

    private static function invalid(string $field): \WP_Error
    {
        return new \WP_Error(
            'mcpmate_invalid_onboarding',
            /* translators: %s: field name, e.g. step. */
            sprintf(__('The setup field "%s" is invalid.', 'mcpmate'), $field),
            ['status' => 400]
        );
    }
}

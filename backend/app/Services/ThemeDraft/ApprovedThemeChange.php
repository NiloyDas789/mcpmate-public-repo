<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Approvals\ApprovalException;
use McpMate\Utils\Capabilities;

/**
 * Executor for approved `POST /theme-draft/publish` and
 * `POST /theme-draft/rollback` requests (tool `theme_draft` in Approvals).
 *
 * Runs in the approving admin's request. Before anything changes, it checks
 * again, against the site as it is now:
 *
 * 1. The operation is `publish` or `rollback` (default-deny).
 * 2. The requester still has edit_themes and switch_themes, and so does
 *    the approving admin.
 * 3. Theme drafts are still enabled.
 * 4. ThemePublisher re-runs every check of its plan (draft still valid and
 *    unpublished; backup unchanged and still for the live theme).
 *
 * Then the operation runs once.
 */
final class ApprovedThemeChange
{
    public const CAPABILITIES = ['edit_themes', 'switch_themes'];

    public const OPERATIONS = ['publish', 'rollback'];

    /**
     * @param array<string, mixed> $args The stored request: `operation`, plus `draft_id` or `backup_id`.
     * @return array<string, mixed>
     * @throws ApprovalException When a check fails or the operation refuses.
     */
    public static function run(array $args, int $requestedBy): array
    {
        $operation = (string) ($args['operation'] ?? '');

        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new ApprovalException(
                esc_html__('This theme operation cannot be run.', 'mcpmate'),
                'mcpmate_approval_unknown_tool',
                400
            );
        }

        foreach (self::CAPABILITIES as $capability) {
            if (!user_can($requestedBy, $capability)) {
                throw new ApprovalException(
                    esc_html__('The user who requested this theme change can no longer make it.', 'mcpmate'),
                    'mcpmate_forbidden',
                    403,
                    /* translators: %s: WordPress capability name. */
                    sprintf(esc_html__('The requester needs the "%s" capability.', 'mcpmate'), esc_html($capability))
                );
            }

            if (!Capabilities::check($capability)) {
                throw new ApprovalException(
                    esc_html__('Sorry, you are not allowed to do that.', 'mcpmate'),
                    'mcpmate_forbidden',
                    403,
                    /* translators: %s: WordPress capability name. */
                    sprintf(esc_html__('Approving this theme change needs the "%s" capability.', 'mcpmate'), esc_html($capability))
                );
            }
        }

        if (!ThemeDraftSettings::isEnabled()) {
            throw new ApprovalException(
                esc_html__('Theme drafts are not enabled on this site.', 'mcpmate'),
                'mcpmate_theme_drafts_disabled',
                403,
                esc_html__('Enable them under McpMate → Tools → Core (Theme drafts), then ask the AI to send the request again.', 'mcpmate')
            );
        }

        try {
            if ($operation === 'publish') {
                return ThemePublisher::publish(Drafts::find((string) ($args['draft_id'] ?? '')), get_current_user_id(), $requestedBy);
            }

            $backupId = (string) ($args['backup_id'] ?? '');

            return ThemePublisher::rollback($backupId === '' ? null : self::backup($backupId), get_current_user_id());
        } catch (ThemeDraftException $e) {
            throw new ApprovalException(esc_html($e->getMessage()), esc_html($e->errorCode()), (int) $e->status(), esc_html($e->hint()));
        }
    }

    /**
     * @return array<string, mixed>
     * @throws ThemeDraftException 404
     */
    private static function backup(string $id): array
    {
        $backup = ThemeBackups::find($id);

        if ($backup === null) {
            throw new ThemeDraftException(esc_html__('Theme backup not found.', 'mcpmate'), esc_html(ThemeDraftException::BACKUP_NOT_FOUND), 404);
        }

        return $backup;
    }
}

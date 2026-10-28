<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Approvals\ApprovalException;
use McpMate\Utils\Capabilities;

/**
 * Executor for approved `POST /cli` requests (tool `cli` in Approvals).
 *
 * Runs in the approving admin's request. The stored command string goes
 * through the same checks as the original call, against the state of the
 * site now rather than when it was requested:
 *
 * 1. CommandParser: still registered, arguments still valid.
 * 2. Still destructive (only destructive commands are queued).
 * 3. The requester still has the command's capability, and so does the
 *    approving admin (handlers also check object-level capabilities
 *    against the admin).
 * 4. Still enabled in CommandSettings.
 *
 * Then the handler runs once with dry run off.
 */
final class ApprovedCommand
{
    /**
     * @param array<string, mixed> $args The stored request: `command` (string).
     * @return array<string, mixed>
     * @throws ApprovalException When any check fails or the handler refuses.
     */
    public static function run(array $args, int $requestedBy): array
    {
        try {
            $parsed = CommandParser::parse((string) ($args['command'] ?? ''), CommandRegistry::defaults());
        } catch (CommandException $e) {
            throw new ApprovalException(esc_html($e->getMessage()), esc_html($e->errorCode()), (int) $e->status(), esc_html($e->hint()));
        }

        $command = $parsed->command();

        if (!$command->isDestructive()) {
            throw new ApprovalException(
                esc_html__('Only destructive commands run through approvals.', 'mcpmate'),
                esc_html(CommandException::NOT_ALLOWED),
                400
            );
        }

        if (!user_can($requestedBy, $command->capability())) {
            throw new ApprovalException(
                esc_html__('The user who requested this command can no longer run it.', 'mcpmate'),
                esc_html(CommandException::FORBIDDEN),
                403,
                sprintf(
                    /* translators: %s: WordPress capability name. */
                    esc_html__('The requester needs the "%s" capability.', 'mcpmate'),
                    esc_html($command->capability())
                )
            );
        }

        if (!Capabilities::check($command->capability())) {
            throw new ApprovalException(
                esc_html__('Sorry, you are not allowed to do that.', 'mcpmate'),
                esc_html(CommandException::FORBIDDEN),
                403,
                sprintf(
                    /* translators: %s: WordPress capability name. */
                    esc_html__('Approving this command needs the "%s" capability.', 'mcpmate'),
                    esc_html($command->capability())
                )
            );
        }

        if (!CommandSettings::isEnabled($command->name())) {
            throw new ApprovalException(
                /* translators: %s: command name. */
                sprintf(esc_html__('"%s" is not enabled on this site.', 'mcpmate'), esc_html($command->name())),
                'mcpmate_cli_command_disabled',
                403,
                esc_html__('Enable it under McpMate → Tools → Core (WP-CLI commands), then ask the AI to send the request again.', 'mcpmate')
            );
        }

        try {
            $result = $command->run($parsed, false);
        } catch (CommandException $e) {
            throw new ApprovalException(esc_html($e->getMessage()), esc_html($e->errorCode()), (int) $e->status(), esc_html($e->hint()));
        }

        return [
            'command' => $command->name(),
            'risk'    => $command->risk(),
            'dry_run' => false,
            'args'    => $parsed->args(),
            'flags'   => array_diff_key($parsed->flags(), [Command::DRY_RUN_FLAG => true]),
            'result'  => $result,
        ];
    }
}

<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\HTTP\RestEnvelope;
use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandException;
use McpMate\Operations\Cli\CommandParser;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\CommandSettings;
use McpMate\Operations\Cli\ParsedCommand;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Services\Approvals\Approvals;
use McpMate\Utils\Capabilities;

/**
 * POST /cli { command, dry_run } — run one WP-CLI-style command in PHP.
 *
 * Nothing reaches a shell. Order of checks:
 *
 * 1. The route needs edit_posts (the lowest capability any command needs).
 * 2. CommandParser: the name must be registered (default-deny) and every
 *    argument and flag must pass the command's schema → 400.
 * 3. The command's own capability → 403.
 * 4. The command must be enabled by a site admin (CommandSettings) → 403.
 * 5. dry_run: the body field, else `--dry-run` / `--no-dry-run`, else the
 *    command's default. A dry run of a command without dry-run support → 400.
 * 6. Destructive and not a dry run: the handler runs as a dry run to check
 *    the arguments and build a preview. The command and preview are stored
 *    (Approvals) and the response is 202 pending_approval with the
 *    approval ID, a one-time token, the wp-admin approval URL and the
 *    expiry. Nothing changes until a site admin approves it.
 * 7. Otherwise the handler runs. Its rejections (not found, not
 *    allowlisted, object-level capability) keep their status.
 *
 * Every call is audited (POST) with action = command name, spaces as dots
 * (`plugin.list`).
 */
final class CliController extends RestController
{
    protected const CAPABILITIES = [
        'run' => 'edit_posts',
    ];

    protected const TOOL = 'cli';

    public function run(Request $request): Response
    {
        $registry = CommandRegistry::defaults();

        try {
            $parsed = CommandParser::parse((string) $request->get('command', ''), $registry);
        } catch (CommandException $e) {
            return $this->fromException($e);
        }

        $command = $parsed->command();
        // Audit actions are identifiers (Logger::clean()): `plugin list` → `plugin.list`.
        $this->auditAction(str_replace(' ', '.', $command->name()));

        $target = $command->auditTarget($parsed);
        if ($target !== null) {
            $this->auditTarget($target[0], $target[1]);
        }

        if (!Capabilities::check($command->capability())) {
            return $this->error(
                __('Sorry, you are not allowed to do that.', 'mcpmate'),
                403,
                'mcpmate_forbidden',
                sprintf(
                    /* translators: 1: command name, 2: WordPress capability name. */
                    __('"%1$s" needs the "%2$s" capability. Use a WordPress account that has it.', 'mcpmate'),
                    $command->name(),
                    $command->capability()
                )
            );
        }

        if (!CommandSettings::isEnabled($command->name())) {
            return $this->error(
                /* translators: %s: command name. */
                sprintf(__('"%s" is not enabled on this site.', 'mcpmate'), $command->name()),
                403,
                'mcpmate_cli_command_disabled',
                __('A site admin can enable it under McpMate → Tools → Core (WP-CLI commands).', 'mcpmate')
            );
        }

        $dryRun = $this->resolveDryRun($request, $parsed);
        if ($dryRun instanceof Response) {
            return $dryRun;
        }

        $summary = [
            'command' => $command->name(),
            'risk'    => $command->risk(),
            'dry_run' => $dryRun,
            'args'    => $parsed->args(),
            'flags'   => array_diff_key($parsed->flags(), [Command::DRY_RUN_FLAG => true]),
        ];

        try {
            if ($command->isDestructive() && !$dryRun) {
                return $this->requestApproval($request, $parsed, $command->run($parsed, true), $summary);
            }

            $result = $command->run($parsed, $dryRun);
        } catch (CommandException $e) {
            return $this->fromException($e);
        }

        return $this->ok($summary + ['result' => $result]);
    }

    /**
     * REST `args` schema for run().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function runArgs(): array
    {
        return [
            'command' => [
                'type'        => 'string',
                'description' => 'One WP-CLI-style command, e.g. "plugin list --status=active".',
                'required'    => true,
                'minLength'   => 1,
                'maxLength'   => CommandParser::MAX_LENGTH,
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'Preview without changing anything. Defaults to the command\'s own default (true for search-replace).',
            ],
        ];
    }

    /**
     * @return bool|Response
     */
    private function resolveDryRun(Request $request, ParsedCommand $parsed)
    {
        $command = $parsed->command();
        $body    = $request->get('dry_run');
        $flag    = $parsed->hasFlag(Command::DRY_RUN_FLAG) ? (bool) $parsed->flag(Command::DRY_RUN_FLAG) : null;

        if ($body !== null && $flag !== null && (bool) $body !== $flag) {
            return $this->error(
                __('dry_run and --dry-run disagree.', 'mcpmate'),
                400,
                CommandException::INVALID_ARGS,
                __('Set only one of them.', 'mcpmate')
            );
        }

        if ($body !== null) {
            $dryRun = (bool) $body;
        } elseif ($flag !== null) {
            $dryRun = $flag;
        } else {
            $dryRun = $command->dryRunDefault();
        }

        if ($dryRun && !$command->supportsDryRun()) {
            return $this->error(
                /* translators: %s: command name. */
                sprintf(__('"%s" does not support dry runs.', 'mcpmate'), $command->name()),
                400,
                'mcpmate_cli_dry_run_unsupported',
                $command->risk() === Command::RISK_READ ? __('It only reads; run it without dry_run.', 'mcpmate') : ''
            );
        }

        return $dryRun;
    }

    /**
     * Store the command and its preview for a site admin (202). The client
     * polls POST /approvals/{id}/status with the token to get the result.
     *
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $summary
     */
    private function requestApproval(Request $request, ParsedCommand $parsed, array $preview, array $summary): Response
    {
        $command     = $parsed->command();
        $restRequest = $request->restRequest();
        $target      = $command->auditTarget($parsed);

        try {
            $approval = Approvals::request(
                self::tool(),
                str_replace(' ', '.', $command->name()),
                $target !== null ? $target[0] . ':' . $target[1] : '',
                ['command' => (string) $request->get('command', '')],
                $preview,
                $restRequest ? RestEnvelope::requestId($restRequest) : ''
            );
        } catch (ApprovalException $e) {
            return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
        }

        return $this->pendingApproval(
            sprintf(
                /* translators: 1: command name, 2: approval ID. */
                __('"%1$s" is waiting for a site admin to approve it (approval #%2$d).', 'mcpmate'),
                $command->name(),
                $approval['id']
            ),
            [
                'status'           => 'pending_approval',
                'pending_approval' => true,
                'approval_id'      => $approval['id'],
                'approval_token'   => $approval['token'],
                'approval_url'     => $approval['approval_url'],
                'expires_at'       => $approval['expires_at'],
                'preview'          => $preview,
            ] + $summary
        );
    }

    private function fromException(CommandException $e): Response
    {
        return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
    }
}

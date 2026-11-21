<?php

namespace MCPMate\Extensions;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\CommandRegistry;

/**
 * A set of MCP tools the MCPMate Pro add-on adds to this plugin's
 * allowlists (Features). Every method describes what the add-on brings; the
 * free plugin keeps every check: the router maps each route to its tool, a
 * tool ships off until a site admin turns it on, grants and scopes apply,
 * and Policy and Approvals run the add-on's classes only for its own tools.
 *
 * A method left as is adds nothing.
 */
// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Default no-ops of an extension point: features override them and use the parameters.
abstract class Feature
{
    /**
     * Unique id (one registration per id).
     */
    abstract public function id(): string;

    /**
     * Tool groups: id => {source, label, after}. `after` is the group the new
     * one follows on the Tools page (else it is added before `skills`).
     *
     * @return array<string, array{source: string, label: string, after?: string}>
     */
    public function groups(): array
    {
        return [];
    }

    /**
     * Tools in display order: name => the ToolCatalog entry and its texts.
     * A tool may join a free group (run_ability joins `abilities`).
     *
     * @return array<string, array{group: string, kind: string, approval?: bool, default: bool, always_on?: bool, items?: string, label: string, description: string}>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Route (`METHOD path` as declared) => tool, for the router.
     *
     * @return array<string, string>
     */
    public function routes(): array
    {
        return [];
    }

    /**
     * Declare the add-on's REST_NS routes. Called on `rest_api_init` with
     * the free plugin's router set (Route::get() etc. go to it).
     */
    public function registerRestRoutes(): void
    {
    }

    /**
     * Declare the add-on's admin-ajax routes. Called during AJAX requests
     * inside the free plugin's `nonce` + `isAdmin` group.
     */
    public function registerAjaxRoutes(): void
    {
    }

    // ──────────────────────────────────────────────
    //  MCP layer
    // ──────────────────────────────────────────────

    /**
     * MCP tool => handler class (a Services\Mcp\Tools\Tool).
     *
     * @return array<string, class-string>
     */
    public function handlers(): array
    {
        return [];
    }

    /**
     * The tool definitions file: {instructions, tools} like docs/mcp-tools.json.
     * Its instructions are appended to the free plugin's.
     */
    public function definitionsFile(): string
    {
        return '';
    }

    /**
     * Text the MCP server `instructions` start with, ahead of every
     * definitions file's (ToolRegistry::instructions()), or ''. Read on
     * each call, so a change applies to the next initialize.
     */
    public function leadingInstructions(): string
    {
        return '';
    }

    /**
     * Text added after every definitions file's instructions, or ''. Read
     * on each call while the grant's McpContext is active, so it can depend
     * on which tools the grant sees (ToolRegistry::isVisible()).
     */
    public function trailingInstructions(): string
    {
        return '';
    }

    /**
     * The arguments to keep in the audit row of one of the add-on's local
     * tools (no route, McpAudit), or null to keep them all. They are
     * redacted and cut as usual afterwards.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    public function auditArgs(string $tool, array $args): ?array
    {
        return null;
    }

    /**
     * MCP tool => Policy class (`read`, `write`, `destructive`) or `call`
     * (classified per call by classify()).
     *
     * @return array<string, string>
     */
    public function policy(): array
    {
        return [];
    }

    /**
     * Tools classified per call that are never a read (a read-only grant does not list them).
     *
     * @return string[]
     */
    public function neverRead(): array
    {
        return [];
    }

    /**
     * Class of one call of a `call` tool, or why it is refused (Policy::classify()'s shape).
     *
     * @param array<string, mixed> $args
     * @return array{allowed: bool, class: string, approval?: bool, reason?: string, hint?: string}|null
     */
    public function classify(string $tool, array $args): ?array
    {
        return null;
    }

    /**
     * The folder of the add-on's playbooks (skills/*.md), or ''.
     */
    public function skillsDirectory(): string
    {
        return '';
    }

    // ──────────────────────────────────────────────
    //  WP-CLI commands and abilities
    // ──────────────────────────────────────────────

    /**
     * Add the add-on's commands to POST /cli's allowlist (CommandRegistry::add()
     * refuses a name that is taken).
     */
    public function registerCommands(CommandRegistry $registry): void
    {
    }

    /**
     * The same commands for the policy layer: name => risk (`read`, `write`,
     * `destructive`). CommandRegistryTest keeps both lists equal.
     *
     * @return array<string, string>
     */
    public function cliCommands(): array
    {
        return [];
    }

    /**
     * Ability risks run_ability may run besides `read` (AbilityRunner).
     *
     * @return string[]
     */
    public function abilityRisks(): array
    {
        return [];
    }

    // ──────────────────────────────────────────────
    //  Approvals
    // ──────────────────────────────────────────────

    /**
     * Approval tool => executor callable(array $args, int $requestedBy): array.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public function executors(): array
    {
        return [];
    }

    /**
     * What the audit row of an approved request of one of the add-on's
     * approval tools keeps, or null to keep its stored arguments. The
     * executor still gets the stored arguments in full.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    public function approvalAuditArgs(string $tool, array $args): ?array
    {
        return null;
    }

    // ──────────────────────────────────────────────
    //  Tools page
    // ──────────────────────────────────────────────

    /**
     * Why this site cannot run one of the add-on's groups ('' when it can),
     * or null for a group that is not the add-on's.
     */
    public function unavailableReason(string $group): ?string
    {
        return null;
    }

    /**
     * A group's own settings on the Tools page, or null.
     *
     * @return array<string, mixed>|null
     */
    public function groupOptions(string $group): ?array
    {
        return null;
    }

    /**
     * Option names POST /tools accepts for the add-on (each a boolean).
     *
     * @return string[]
     */
    public function optionNames(): array
    {
        return [];
    }

    public function updateOption(string $name, bool $value): void
    {
    }

    /**
     * The items of a tool with an item list (`items` in tools()), or null.
     *
     * @return array<int, array{name: string, label: string, kind: string, approval: bool, enabled: bool}>|null
     */
    public function items(string $tool): ?array
    {
        return null;
    }

    /**
     * Save item changes (name => bool, every name one of items()).
     *
     * @param array<string, bool> $changes
     */
    public function updateItems(string $tool, array $changes): void
    {
    }

    /**
     * Facts the setup wizard shows (OnboardingController `features`).
     *
     * @return array<string, mixed>
     */
    public function setupFacts(): array
    {
        return [];
    }

    /**
     * Keys this feature adds to the admin app's `SERVER_VARIABLES`
     * (Head::createFrontendConfig()), such as MCPMate Pro's `chat`. A key
     * the free plugin already sets is never replaced. No secrets.
     *
     * @return array<string, mixed>
     */
    public function adminConfig(): array
    {
        return [];
    }
}
// phpcs:enable

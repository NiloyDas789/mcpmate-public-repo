<?php

namespace MCPMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;
use MCPMate\Extensions\ProUpsell;
use MCPMate\Services\Abilities\AbilityRisk;

/**
 * The policy layer (the cloud's src/guard/policy.ts): before any tool runs,
 * its call is classified as read, write or destructive, and known-dangerous
 * calls are refused here, whatever the model asks for. The routes enforce
 * their own allowlists again; neither layer relies on the other (rules 1–3
 * and 6).
 *
 * Destructive calls go ahead only because their route holds every one of
 * them for a site admin's approval (unless it is a dry run). Anything the
 * approval gate does not cover and that could take over or wreck a site
 * (site URLs, users and roles, PHP, SQL, permanent deletes, publishing) is
 * refused outright. Pure apart from the registered features: MCPMate Pro
 * adds its tools (Feature::policy(), classified per call by its ProPolicy)
 * and its write and destructive WP-CLI commands (Feature::cliCommands()).
 */
final class Policy
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const DESTRUCTIVE = 'destructive';

    /** Classified per call. */
    public const PER_CALL = 'call';

    /**
     * The plugin's CLI allowlist with its risk levels: reads only
     * (CommandRegistry::defaults(); CommandRegistryTest keeps them equal).
     * MCPMate Pro adds its commands (cliCommands()).
     *
     * @var array<string, string>
     */
    public const CLI_COMMANDS = [
        'core version'          => self::READ,
        'core verify-checksums' => self::READ,
        'plugin list'           => self::READ,
        'plugin get'            => self::READ,
        'theme list'            => self::READ,
        'theme get'             => self::READ,
        'post list'             => self::READ,
        'post get'              => self::READ,
        'post meta get'         => self::READ,
        'post-type list'        => self::READ,
        'taxonomy list'         => self::READ,
        'term list'             => self::READ,
        'term get'              => self::READ,
        'menu list'             => self::READ,
        'menu item list'        => self::READ,
        'option get'            => self::READ,
        'option list'           => self::READ,
        'transient get'         => self::READ,
        'user list'             => self::READ,
        'user get'              => self::READ,
        'role list'             => self::READ,
        'comment list'          => self::READ,
        'cron event list'       => self::READ,
        'cron schedule list'    => self::READ,
        'rewrite list'          => self::READ,
        'site list'             => self::READ,
    ];

    /**
     * Every tool and its class; `call` means classified per call (classify()).
     *
     * @var array<string, string>
     */
    private const TOOLS = [
        'ping'                => self::READ,
        'list_skills'         => self::READ,
        'get_skill'           => self::READ,
        'save_skill'          => self::WRITE,
        'site_info'           => self::READ,
        'get_page_html'       => self::READ,
        'get_audit_log'       => self::READ,
        'query_db'            => self::READ,
        'check_approval'      => self::READ,
        'search_stock_photos' => self::READ,
        'run_wp_cli'          => self::PER_CALL,
        'list_abilities'      => self::READ,
        'run_ability'         => self::PER_CALL,
        'list_block_types'    => self::READ,
        'validate_blocks'     => self::READ,
    ];

    /** Classified per call, but never as a read. */
    private const NEVER_READ = ['run_ability'];

    /**
     * Options that change site URLs, users, roles, plugins or themes
     * (OptionCommands::NEVER_WRITABLE). Compared case-insensitively.
     */
    private const PROTECTED_OPTIONS = [
        'active_plugins', 'admin_email', 'cron', 'default_role', 'home', 'new_admin_email',
        'recently_activated', 'siteurl', 'stylesheet', 'template', 'upload_path', 'upload_url_path', 'users_can_register',
    ];

    /** First words that run PHP, SQL or a shell, or change wp-config or packages. */
    private const CODE_EXECUTION = ['eval', 'eval-file', 'shell', 'db', 'config', 'package', 'server', 'cli', 'dist-archive', 'scaffold'];

    /** First words that manage users and roles. Only the READ_USERS_AND_ROLES commands are allowed among them. */
    private const USERS_AND_ROLES = ['user', 'role', 'cap', 'super-admin'];

    /** The read commands among USERS_AND_ROLES, as `first second`. */
    private const READ_USERS_AND_ROLES = ['user list', 'user get', 'role list'];

    /** Flags that would activate what `plugin install` installs: activation is its own approved step. */
    private const ACTIVATE_FLAGS = ['activate', 'activate-network'];

    /** Global WP-CLI flags that load PHP, or point the command at another site, install or user. */
    private const GLOBAL_FLAGS = ['exec', 'require', 'url', 'path', 'ssh', 'http', 'user', 'context'];

    /** Flags that make a removal permanent. */
    private const PERMANENT_FLAGS = ['force', 'permanent', 'delete-data'];

    private const PUBLIC_STATUSES = ['publish', 'future', 'private'];

    private const ADMIN_ONLY = 'Do this in wp-admin as an administrator, not through MCPMate.';

    public const MAX_COMMAND_WORDS = 64;

    private const SHELL_OPERATORS = ';|&<>`$';

    public static function has(string $tool): bool
    {
        return isset(self::tools()[$tool]);
    }

    /**
     * Whether some call of the tool is a read: a read-only grant lists just these.
     */
    public static function hasReadCalls(string $tool): bool
    {
        $entry = self::tools()[$tool] ?? null;
        $owner = isset(self::TOOLS[$tool]) ? null : Features::ownerOfTool($tool);

        return $entry !== null
            && $entry !== self::WRITE
            && !in_array($tool, self::NEVER_READ, true)
            && !($owner !== null && in_array($tool, $owner->neverRead(), true));
    }

    /**
     * @return string[] The CLI commands of one class, in allowlist order.
     */
    public static function cliCommandsOf(string $class): array
    {
        return array_keys(array_filter(self::cliCommands(), static fn (string $risk): bool => $risk === $class));
    }

    /**
     * The call's class, and why it is refused when it is.
     *
     * @param array<string, mixed> $args
     * @return array{allowed: bool, class: string, approval?: bool, reason?: string, hint?: string}
     */
    public static function classify(string $tool, array $args): array
    {
        $entry = self::tools()[$tool] ?? null;

        if ($entry === null) {
            return self::unknown($tool);
        }

        if ($entry !== self::PER_CALL) {
            return ['allowed' => true, 'class' => $entry, 'approval' => false];
        }

        switch ($tool) {
            case 'run_wp_cli':
                return self::classifyCliCommand(is_string($args['command'] ?? null) ? $args['command'] : '', ($args['dry_run'] ?? null) === true);
            case 'run_ability':
                return self::classifyAbility(is_string($args['name'] ?? null) ? $args['name'] : '');
            default:
                // MCPMate Pro's per-call tools are classified by their own feature.
                $owner    = Features::ownerOfTool($tool);
                $decision = $owner !== null ? $owner->classify($tool, $args) : null;

                return $decision ?? self::unknown($tool);
        }
    }

    /**
     * Destructive (held for approval by the route) when the name says so,
     * otherwise a write: an ability's readonly annotation is only a claim.
     *
     * @return array{allowed: bool, class: string, approval: bool}
     */
    public static function classifyAbility(string $name): array
    {
        $lower = strtolower($name);

        foreach (AbilityRisk::DESTRUCTIVE_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return ['allowed' => true, 'class' => self::DESTRUCTIVE, 'approval' => true];
            }
        }

        return ['allowed' => true, 'class' => self::WRITE, 'approval' => false];
    }

    // ──────────────────────────────────────────────
    //  WP-CLI commands
    // ──────────────────────────────────────────────

    /**
     * A run_wp_cli call: the command's class, or why it is refused.
     *
     * @return array{allowed: bool, class: string, approval?: bool, reason?: string, hint?: string}
     */
    public static function classifyCliCommand(string $command, bool $dryRun): array
    {
        $parsed = self::tokenize($command);

        if (is_string($parsed)) {
            return self::refuse(self::DESTRUCTIVE, $parsed, 'Send exactly one command on a single line, with no shell syntax. Quote values that contain these characters.');
        }

        $words = ($parsed[0] ?? '') === 'wp' ? array_slice($parsed, 1) : $parsed;
        [$positional, $flags] = self::bind($words);

        $lowered = array_map('strtolower', array_slice($positional, 0, 3));
        $first   = $lowered[0] ?? '';
        $second  = $lowered[1] ?? '';
        $known   = self::matchCommand($words);
        $class   = $known === null ? self::DESTRUCTIVE : self::cliCommands()[$known];

        foreach (array_keys($flags) as $flag) {
            if (in_array(strtolower((string) $flag), self::GLOBAL_FLAGS, true)) {
                return self::refuse(
                    self::DESTRUCTIVE,
                    '--' . Compact::oneLine((string) $flag, 40) . ' is not allowed: it loads PHP or points the command at another site or user.',
                    'Leave out global WP-CLI flags.'
                );
            }
        }

        if (in_array($first, self::CODE_EXECUTION, true)) {
            return self::refuse(self::DESTRUCTIVE, '"' . Compact::oneLine($first, 40) . '" would run code, SQL or a shell, or change wp-config. MCPMate never does that.', self::ADMIN_ONLY);
        }

        if (in_array($first, self::USERS_AND_ROLES, true) && !in_array($first . ' ' . $second, self::READ_USERS_AND_ROLES, true)) {
            return self::refuse(
                self::DESTRUCTIVE,
                'Users and roles cannot be created, changed or deleted through MCPMate. Only "user list", "user get" and "role list" are available.',
                self::ADMIN_ONLY
            );
        }

        if ($second === 'delete' && in_array($first, ['post', 'comment', 'term', 'site', 'media', 'menu', 'widget'], true)) {
            return self::refuse(self::DESTRUCTIVE, 'Content cannot be deleted through MCPMate.', self::ADMIN_ONLY);
        }

        if ($first === 'site' && $second === 'empty') {
            return self::refuse(self::DESTRUCTIVE, 'A site cannot be emptied through MCPMate.', self::ADMIN_ONLY);
        }

        // `plugin install` (MCPMate Pro) installs from WordPress.org only, after approval, and never activates.
        if ($first === 'plugin' && $second === 'install') {
            foreach (array_keys($flags) as $flag) {
                if (in_array(strtolower((string) $flag), self::ACTIVATE_FLAGS, true)) {
                    return self::refuse(
                        self::DESTRUCTIVE,
                        '--' . strtolower((string) $flag) . ' is not allowed: installing and activating a plugin are separate steps, each approved on its own.',
                        'Install without --activate; once it is installed, ask for "plugin activate <slug>".'
                    );
                }
            }
        } elseif (in_array($first, ['plugin', 'theme', 'language'], true) && in_array($second, ['install', 'delete', 'uninstall'], true)) {
            return self::refuse(
                self::DESTRUCTIVE,
                'Plugins, themes and languages cannot be removed through MCPMate, and only plugins can be installed (MCPMate Pro, from WordPress.org, with approval).',
                self::ADMIN_ONLY
            );
        }

        if ($first === 'core' && !in_array($second, ['version', 'verify-checksums'], true)) {
            return self::refuse(self::DESTRUCTIVE, 'WordPress core cannot be installed, updated or changed through MCPMate.', self::ADMIN_ONLY);
        }

        if ($first === 'option' && $second !== 'get' && $second !== 'list') {
            // option update <key> <value>: the key is the first argument after the name.
            $key = $positional[2] ?? null;

            if ($key !== null && self::isProtectedOption($key)) {
                return self::refuse(
                    self::DESTRUCTIVE,
                    'The option "' . Compact::oneLine($key, 60) . '" changes site URLs, users, roles, plugins or themes, so MCPMate never changes it.',
                    self::ADMIN_ONLY
                );
            }
        }

        if ($class !== self::READ) {
            foreach ($flags as $flag => $value) {
                $name = strtolower((string) $flag);

                if (in_array($name, self::PERMANENT_FLAGS, true) && $value !== 'false') {
                    return self::refuse($class, '--' . $name . ' is not allowed: MCPMate never removes anything permanently.', 'Leave out --force; removals go to the trash.');
                }

                if (($name === 'post_status' || $name === 'status') && $value !== null && in_array(strtolower($value), self::PUBLIC_STATUSES, true)) {
                    return self::refuse($class, 'MCPMate never publishes or schedules content: it stays a draft or pending review.', 'Use draft or pending, and ask the user to publish in wp-admin.');
                }
            }
        }

        if ($known === null) {
            $pro = ProUpsell::proCommand($lowered);

            if ($pro !== null) {
                // A command MCPMate Pro runs: say so, so the app can tell the user what to do.
                return self::refuse(self::cliCommands()[$pro] ?? self::DESTRUCTIVE, ProUpsell::message('wp ' . $pro), ProUpsell::hint());
            }

            $named = implode(' ', $lowered);

            return self::refuse(
                self::DESTRUCTIVE,
                '"' . Compact::oneLine($named !== '' ? $named : $command, 60) . '" is not a command MCPMate can run.',
                'Available: ' . implode(', ', array_keys(self::cliCommands())) . '.'
            );
        }

        return ['allowed' => true, 'class' => $class, 'approval' => $class === self::DESTRUCTIVE && !$dryRun];
    }

    /**
     * Splits a command into words exactly like CommandParser::tokenize():
     * 'single quotes' are literal, "double quotes" allow \" and \\, a
     * backslash outside quotes escapes the next character, and unquoted
     * shell operators are an error.
     *
     * @return string[]|string The words, or why the command is refused.
     */
    public static function tokenize(string $input)
    {
        if (!mb_check_encoding($input, 'UTF-8')) {
            return 'The command is not valid UTF-8.';
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $input)) {
            return 'The command contains a line break or control character.';
        }

        $chars  = (array) preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        $count  = count($chars);
        $words  = [];
        $word   = '';
        $inWord = false;
        $quote  = '';

        for ($i = 0; $i < $count; $i++) {
            $char = (string) $chars[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = '';
                } else {
                    $word .= $char;
                }
                continue;
            }

            if ($quote === '"') {
                $next = (string) ($chars[$i + 1] ?? '');

                if ($char === '\\' && ($next === '"' || $next === '\\')) {
                    $word .= $next;
                    $i++;
                } elseif ($char === '"') {
                    $quote = '';
                } else {
                    $word .= $char;
                }
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inWord) {
                    $words[] = $word;
                    $word    = '';
                    $inWord  = false;
                }
                continue;
            }

            $inWord = true;

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '\\') {
                if ($i + 1 >= $count) {
                    return 'The command ends with a lone backslash.';
                }

                $word .= (string) $chars[++$i];
                continue;
            }

            if (str_contains(self::SHELL_OPERATORS, $char)) {
                return 'Shell operators such as "' . $char . '" are not supported: one command per call.';
            }

            $word .= $char;
        }

        if ($quote !== '') {
            return 'The command has an unterminated quote.';
        }

        if ($inWord) {
            $words[] = $word;
        }

        if (count($words) > self::MAX_COMMAND_WORDS) {
            return 'The command has more than ' . self::MAX_COMMAND_WORDS . ' words.';
        }

        if ($words === [] || $words === ['wp']) {
            return 'The command is empty.';
        }

        return $words;
    }

    /**
     * Longest registered name (up to three words) at the start, matched exactly.
     *
     * @param string[] $words
     */
    private static function matchCommand(array $words): ?string
    {
        $leading = [];

        foreach (array_slice($words, 0, 3) as $word) {
            if ($word === '' || str_starts_with($word, '-')) {
                break;
            }

            $leading[] = $word;
        }

        for ($n = count($leading); $n > 0; $n--) {
            $name = implode(' ', array_slice($leading, 0, $n));

            if (isset(self::cliCommands()[$name])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Positional words and `--name[=value]` flags, split like CommandParser::bind().
     *
     * @param string[] $words
     * @return array{0: string[], 1: array<string, string|null>}
     */
    private static function bind(array $words): array
    {
        $positional = [];
        $flags      = [];
        $endOfFlags = false;

        foreach ($words as $word) {
            if ($endOfFlags || $word === '' || $word === '-' || !str_starts_with($word, '-')) {
                $positional[] = $word;
            } elseif ($word === '--') {
                $endOfFlags = true;
            } else {
                $body = (string) preg_replace('/^--?/', '', $word);
                $eq   = strpos($body, '=');

                $flags[$eq === false ? $body : (string) substr($body, 0, $eq)] = $eq === false ? null : (string) substr($body, $eq + 1);
            }
        }

        return [$positional, $flags];
    }

    private static function isProtectedOption(string $name): bool
    {
        $lower = strtolower($name);

        // auto_update_* are the auto-update settings (plugins, themes, core); <prefix>user_roles holds every role's
        // capabilities; mcpmate_* is the plugin's own settings.
        return in_array($lower, self::PROTECTED_OPTIONS, true)
            || str_starts_with($lower, 'auto_update_')
            || str_ends_with($lower, 'user_roles')
            || str_starts_with($lower, 'mcpmate_');
    }

    /**
     * The plugin's tools and MCPMate Pro's (a feature never reclassifies one).
     *
     * @return array<string, string>
     */
    private static function tools(): array
    {
        $tools = self::TOOLS;

        foreach (Features::policy() as $tool => $class) {
            if (!isset($tools[$tool]) && in_array($class, [self::READ, self::WRITE, self::DESTRUCTIVE, self::PER_CALL], true)) {
                $tools[$tool] = $class;
            }
        }

        return $tools;
    }

    /**
     * The CLI allowlist: the plugin's reads and MCPMate Pro's commands (never a reclassified read).
     *
     * @return array<string, string>
     */
    public static function cliCommands(): array
    {
        $commands = self::CLI_COMMANDS;

        foreach (Features::all() as $feature) {
            foreach ($feature->cliCommands() as $name => $risk) {
                if (!isset($commands[$name]) && in_array($risk, [self::READ, self::WRITE, self::DESTRUCTIVE], true)) {
                    $commands[$name] = $risk;
                }
            }
        }

        return $commands;
    }

    /**
     * @return array{allowed: false, class: string, reason: string, hint: string}
     */
    private static function unknown(string $tool): array
    {
        return self::refuse(self::DESTRUCTIVE, '"' . Compact::oneLine($tool, 60) . '" is not a tool this server runs.', 'Use one of the listed tools.');
    }

    /**
     * @return array{allowed: false, class: string, reason: string, hint: string}
     */
    private static function refuse(string $class, string $reason, string $hint): array
    {
        return ['allowed' => false, 'class' => $class, 'reason' => $reason, 'hint' => $hint];
    }
}

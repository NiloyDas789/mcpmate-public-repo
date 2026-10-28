<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One registered WP-CLI-style command: its name, argument schema, risk
 * level, dry-run support, required capability and PHP handler.
 *
 * Spec keys:
 *
 *   summary          string  Shown to admins in Settings.
 *   risk             string  read | write | destructive. Destructive commands
 *                            wait for admin approval unless run as a dry run.
 *   capability       string  Checked with current_user_can() before the handler runs.
 *   supports_dry_run bool    Default false. Destructive commands must support it.
 *   dry_run_default  bool    Dry run when the caller does not say (default false).
 *   args             list    Positional arguments, in order: [name, type, required, …].
 *                            Only the last one may be `variadic`.
 *   flags            map     `--name` => schema. Types: string, integer, boolean, list.
 *   target           array   [type, arg] for the audit-log target, e.g. ['post', 'id'].
 *   handler          callable(ParsedCommand $command, bool $dryRun): array
 *
 * Value schema keys: type (string|integer|boolean|list), required, default,
 * enum, pattern (full PCRE with delimiters), max_length, minimum, maximum,
 * max_items (list and variadic), variadic (positional only).
 */
final class Command
{
    public const RISK_READ = 'read';

    public const RISK_WRITE = 'write';

    public const RISK_DESTRUCTIVE = 'destructive';

    public const RISKS = [self::RISK_READ, self::RISK_WRITE, self::RISK_DESTRUCTIVE];

    public const TYPES = ['string', 'integer', 'boolean', 'list'];

    /** Longest value a string argument may have, unless its schema says otherwise. */
    public const MAX_LENGTH = 1000;

    /** Accepted by every command, for WP-CLI compatibility. Output is always JSON. */
    public const GLOBAL_FLAGS = [
        'format' => ['type' => 'string', 'enum' => ['json'], 'default' => 'json'],
    ];

    public const DRY_RUN_FLAG = 'dry-run';

    private string $name;

    private string $summary;

    private string $risk;

    private string $capability;

    private bool $supportsDryRun;

    private bool $dryRunDefault;

    /** @var array<int, array<string, mixed>> */
    private array $args;

    /** @var array<string, array<string, mixed>> */
    private array $flags;

    /** @var array{0: string, 1: string}|null */
    private ?array $target;

    /** @var callable */
    private $handler;

    /**
     * @param array<string, mixed> $spec See the class comment.
     * @throws \InvalidArgumentException When the spec is incomplete or inconsistent.
     */
    public function __construct(string $name, array $spec)
    {
        if (!preg_match('/^[a-z][a-z0-9-]*( [a-z][a-z0-9-]*){0,2}$/D', $name)) {
            throw new \InvalidArgumentException('Invalid command name: ' . esc_html($name));
        }

        $risk = $spec['risk'] ?? '';
        if (!in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException(esc_html($name) . ': unknown risk level.');
        }

        if (empty($spec['capability']) || !is_string($spec['capability'])) {
            throw new \InvalidArgumentException(esc_html($name) . ': a capability is required.');
        }

        if (!isset($spec['handler']) || !is_callable($spec['handler'])) {
            throw new \InvalidArgumentException(esc_html($name) . ': a callable handler is required.');
        }

        $supportsDryRun = !empty($spec['supports_dry_run']);
        if ($risk === self::RISK_DESTRUCTIVE && !$supportsDryRun) {
            throw new \InvalidArgumentException(esc_html($name) . ': destructive commands must support dry runs.');
        }

        $this->name           = $name;
        $this->summary        = (string) ($spec['summary'] ?? '');
        $this->risk           = $risk;
        $this->capability     = $spec['capability'];
        $this->supportsDryRun = $supportsDryRun;
        $this->dryRunDefault  = $supportsDryRun && !empty($spec['dry_run_default']);
        $this->args           = self::checkArgs($name, $spec['args'] ?? []);
        $this->flags          = self::checkFlags($name, $spec['flags'] ?? [], $supportsDryRun);
        $this->target         = isset($spec['target']) ? array_values($spec['target']) : null;
        $this->handler        = $spec['handler'];
    }

    // ──────────────────────────────────────────────
    //  Definition
    // ──────────────────────────────────────────────

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function risk(): string
    {
        return $this->risk;
    }

    public function isDestructive(): bool
    {
        return $this->risk === self::RISK_DESTRUCTIVE;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function supportsDryRun(): bool
    {
        return $this->supportsDryRun;
    }

    public function dryRunDefault(): bool
    {
        return $this->dryRunDefault;
    }

    /**
     * Positional argument schemas, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * Flag schemas keyed by name, including GLOBAL_FLAGS and `dry-run`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * Public description, for the admin settings screen.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'name'             => $this->name,
            'summary'          => $this->summary,
            'risk'             => $this->risk,
            'capability'       => $this->capability,
            'supports_dry_run' => $this->supportsDryRun,
            'dry_run_default'  => $this->dryRunDefault,
            'usage'            => $this->usage(),
        ];
    }

    /**
     * Synopsis, e.g. `option get <key>` or `plugin activate <plugin>...`.
     */
    public function usage(): string
    {
        $parts = [$this->name];

        foreach ($this->args as $arg) {
            $part    = '<' . $arg['name'] . '>' . (!empty($arg['variadic']) ? '...' : '');
            $parts[] = !empty($arg['required']) ? $part : '[' . $part . ']';
        }

        foreach ($this->flags as $flag => $schema) {
            if (isset(self::GLOBAL_FLAGS[$flag])) {
                continue;
            }

            $parts[] = $schema['type'] === 'boolean' ? '[--' . $flag . ']' : '[--' . $flag . '=<' . $schema['type'] . '>]';
        }

        return implode(' ', $parts);
    }

    // ──────────────────────────────────────────────
    //  Execution
    // ──────────────────────────────────────────────

    /**
     * Audit-log target for a parsed call, e.g. `post:12` or `plugin:akismet`.
     *
     * @return array{0: string, 1: string}|null
     */
    public function auditTarget(ParsedCommand $command): ?array
    {
        if ($this->target === null) {
            return null;
        }

        $value = $command->arg($this->target[1]);
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        return $value === null || $value === '' ? null : [$this->target[0], (string) $value];
    }

    /**
     * Run the handler. Capability, allowlist and approval checks are the caller's job.
     *
     * @return array<string, mixed>|array<int, mixed>
     * @throws CommandException When the handler rejects the call.
     */
    public function run(ParsedCommand $command, bool $dryRun): array
    {
        return call_user_func($this->handler, $command, $dryRun);
    }

    // ──────────────────────────────────────────────
    //  Spec checks
    // ──────────────────────────────────────────────

    /**
     * @param mixed $args
     * @return array<int, array<string, mixed>>
     */
    private static function checkArgs(string $command, $args): array
    {
        if (!is_array($args)) {
            throw new \InvalidArgumentException(esc_html($command) . ': args must be a list.');
        }

        $args  = array_values($args);
        $names = [];

        foreach ($args as $i => $arg) {
            if (empty($arg['name']) || isset($names[$arg['name']])) {
                throw new \InvalidArgumentException(esc_html($command) . ': every argument needs a unique name.');
            }

            if (!empty($arg['variadic']) && $i !== count($args) - 1) {
                throw new \InvalidArgumentException(esc_html($command) . ': only the last argument may be variadic.');
            }

            if (($arg['type'] ?? 'string') === 'boolean') {
                throw new \InvalidArgumentException(esc_html($command) . ': positional arguments cannot be boolean.');
            }

            $names[$arg['name']] = true;
            $args[$i]            = self::checkSchema($command, $arg);
        }

        return $args;
    }

    /**
     * @param mixed $flags
     * @return array<string, array<string, mixed>>
     */
    private static function checkFlags(string $command, $flags, bool $supportsDryRun): array
    {
        if (!is_array($flags)) {
            throw new \InvalidArgumentException(esc_html($command) . ': flags must be a map.');
        }

        foreach ($flags as $flag => $schema) {
            if (!is_string($flag) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $flag) || strpos($flag, 'no-') === 0) {
                throw new \InvalidArgumentException(esc_html($command) . ': invalid flag name.');
            }

            if (isset(self::GLOBAL_FLAGS[$flag]) || $flag === self::DRY_RUN_FLAG) {
                throw new \InvalidArgumentException(esc_html($command) . ': --' . esc_html($flag) . ' is reserved.');
            }

            $flags[$flag] = self::checkSchema($command, $schema);
        }

        foreach (self::GLOBAL_FLAGS as $flag => $schema) {
            $flags[$flag] = self::checkSchema($command, $schema);
        }

        if ($supportsDryRun) {
            $flags[self::DRY_RUN_FLAG] = ['type' => 'boolean'];
        }

        return $flags;
    }

    /**
     * @param mixed $schema
     * @return array<string, mixed>
     */
    private static function checkSchema(string $command, $schema): array
    {
        if (!is_array($schema)) {
            throw new \InvalidArgumentException(esc_html($command) . ': invalid value schema.');
        }

        $schema['type'] = $schema['type'] ?? 'string';

        if (!in_array($schema['type'], self::TYPES, true)) {
            throw new \InvalidArgumentException(esc_html($command) . ': unknown type ' . esc_html($schema['type']) . '.');
        }

        if (in_array($schema['type'], ['string', 'list'], true)) {
            $schema['max_length'] = $schema['max_length'] ?? self::MAX_LENGTH;
        }

        return $schema;
    }
}

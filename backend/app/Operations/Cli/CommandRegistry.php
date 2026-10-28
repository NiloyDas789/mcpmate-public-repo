<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Commands\CacheCommands;
use McpMate\Operations\Cli\Commands\CoreCommands;
use McpMate\Operations\Cli\Commands\CronCommands;
use McpMate\Operations\Cli\Commands\OptionCommands;
use McpMate\Operations\Cli\Commands\PluginCommands;
use McpMate\Operations\Cli\Commands\PostCommands;
use McpMate\Operations\Cli\Commands\SearchReplaceCommand;
use McpMate\Operations\Cli\Commands\ThemeCommands;
use McpMate\Operations\Cli\Commands\UserCommands;

/**
 * The allowlist of WP-CLI-style commands POST /cli may run.
 *
 * Default-deny: a name that is not registered here is rejected by the
 * parser before anything runs. There is no filter for adding commands;
 * each one is reviewed code with its own schema, risk level and capability.
 * Registered commands still start disabled until a site admin enables them
 * (CommandSettings).
 */
final class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * The built-in command set.
     */
    public static function defaults(): self
    {
        $registry = new self();

        CoreCommands::register($registry);
        PluginCommands::register($registry);
        ThemeCommands::register($registry);
        OptionCommands::register($registry);
        UserCommands::register($registry);
        PostCommands::register($registry);
        CacheCommands::register($registry);
        CronCommands::register($registry);
        SearchReplaceCommand::register($registry);

        return $registry;
    }

    /**
     * @param array<string, mixed> $spec See Command.
     * @throws \InvalidArgumentException When $name is taken or the spec is invalid.
     */
    public function add(string $name, array $spec): Command
    {
        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException('Command already registered: ' . esc_html($name));
        }

        $this->commands[$name] = new Command($name, $spec);

        return $this->commands[$name];
    }

    public function get(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Registered command names, sorted.
     *
     * @return string[]
     */
    public function names(): array
    {
        $names = array_keys($this->commands);
        sort($names);

        return $names;
    }

    /**
     * @return array<string, Command>
     */
    public function all(): array
    {
        $commands = $this->commands;
        ksort($commands);

        return $commands;
    }
}

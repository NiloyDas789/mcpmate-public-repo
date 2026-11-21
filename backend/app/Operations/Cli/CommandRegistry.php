<?php

namespace MCPMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;
use MCPMate\Operations\Cli\Commands\CommentCommands;
use MCPMate\Operations\Cli\Commands\ContentTypeCommands;
use MCPMate\Operations\Cli\Commands\CoreCommands;
use MCPMate\Operations\Cli\Commands\CronCommands;
use MCPMate\Operations\Cli\Commands\MenuCommands;
use MCPMate\Operations\Cli\Commands\OptionCommands;
use MCPMate\Operations\Cli\Commands\PluginCommands;
use MCPMate\Operations\Cli\Commands\PostCommands;
use MCPMate\Operations\Cli\Commands\RewriteCommands;
use MCPMate\Operations\Cli\Commands\SiteCommands;
use MCPMate\Operations\Cli\Commands\TermCommands;
use MCPMate\Operations\Cli\Commands\ThemeCommands;
use MCPMate\Operations\Cli\Commands\TransientCommands;
use MCPMate\Operations\Cli\Commands\UserCommands;

/**
 * The allowlist of WP-CLI-style commands POST /cli may run.
 *
 * Default-deny: a name that is not registered here is rejected by the
 * parser before anything runs. There is no filter for adding commands;
 * each one is reviewed code with its own schema, risk level and capability.
 * The plugin's own commands only read; MCPMate Pro adds the write and
 * destructive ones through Features (Feature::registerCommands()).
 * Registered commands still start disabled until a site admin enables them
 * (CommandSettings).
 */
final class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * The built-in read commands, and MCPMate Pro's.
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
        CronCommands::register($registry);
        TermCommands::register($registry);
        MenuCommands::register($registry);
        ContentTypeCommands::register($registry);
        RewriteCommands::register($registry);
        TransientCommands::register($registry);
        SiteCommands::register($registry);
        CommentCommands::register($registry);

        foreach (Features::all() as $feature) {
            $feature->registerCommands($registry);
        }

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

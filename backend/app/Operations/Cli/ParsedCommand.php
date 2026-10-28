<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A command string after CommandParser: the registered Command plus its
 * validated positional arguments and flags. Every value has already passed
 * its schema; integers are ints, booleans are bools, lists are string arrays.
 */
final class ParsedCommand
{
    private Command $command;

    /** @var array<string, mixed> */
    private array $args;

    /** @var array<string, mixed> */
    private array $flags;

    /** @var array<string, true> Flags the caller wrote (not filled from a default). */
    private array $given;

    /**
     * @param array<string, mixed> $args  Positional arguments by name.
     * @param array<string, mixed> $flags Flags by name, defaults included.
     * @param string[]             $given Flags present in the input.
     */
    public function __construct(Command $command, array $args, array $flags, array $given)
    {
        $this->command = $command;
        $this->args    = $args;
        $this->flags   = $flags;
        $this->given   = array_fill_keys($given, true);
    }

    public function command(): Command
    {
        return $this->command;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function arg(string $name, $default = null)
    {
        return $this->args[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function flag(string $name, $default = null)
    {
        return $this->flags[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * Whether the caller wrote --$name (as opposed to it taking its default).
     */
    public function hasFlag(string $name): bool
    {
        return isset($this->given[$name]);
    }
}

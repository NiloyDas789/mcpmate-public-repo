<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a WP-CLI-style string such as `option get blogname --format=json`
 * into a ParsedCommand, validated against the command's schema.
 *
 * Nothing is ever handed to a shell. The syntax is a strict subset of POSIX
 * shell words: whitespace separates words, 'single quotes' are literal,
 * "double quotes" allow \" and \\, and a backslash outside quotes escapes
 * the next character. Unquoted shell operators (; | & < > ` $) are rejected,
 * so a string that looks like a pipeline or substitution fails loudly
 * instead of being read as literal text.
 *
 * Flags are `--name=value`, a bare `--name` (boolean true) or `--no-name`
 * (boolean false). `--` ends the flags. Short options, unknown flags,
 * repeated flags and extra arguments are errors. A leading `wp` is ignored.
 */
final class CommandParser
{
    /** Longest accepted input, in bytes. */
    public const MAX_LENGTH = 4096;

    public const MAX_WORDS = 64;

    /** Rejected outside quotes. */
    private const SHELL_OPERATORS = ';|&<>`$';

    /**
     * @throws CommandException When the input cannot be parsed, names no
     *                          registered command, or fails its schema.
     */
    public static function parse(string $input, CommandRegistry $registry): ParsedCommand
    {
        $words = self::tokenize($input);

        if ($words !== [] && $words[0] === 'wp') {
            array_shift($words);
        }

        if ($words === []) {
            throw new CommandException(
                esc_html__('The command is empty.', 'mcpmate'),
                esc_html(CommandException::PARSE_ERROR),
                400,
                esc_html__('Send a command such as "plugin list".', 'mcpmate')
            );
        }

        $command = self::matchCommand($words, $registry);
        $nameLen = substr_count($command->name(), ' ') + 1;

        return self::bind($command, array_slice($words, $nameLen));
    }

    /**
     * Split $input into words.
     *
     * @return string[]
     * @throws CommandException
     */
    public static function tokenize(string $input): array
    {
        if (strlen($input) > self::MAX_LENGTH) {
            throw self::parseError(sprintf(
                /* translators: %d: maximum length in bytes. */
                esc_html__('The command is longer than %d bytes.', 'mcpmate'),
                (int) self::MAX_LENGTH
            ));
        }

        if (!preg_match('//u', $input)) {
            throw self::parseError(esc_html__('The command is not valid UTF-8.', 'mcpmate'));
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $input)) {
            throw self::parseError(
                esc_html__('The command contains a line break or control character.', 'mcpmate'),
                esc_html__('Send exactly one command on a single line.', 'mcpmate')
            );
        }

        $words   = [];
        $word    = '';
        $inWord  = false;
        $quote   = '';
        $length  = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = '';
                } else {
                    $word .= $char;
                }
                continue;
            }

            if ($quote === '"') {
                if ($char === '\\' && $i + 1 < $length && ($input[$i + 1] === '"' || $input[$i + 1] === '\\')) {
                    $word .= $input[++$i];
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
                if ($i + 1 >= $length) {
                    throw self::parseError(esc_html__('The command ends with a lone backslash.', 'mcpmate'));
                }
                $word .= $input[++$i];
                continue;
            }

            if (strpos(self::SHELL_OPERATORS, $char) !== false) {
                throw self::parseError(
                    sprintf(
                        /* translators: %s: a shell operator character such as ; or |. */
                        esc_html__('Shell operators such as "%s" are not supported.', 'mcpmate'),
                        esc_html($char)
                    ),
                    esc_html__('Run one command per request. Quote values that contain these characters.', 'mcpmate')
                );
            }

            $word .= $char;
        }

        if ($quote !== '') {
            throw self::parseError(esc_html__('The command has an unterminated quote.', 'mcpmate'));
        }

        if ($inWord) {
            $words[] = $word;
        }

        if (count($words) > self::MAX_WORDS) {
            throw self::parseError(sprintf(
                /* translators: %d: maximum number of words. */
                esc_html__('The command has more than %d words.', 'mcpmate'),
                (int) self::MAX_WORDS
            ));
        }

        return $words;
    }

    // ──────────────────────────────────────────────
    //  Command name
    // ──────────────────────────────────────────────

    /**
     * Longest registered name (up to three words) at the start of $words.
     *
     * @param string[] $words
     */
    private static function matchCommand(array $words, CommandRegistry $registry): Command
    {
        $leading = [];
        foreach (array_slice($words, 0, 3) as $word) {
            if ($word === '' || $word[0] === '-') {
                break;
            }
            $leading[] = $word;
        }

        for ($n = count($leading); $n > 0; $n--) {
            $command = $registry->get(implode(' ', array_slice($leading, 0, $n)));
            if ($command !== null) {
                return $command;
            }
        }

        $attempt = $leading !== [] ? implode(' ', $leading) : $words[0];

        throw new CommandException(
            sprintf(
                /* translators: %s: the command the caller sent, e.g. "db query". */
                esc_html__('"%s" is not an allowed command.', 'mcpmate'),
                esc_html(self::preview($attempt))
            ),
            esc_html(CommandException::UNKNOWN_COMMAND),
            400,
            sprintf(
                /* translators: %s: comma-separated list of command names. */
                esc_html__('Allowed commands: %s.', 'mcpmate'),
                esc_html(implode(', ', $registry->names()))
            )
        );
    }

    // ──────────────────────────────────────────────
    //  Arguments and flags
    // ──────────────────────────────────────────────

    /**
     * @param string[] $words Everything after the command name.
     */
    private static function bind(Command $command, array $words): ParsedCommand
    {
        $schemas    = $command->flags();
        $positional = [];
        $flags      = [];
        $given      = [];
        $endOfFlags = false;

        foreach ($words as $word) {
            if ($endOfFlags || $word === '' || $word === '-' || $word[0] !== '-') {
                $positional[] = $word;
                continue;
            }

            if ($word === '--') {
                $endOfFlags = true;
                continue;
            }

            if ($word[1] !== '-') {
                throw self::invalid(
                    sprintf(
                        /* translators: %s: the option the caller sent, e.g. -f. */
                        esc_html__('Short options such as "%s" are not supported.', 'mcpmate'),
                        esc_html(self::preview($word))
                    ),
                    esc_html__('Use the long form, e.g. --format=json. Put "--" before an argument that starts with "-".', 'mcpmate')
                );
            }

            $eq    = strpos($word, '=');
            // PHP 7.4's substr() returns false for an empty tail (`--flag=`).
            $name  = $eq === false ? (string) substr($word, 2) : (string) substr($word, 2, $eq - 2);
            $value = $eq === false ? null : (string) substr($word, $eq + 1);

            if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
                throw self::invalid(sprintf(
                    /* translators: %s: the flag the caller sent. */
                    esc_html__('"%s" is not a valid flag.', 'mcpmate'),
                    esc_html(self::preview($word))
                ), esc_html(self::usageHint($command)));
            }

            $negated = false;
            if (!isset($schemas[$name]) && strpos($name, 'no-') === 0 && $value === null) {
                $base = (string) substr($name, 3);
                if (isset($schemas[$base]) && $schemas[$base]['type'] === 'boolean') {
                    $name    = $base;
                    $negated = true;
                }
            }

            if (!isset($schemas[$name])) {
                throw self::invalid(
                    sprintf(
                        /* translators: 1: flag name, 2: command name. */
                        esc_html__('Unknown flag --%1$s for "%2$s".', 'mcpmate'),
                        esc_html($name),
                        esc_html($command->name())
                    ),
                    esc_html(self::usageHint($command))
                );
            }

            if (isset($flags[$name])) {
                throw self::invalid(sprintf(
                    /* translators: %s: flag name. */
                    esc_html__('--%s is given more than once.', 'mcpmate'),
                    esc_html($name)
                ), esc_html(self::usageHint($command)));
            }

            $schema = $schemas[$name];

            if ($schema['type'] === 'boolean') {
                if ($value !== null) {
                    throw self::invalid(sprintf(
                        /* translators: %s: flag name. */
                        esc_html__('--%1$s does not take a value. Write --%1$s or --no-%1$s.', 'mcpmate'),
                        esc_html($name)
                    ), esc_html(self::usageHint($command)));
                }
                $flags[$name] = !$negated;
            } else {
                if ($value === null) {
                    throw self::invalid(sprintf(
                        /* translators: %s: flag name. */
                        esc_html__('--%1$s needs a value: --%1$s=<value>.', 'mcpmate'),
                        esc_html($name)
                    ), esc_html(self::usageHint($command)));
                }
                $flags[$name] = self::value($command, '--' . $name, $value, $schema);
            }

            $given[] = $name;
        }

        foreach ($schemas as $name => $schema) {
            if (!array_key_exists($name, $flags)) {
                if (!empty($schema['required'])) {
                    throw self::invalid(sprintf(
                        /* translators: %s: flag name. */
                        esc_html__('--%s is required.', 'mcpmate'),
                        esc_html($name)
                    ), esc_html(self::usageHint($command)));
                }

                if (array_key_exists('default', $schema)) {
                    $flags[$name] = $schema['default'];
                }
            }
        }

        return new ParsedCommand($command, self::bindPositional($command, $positional), $flags, $given);
    }

    /**
     * @param string[] $values
     * @return array<string, mixed>
     */
    private static function bindPositional(Command $command, array $values): array
    {
        $args  = [];
        $specs = $command->args();

        foreach ($specs as $i => $spec) {
            $name = $spec['name'];

            if (!empty($spec['variadic'])) {
                $rest = array_slice($values, $i);

                if ($rest === [] && !empty($spec['required'])) {
                    throw self::missing(esc_html($name), esc_html(self::usageHint($command)));
                }

                $max = (int) ($spec['max_items'] ?? 20);
                if (count($rest) > $max) {
                    throw self::invalid(sprintf(
                        /* translators: 1: argument name, 2: maximum count. */
                        esc_html__('At most %2$d <%1$s> values are allowed.', 'mcpmate'),
                        esc_html($name),
                        (int) $max
                    ), esc_html(self::usageHint($command)));
                }

                if (!empty($spec['unique']) && count(array_unique($rest)) !== count($rest)) {
                    throw self::invalid(sprintf(
                        /* translators: %s: argument name. */
                        esc_html__('<%s> lists the same value twice.', 'mcpmate'),
                        esc_html($name)
                    ), esc_html(self::usageHint($command)));
                }

                $args[$name] = array_map(
                    static fn (string $value) => self::value($command, '<' . $name . '>', $value, $spec),
                    $rest
                );

                return $args;
            }

            if (!array_key_exists($i, $values)) {
                if (!empty($spec['required'])) {
                    throw self::missing(esc_html($name), esc_html(self::usageHint($command)));
                }
                continue;
            }

            $args[$name] = self::value($command, '<' . $name . '>', $values[$i], $spec);
        }

        if (count($values) > count($specs)) {
            throw self::invalid(
                sprintf(
                    /* translators: %s: command name. */
                    esc_html__('Too many arguments for "%s".', 'mcpmate'),
                    esc_html($command->name())
                ),
                esc_html(self::usageHint($command))
            );
        }

        return $args;
    }

    /**
     * Validate one value against its schema and cast it.
     *
     * @param array<string, mixed> $schema
     * @return int|string|string[]
     */
    private static function value(Command $command, string $label, string $value, array $schema)
    {
        switch ($schema['type']) {
            case 'integer':
                if (!preg_match('/^-?\d{1,15}$/D', $value)) {
                    throw self::invalid(sprintf(
                        /* translators: %s: argument or flag name. */
                        esc_html__('%s must be a whole number.', 'mcpmate'),
                        esc_html($label)
                    ), esc_html(self::usageHint($command)));
                }

                $int = (int) $value;
                if ((isset($schema['minimum']) && $int < $schema['minimum']) || (isset($schema['maximum']) && $int > $schema['maximum'])) {
                    throw self::invalid(sprintf(
                        /* translators: 1: argument or flag name, 2: minimum, 3: maximum. */
                        esc_html__('%1$s must be between %2$s and %3$s.', 'mcpmate'),
                        esc_html($label),
                        esc_html($schema['minimum'] ?? '-∞'),
                        esc_html($schema['maximum'] ?? '∞')
                    ), esc_html(self::usageHint($command)));
                }

                return $int;

            case 'list':
                $items = explode(',', $value);
                if (count($items) > (int) ($schema['max_items'] ?? 20)) {
                    throw self::invalid(sprintf(
                        /* translators: %s: flag name. */
                        esc_html__('%s lists too many values.', 'mcpmate'),
                        esc_html($label)
                    ), esc_html(self::usageHint($command)));
                }

                $list = [];
                foreach ($items as $item) {
                    $item = trim($item);
                    self::checkString($command, $label, $item, $schema);
                    $list[] = $item;
                }

                return array_values(array_unique($list));

            default:
                self::checkString($command, $label, $value, $schema);

                return $value;
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function checkString(Command $command, string $label, string $value, array $schema): void
    {
        if ($value === '') {
            throw self::invalid(sprintf(
                /* translators: %s: argument or flag name. */
                esc_html__('%s cannot be empty.', 'mcpmate'),
                esc_html($label)
            ), esc_html(self::usageHint($command)));
        }

        if (strlen($value) > (int) $schema['max_length']) {
            throw self::invalid(sprintf(
                /* translators: 1: argument or flag name, 2: maximum length in bytes. */
                esc_html__('%1$s is longer than %2$d bytes.', 'mcpmate'),
                esc_html($label),
                (int) $schema['max_length']
            ), esc_html(self::usageHint($command)));
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw self::invalid(sprintf(
                /* translators: 1: argument or flag name, 2: comma-separated allowed values. */
                esc_html__('%1$s must be one of: %2$s.', 'mcpmate'),
                esc_html($label),
                esc_html(implode(', ', $schema['enum']))
            ), esc_html(self::usageHint($command)));
        }

        if (isset($schema['pattern']) && !preg_match($schema['pattern'], $value)) {
            throw self::invalid(sprintf(
                /* translators: %s: argument or flag name. */
                esc_html__('%s has an invalid format.', 'mcpmate'),
                esc_html($label)
            ), esc_html(self::usageHint($command)));
        }
    }

    // ──────────────────────────────────────────────
    //  Errors
    // ──────────────────────────────────────────────

    private static function parseError(string $message, string $hint = ''): CommandException
    {
        return new CommandException(esc_html($message), esc_html(CommandException::PARSE_ERROR), 400, esc_html($hint));
    }

    private static function invalid(string $message, string $hint): CommandException
    {
        return new CommandException(esc_html($message), esc_html(CommandException::INVALID_ARGS), 400, esc_html($hint));
    }

    private static function missing(string $name, string $hint): CommandException
    {
        return self::invalid(sprintf(
            /* translators: %s: argument name. */
            esc_html__('Missing argument <%s>.', 'mcpmate'),
            $name
        ), $hint);
    }

    private static function usageHint(Command $command): string
    {
        /* translators: %s: command synopsis, e.g. "option get <key>". */
        return sprintf(__('Usage: %s', 'mcpmate'), $command->usage());
    }

    /**
     * Caller input echoed in an error, shortened.
     */
    private static function preview(string $value): string
    {
        return mb_strlen($value) > 60 ? mb_substr($value, 0, 60) . '…' : $value;
    }
}

<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;
use MCPMate\Services\AuditLog\Logger;
use MCPMate\Services\DbInspect\Sensitive;

/**
 * `transient get`. MCPMate Pro adds `transient delete` (CacheCommands).
 *
 * Plugins keep API answers, tokens and licence data in transients, so a
 * value is masked like an option or meta value: a secret-looking name hides
 * it all, secret-looking keys inside it are redacted at any depth, and
 * secret-looking query parameters of URLs inside it (update packages carry
 * licence keys) are redacted too. MCPMate's own transients are not read.
 * Values over MAX_BYTES of JSON come back as a preview.
 */
final class TransientCommands extends CommandGroup
{
    public const MAX_BYTES = 65536;

    public const PREVIEW_CHARS = 2000;

    /** Core's limit for transient names. */
    public const KEY_ARG = ['name' => 'key', 'required' => true, 'max_length' => 172, 'pattern' => '/^[A-Za-z0-9_.:-]+$/D'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('transient get', [
            'summary'    => __('Read one transient (secret values masked).', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'args'       => [self::KEY_ARG],
            'flags'      => [
                'network' => ['type' => 'boolean'],
            ],
            'target'     => ['transient', 'key'],
            'handler'    => [self::class, 'get'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $key     = (string) $command->arg('key');
        $network = (bool) $command->flag('network', false);

        if (stripos($key, Config::VAR_PREFIX) === 0) {
            throw self::notAllowed(esc_html__('MCPMate\'s own transients cannot be read through MCPMate.', 'mcpmate'));
        }

        $value = $network ? get_site_transient($key) : get_transient($key);

        if ($value === false) {
            return ['key' => $key, 'network' => $network, 'found' => false, 'value' => null];
        }

        $timeout = $network ? get_site_option('_site_transient_timeout_' . $key) : get_option('_transient_timeout_' . $key);
        $result  = [
            'key'        => $key,
            'network'    => $network,
            'found'      => true,
            'expires_at' => is_numeric($timeout) && (int) $timeout > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $timeout) : null,
        ];

        if (Sensitive::isSensitiveKey($key)) {
            return $result + ['value' => Logger::REDACTED, 'redacted' => true];
        }

        $value = self::mask(Logger::redact($value));
        $json  = (string) wp_json_encode($value);

        if (strlen($json) > self::MAX_BYTES) {
            return $result + [
                'value'     => null,
                'truncated' => true,
                'bytes'     => strlen($json),
                'preview'   => mb_substr($json, 0, self::PREVIEW_CHARS),
            ];
        }

        return $result + ['value' => $value, 'redacted' => false];
    }

    /**
     * Redact secret-looking query parameters of every URL in $value.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'mask'], $value);
        }

        if (!is_string($value) || !preg_match('#^https?://#i', $value) || strpos($value, '?') === false) {
            return $value;
        }

        [$base, $query] = explode('?', $value, 2);
        $fragment       = '';

        if (strpos($query, '#') !== false) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment           = '#' . $fragment;
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            $name    = (string) strtok($pair, '=');
            $pairs[] = $name !== '' && (preg_match(Logger::SENSITIVE_KEY, $name) || Sensitive::isSensitiveKey($name)) ? $name . '=' . Logger::REDACTED : $pair;
        }

        return $base . '?' . implode('&', $pairs) . $fragment;
    }
}

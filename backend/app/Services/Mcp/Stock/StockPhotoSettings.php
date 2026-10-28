<?php

namespace McpMate\Services\Mcp\Stock;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Connection\SecretBox;

/**
 * The site owner's Unsplash access key and app name (docs/mcp-in-plugin.md
 * §8, Tools → Integrations, Stock photos). Option `MCPMATE_stock_photos`
 * `{key_sealed, app_name}`, autoload off; the key is sealed (SecretBox) and
 * never returned (write-only).
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON tool output or admin-ajax JSON, never HTML.
final class StockPhotoSettings
{
    public const OPTION = 'stock_photos';

    /** Unsplash application names: what `utm_source` carries. */
    public const APP_NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /** Unsplash access keys are 43 URL-safe characters; leave room for other lengths. */
    public const ACCESS_KEY = '/^[A-Za-z0-9_-]{20,128}$/D';

    public static function isConfigured(): bool
    {
        return self::accessKey() !== '';
    }

    /**
     * The key, unsealed; '' when none is set or it no longer opens (new salts).
     */
    public static function accessKey(): string
    {
        $stored = Config::getOption(self::OPTION, []);
        $sealed = is_array($stored) ? (string) ($stored['key_sealed'] ?? '') : '';

        if ($sealed === '') {
            return '';
        }

        return (string) SecretBox::open($sealed, SecretBox::sealingKey());
    }

    public static function appName(): string
    {
        $stored = Config::getOption(self::OPTION, []);

        return is_array($stored) ? (string) ($stored['app_name'] ?? '') : '';
    }

    /**
     * What the admin page may see: never the key.
     *
     * @return array{configured: bool, app_name: string}
     */
    public static function summary(): array
    {
        return ['configured' => self::isConfigured(), 'app_name' => self::appName()];
    }

    /**
     * Save a new key and app name; an empty key removes both.
     *
     * @throws \InvalidArgumentException With a message for the admin.
     */
    public static function update(string $accessKey, string $appName): void
    {
        if ($accessKey === '') {
            Config::deleteOption(self::OPTION);

            return;
        }

        if (!preg_match(self::ACCESS_KEY, $accessKey)) {
            throw new \InvalidArgumentException(__('That does not look like an Unsplash access key.', 'mcpmate'));
        }

        if (!preg_match(self::APP_NAME, $appName)) {
            throw new \InvalidArgumentException(__('Give the application name you registered with Unsplash (letters, digits, _ . -).', 'mcpmate'));
        }

        Config::updateOption(self::OPTION, ['key_sealed' => SecretBox::seal($accessKey, SecretBox::sealingKey()), 'app_name' => $appName], false);
    }
}
// phpcs:enable

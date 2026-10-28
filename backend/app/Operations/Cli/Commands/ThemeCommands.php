<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `theme list|activate`. Switching themes changes the whole front end, so
 * `theme activate` is destructive.
 */
final class ThemeCommands extends CommandGroup
{
    public const FIELDS = ['name', 'title', 'status', 'version', 'parent', 'update', 'update_version', 'auto_update'];

    public const STATUSES = ['active', 'parent', 'inactive'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('theme list', [
            'summary'    => __('List installed themes with status, version and update availability.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'switch_themes',
            'flags'      => [
                'status' => ['enum' => self::STATUSES],
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('theme activate', [
            'summary'          => __('Switch the active theme.', 'mcpmate'),
            'risk'             => Command::RISK_DESTRUCTIVE,
            'capability'       => 'switch_themes',
            'supports_dry_run' => true,
            'args'             => [
                ['name' => 'theme', 'required' => true, 'max_length' => 200, 'pattern' => '#^[A-Za-z0-9._-]+$#D'],
            ],
            'target'           => ['theme', 'theme'],
            'handler'          => [self::class, 'activate'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $transient   = get_site_transient('update_themes');
        $updates     = is_object($transient) && isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $autoUpdates = (array) get_site_option('auto_update_themes', []);
        $active      = get_stylesheet();
        $parent      = get_template();
        $items       = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $slug = (string) $slug;

            if ($slug === $active) {
                $status = 'active';
            } elseif ($slug === $parent) {
                $status = 'parent';
            } else {
                $status = 'inactive';
            }

            $newVersion = isset($updates[$slug]['new_version']) ? (string) $updates[$slug]['new_version'] : null;

            $items[] = [
                'name'           => $slug,
                'title'          => (string) $theme->get('Name'),
                'status'         => $status,
                'version'        => (string) $theme->get('Version'),
                'parent'         => $theme->parent() ? $theme->get_template() : null,
                'update'         => $newVersion !== null ? 'available' : 'none',
                'update_version' => $newVersion,
                'auto_update'    => in_array($slug, $autoUpdates, true) ? 'on' : 'off',
            ];
        }

        $status = $command->flag('status');
        if ($status !== null) {
            $items = array_values(array_filter($items, static fn (array $item) => $item['status'] === $status));
        }

        return [
            'items' => self::pickFields($items, $command->flag('fields')),
            'total' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activate(ParsedCommand $command, bool $dryRun): array
    {
        $slug  = (string) $command->arg('theme');
        $theme = wp_get_theme($slug);

        if (!$theme->exists()) {
            throw self::notFound(
                /* translators: %s: theme slug. */
                sprintf(esc_html__('Theme "%s" is not installed.', 'mcpmate'), esc_html($slug)),
                esc_html__('Run "theme list" to see installed themes.', 'mcpmate')
            );
        }

        if ($theme->errors() || !$theme->is_allowed()) {
            throw self::notAllowed(
                /* translators: %s: theme slug. */
                sprintf(esc_html__('Theme "%s" is broken or not allowed on this site.', 'mcpmate'), esc_html($slug))
            );
        }

        $from = get_stylesheet();

        if ($from === $theme->get_stylesheet()) {
            return ['theme' => $slug, 'from' => $from, 'status' => 'already_active'];
        }

        if ($dryRun) {
            return ['theme' => $slug, 'from' => $from, 'status' => 'would_activate'];
        }

        switch_theme($theme->get_stylesheet());

        return ['theme' => $slug, 'from' => $from, 'status' => get_stylesheet() === $theme->get_stylesheet() ? 'activated' : 'failed'];
    }
}

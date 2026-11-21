<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `site list`: the sites of a multisite network. Read-only; sites are never
 * created, emptied or deleted through MCPMate (Policy refuses those).
 */
final class SiteCommands extends CommandGroup
{
    public const FIELDS = ['blog_id', 'url', 'domain', 'path', 'registered', 'last_updated', 'public', 'archived', 'spam', 'deleted', 'mature'];

    public const LIST_FIELDS = ['blog_id', 'url', 'registered', 'last_updated', 'public', 'archived'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('site list', [
            'summary'    => __('List the sites of this multisite network.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_sites',
            'flags'      => [
                'search'   => ['max_length' => 200],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 50],
                'paged'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'   => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        if (!is_multisite()) {
            throw self::notAllowed(
                esc_html__('This is not a multisite network.', 'mcpmate'),
                esc_html__('Use "core version" or site_info for this site.', 'mcpmate')
            );
        }

        $perPage = (int) $command->flag('per_page');
        $paged   = (int) $command->flag('paged');
        $query   = [
            'network_id' => get_current_network_id(),
            'search'     => (string) $command->flag('search', ''),
            'orderby'    => 'id',
            'order'      => 'ASC',
        ];

        $total = (int) get_sites($query + ['count' => true]);
        $items = [];

        foreach (get_sites($query + ['number' => $perPage, 'offset' => ($paged - 1) * $perPage]) as $site) {
            $items[] = [
                'blog_id'      => (int) $site->blog_id,
                'url'          => set_url_scheme('http://' . $site->domain . $site->path),
                'domain'       => $site->domain,
                'path'         => $site->path,
                'registered'   => $site->registered,
                'last_updated' => $site->last_updated,
                'public'       => (bool) (int) $site->public,
                'archived'     => (bool) (int) $site->archived,
                'spam'         => (bool) (int) $site->spam,
                'deleted'      => (bool) (int) $site->deleted,
                'mature'       => (bool) (int) $site->mature,
            ];
        }

        return [
            'items'       => self::pickFields($items, $command->flag('fields') ?? self::LIST_FIELDS),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}

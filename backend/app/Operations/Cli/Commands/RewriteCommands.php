<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `rewrite list`: the rewrite rules WordPress matches requests against, in
 * the order it tries them. MCPMate Pro adds `rewrite flush` and `rewrite
 * structure` (CacheCommands, RewriteWriteCommands).
 */
final class RewriteCommands extends CommandGroup
{
    public static function register(CommandRegistry $registry): void
    {
        $registry->add('rewrite list', [
            'summary'    => __('List the rewrite rules, or the ones a URL path matches.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'flags'      => [
                // A URL path such as /shop/page/2/, matched as WP::parse_request() matches it.
                'match'    => ['max_length' => 500, 'pattern' => '#^[A-Za-z0-9/_.~%+\-]*$#D'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => self::MAX_PER_PAGE],
                'paged'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
            ],
            'handler'    => [self::class, 'list'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        global $wp_rewrite;

        $rules = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->wp_rewrite_rules() : [];
        $match = $command->flag('match');
        $path  = $match !== null ? trim(urldecode((string) $match), '/') : '';
        $used  = false;
        $items = [];

        foreach ($rules as $regex => $query) {
            $regex   = (string) $regex;
            $query   = (string) $query;
            $matches = [];

            // WP::parse_request() tries "#^{$regex}#" on the request path, as here.
            if ($match !== null && preg_match('#^' . $regex . '#', $path, $matches) !== 1) {
                continue;
            }

            $item = ['match' => $regex, 'query' => $query];

            if ($match !== null) {
                // A request uses the first rule that matches, except a page rule for a page that does not exist.
                $item['used'] = !$used && self::pageExists($query, $matches);
                $used         = $used || $item['used'];
            }

            $items[] = $item;
        }

        $perPage = (int) $command->flag('per_page');
        $paged   = (int) $command->flag('paged');
        $total   = count($items);

        return [
            'permalink_structure' => (string) get_option('permalink_structure'),
            'items'               => array_slice($items, ($paged - 1) * $perPage, $perPage),
            'total'               => $total,
            'page'                => $paged,
            'per_page'            => $perPage,
            'total_pages'         => (int) ceil($total / $perPage),
        ];
    }

    /**
     * False for a verbose page rule whose page does not exist, as WP::parse_request() skips it.
     *
     * @param string[] $matches
     */
    private static function pageExists(string $query, array $matches): bool
    {
        if (!preg_match('/pagename=\$matches\[([0-9]+)\]/', $query, $index)) {
            return true;
        }

        return get_page_by_path($matches[(int) $index[1]] ?? '') !== null;
    }
}

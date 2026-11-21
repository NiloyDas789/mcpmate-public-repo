<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandException;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `menu list`, `menu item list`: classic navigation menus (the `nav_menu`
 * taxonomy and its `nav_menu_item` posts), as Appearance → Menus shows
 * them. MCPMate Pro adds `menu item add-post|add-term|add-custom|update`
 * (MenuWriteCommands), which use menu(), item() and describeItem(). Menus
 * and items are never deleted (rule 3).
 */
final class MenuCommands extends CommandGroup
{
    public const ITEM_FIELDS = [
        'db_id', 'type', 'object', 'object_id', 'parent', 'position', 'title', 'url', 'target', 'attr_title', 'description', 'classes', 'xfn', 'status',
    ];

    public const ITEM_LIST_FIELDS = ['db_id', 'type', 'object', 'object_id', 'parent', 'position', 'title', 'url'];

    /** A menu's ID, slug or name. */
    public const MENU_ARG = ['name' => 'menu', 'required' => true, 'max_length' => 200];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('menu list', [
            'summary'    => __('List navigation menus and the theme locations they are assigned to.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_theme_options',
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('menu item list', [
            'summary'    => __('List the items of one navigation menu.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_theme_options',
            'args'       => [self::MENU_ARG],
            'flags'      => [
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => self::MAX_PER_PAGE],
                'paged'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'   => self::fieldsFlag(self::ITEM_FIELDS),
            ],
            'target'     => ['menu', 'menu'],
            'handler'    => [self::class, 'itemList'],
        ]);
    }

    // ──────────────────────────────────────────────
    //  Handlers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $locations = self::locationsByMenu();
        $items     = [];

        foreach (wp_get_nav_menus() as $menu) {
            $items[] = [
                'term_id'   => (int) $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'count'     => (int) $menu->count,
                'locations' => $locations[(int) $menu->term_id] ?? [],
            ];
        }

        return ['items' => $items, 'total' => count($items), 'locations' => array_keys(get_registered_nav_menus())];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemList(ParsedCommand $command, bool $dryRun): array
    {
        $menu    = self::menu((string) $command->arg('menu'));
        $all     = self::items($menu);
        $perPage = (int) $command->flag('per_page');
        $paged   = (int) $command->flag('paged');
        $total   = count($all);
        $items   = array_map([self::class, 'describeItem'], array_slice($all, ($paged - 1) * $perPage, $perPage));

        return [
            'menu'        => ['term_id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug],
            'items'       => self::pickFields($items, $command->flag('fields') ?? self::ITEM_LIST_FIELDS),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    // ──────────────────────────────────────────────
    //  Helpers (also MCPMate Pro's MenuWriteCommands)
    // ──────────────────────────────────────────────

    /**
     * A menu by ID, slug or name.
     *
     * @throws CommandException 404 when there is no such menu.
     */
    public static function menu(string $value): \WP_Term
    {
        $menu = wp_get_nav_menu_object(preg_match('/^[1-9]\d{0,19}$/D', $value) ? (int) $value : $value);

        if (!$menu instanceof \WP_Term) {
            throw self::notFound(
                /* translators: %s: menu ID, slug or name. */
                sprintf(esc_html__('There is no menu "%s".', 'mcpmate'), esc_html($value)),
                esc_html__('Run "menu list" to see the menus.', 'mcpmate')
            );
        }

        return $menu;
    }

    /**
     * Every item of $menu, drafts included, in menu order.
     *
     * @return array<int, object>
     */
    public static function items(\WP_Term $menu): array
    {
        $items = wp_get_nav_menu_items($menu->term_id, ['post_status' => 'publish,draft']);

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * One menu item (wp_setup_nav_menu_item()'s object) and the menu it belongs to.
     *
     * @return array{0: object, 1: \WP_Term}
     * @throws CommandException 404 when $id is not a menu item.
     */
    public static function item(int $id): array
    {
        $post  = get_post($id);
        $menus = $post instanceof \WP_Post && $post->post_type === 'nav_menu_item' ? wp_get_object_terms($post->ID, 'nav_menu') : [];

        if (!$post instanceof \WP_Post || !is_array($menus) || !isset($menus[0])) {
            /* translators: %d: menu item ID. */
            throw self::notFound(sprintf(esc_html__('Menu item %d does not exist.', 'mcpmate'), (int) $id), esc_html__('Run "menu item list <menu>" to see the items and their db_id.', 'mcpmate'));
        }

        return [wp_setup_nav_menu_item($post), $menus[0]];
    }

    /**
     * @param object $item wp_setup_nav_menu_item()'s object.
     * @return array<string, mixed>
     */
    public static function describeItem($item): array
    {
        return [
            'db_id'       => (int) ($item->db_id ?? $item->ID ?? 0),
            'type'        => (string) ($item->type ?? ''),
            'object'      => (string) ($item->object ?? ''),
            'object_id'   => (int) ($item->object_id ?? 0),
            'parent'      => (int) ($item->menu_item_parent ?? 0),
            'position'    => (int) ($item->menu_order ?? 0),
            'title'       => (string) ($item->title ?? ''),
            'url'         => (string) ($item->url ?? ''),
            'target'      => (string) ($item->target ?? ''),
            'attr_title'  => (string) ($item->attr_title ?? ''),
            'description' => (string) ($item->description ?? ''),
            'classes'     => implode(' ', array_filter((array) ($item->classes ?? []), 'is_string')),
            'xfn'         => (string) ($item->xfn ?? ''),
            'status'      => (string) ($item->post_status ?? ''),
        ];
    }

    /**
     * Theme location names by menu ID.
     *
     * @return array<int, string[]>
     */
    public static function locationsByMenu(): array
    {
        $byMenu = [];

        foreach ((array) get_nav_menu_locations() as $location => $menuId) {
            if ((int) $menuId > 0) {
                $byMenu[(int) $menuId][] = (string) $location;
            }
        }

        return $byMenu;
    }
}

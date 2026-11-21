<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `post-type list`, `taxonomy list`: what the site registers. Read-only;
 * labels and flags only, never the registering plugin's callbacks.
 */
final class ContentTypeCommands extends CommandGroup
{
    public const POST_TYPE_FIELDS = ['name', 'label', 'singular', 'description', 'public', 'hierarchical', 'show_ui', 'show_in_rest', 'rest_base', 'supports', 'taxonomies'];

    public const POST_TYPE_LIST_FIELDS = ['name', 'label', 'public', 'hierarchical', 'show_in_rest'];

    public const TAXONOMY_FIELDS = ['name', 'label', 'singular', 'description', 'public', 'hierarchical', 'show_ui', 'show_in_rest', 'rest_base', 'object_type'];

    public const TAXONOMY_LIST_FIELDS = ['name', 'label', 'public', 'hierarchical', 'object_type'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('post-type list', [
            'summary'    => __('List the registered post types.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'flags'      => [
                'public' => ['type' => 'boolean'],
                'fields' => self::fieldsFlag(self::POST_TYPE_FIELDS),
            ],
            'handler'    => [self::class, 'postTypes'],
        ]);

        $registry->add('taxonomy list', [
            'summary'    => __('List the registered taxonomies.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'edit_posts',
            'flags'      => [
                'public'      => ['type' => 'boolean'],
                'object_type' => ['max_length' => 20, 'pattern' => '/^[a-z0-9_-]+$/D'],
                'fields'      => self::fieldsFlag(self::TAXONOMY_FIELDS),
            ],
            'handler'    => [self::class, 'taxonomies'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function postTypes(ParsedCommand $command, bool $dryRun): array
    {
        $filter = $command->hasFlag('public') ? ['public' => (bool) $command->flag('public')] : [];
        $items  = [];

        foreach (get_post_types($filter, 'objects') as $type) {
            $items[] = [
                'name'         => $type->name,
                'label'        => (string) $type->label,
                'singular'     => (string) ($type->labels->singular_name ?? $type->label),
                'description'  => (string) $type->description,
                'public'       => (bool) $type->public,
                'hierarchical' => (bool) $type->hierarchical,
                'show_ui'      => (bool) $type->show_ui,
                'show_in_rest' => (bool) $type->show_in_rest,
                'rest_base'    => is_string($type->rest_base) && $type->rest_base !== '' ? $type->rest_base : null,
                'supports'     => array_keys((array) get_all_post_type_supports($type->name)),
                'taxonomies'   => array_values(get_object_taxonomies($type->name)),
            ];
        }

        return ['items' => self::pickFields($items, $command->flag('fields') ?? self::POST_TYPE_LIST_FIELDS), 'total' => count($items)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function taxonomies(ParsedCommand $command, bool $dryRun): array
    {
        $filter = $command->hasFlag('public') ? ['public' => (bool) $command->flag('public')] : [];
        $object = $command->flag('object_type');
        $items  = [];

        foreach (get_taxonomies($filter, 'objects') as $taxonomy) {
            if ($object !== null && !in_array($object, (array) $taxonomy->object_type, true)) {
                continue;
            }

            $items[] = [
                'name'         => $taxonomy->name,
                'label'        => (string) $taxonomy->label,
                'singular'     => (string) ($taxonomy->labels->singular_name ?? $taxonomy->label),
                'description'  => (string) $taxonomy->description,
                'public'       => (bool) $taxonomy->public,
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'show_ui'      => (bool) $taxonomy->show_ui,
                'show_in_rest' => (bool) $taxonomy->show_in_rest,
                'rest_base'    => is_string($taxonomy->rest_base) && $taxonomy->rest_base !== '' ? $taxonomy->rest_base : null,
                'object_type'  => array_values((array) $taxonomy->object_type),
            ];
        }

        return ['items' => self::pickFields($items, $command->flag('fields') ?? self::TAXONOMY_LIST_FIELDS), 'total' => count($items)];
    }
}

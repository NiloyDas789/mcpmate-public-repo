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
 * `comment list`. MCPMate Pro adds `comment approve|unapprove|spam`
 * (CommentWriteCommands); comments are never deleted (rule 3, Policy).
 *
 * Needs moderate_comments, as the Comments screen does, and each comment is
 * re-checked with edit_comment. Authors' IP addresses and user agents are
 * never returned.
 */
final class CommentCommands extends CommandGroup
{
    public const FIELDS = [
        'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_date_gmt',
        'comment_content', 'status', 'comment_type', 'comment_parent', 'user_id',
    ];

    public const LIST_FIELDS = ['comment_ID', 'comment_post_ID', 'comment_author', 'comment_date_gmt', 'status', 'comment_content'];

    /** Longest comment text returned, in characters. */
    public const MAX_CONTENT = 2000;

    /** comment_approved → status name, as `wp comment list` shows it. */
    public const STATUS_NAMES = ['1' => 'approved', '0' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash', 'post-trashed' => 'post-trashed'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('comment list', [
            'summary'    => __('List comments, newest first.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'moderate_comments',
            'flags'      => [
                'status'  => ['enum' => ['all', 'approve', 'hold', 'spam', 'trash'], 'default' => 'all'],
                'post_id' => ['type' => 'integer', 'minimum' => 1],
                'type'    => ['max_length' => 20, 'pattern' => '/^[a-z0-9_-]+$/D'],
                'search'  => ['max_length' => 200],
                'orderby' => ['enum' => ['comment_date_gmt', 'comment_ID'], 'default' => 'comment_date_gmt'],
                'order'   => ['enum' => ['ASC', 'DESC', 'asc', 'desc'], 'default' => 'DESC'],
                'number'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 50],
                'paged'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'  => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $number = (int) $command->flag('number');
        $paged  = (int) $command->flag('paged');
        $args   = [
            'status'        => (string) $command->flag('status'),
            'search'        => (string) $command->flag('search', ''),
            'orderby'       => (string) $command->flag('orderby'),
            'order'         => strtoupper((string) $command->flag('order')),
            'number'        => $number,
            'paged'         => $paged,
            'no_found_rows' => false,
        ];

        if ($command->hasFlag('post_id')) {
            $args['post_id'] = (int) $command->flag('post_id');
        }

        if ($command->hasFlag('type')) {
            $args['type'] = (string) $command->flag('type');
        }

        $query = new \WP_Comment_Query();
        $found = $query->query($args);
        $items = [];

        foreach (is_array($found) ? $found : [] as $comment) {
            if ($comment instanceof \WP_Comment && current_user_can('edit_comment', (int) $comment->comment_ID)) {
                $items[] = self::describe($comment);
            }
        }

        $total = (int) $query->found_comments;

        return [
            'items'       => self::pickFields($items, $command->flag('fields') ?? self::LIST_FIELDS),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $number,
            'total_pages' => (int) ceil($total / $number),
        ];
    }

    // ──────────────────────────────────────────────
    //  Helpers (also MCPMate Pro's CommentWriteCommands)
    // ──────────────────────────────────────────────

    /**
     * A comment the current user may edit.
     *
     * @throws CommandException 404 unknown, 403 not the user's to moderate.
     */
    public static function comment(int $id): \WP_Comment
    {
        $comment = get_comment($id);

        if (!$comment instanceof \WP_Comment) {
            /* translators: %d: comment ID. */
            throw self::notFound(sprintf(esc_html__('Comment %d does not exist.', 'mcpmate'), (int) $id));
        }

        self::authorize('edit_comment', (int) $comment->comment_ID);

        return $comment;
    }

    public static function status(\WP_Comment $comment): string
    {
        return self::STATUS_NAMES[(string) $comment->comment_approved] ?? (string) $comment->comment_approved;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(\WP_Comment $comment): array
    {
        $content = (string) $comment->comment_content;

        return [
            'comment_ID'           => (int) $comment->comment_ID,
            'comment_post_ID'      => (int) $comment->comment_post_ID,
            'comment_author'       => $comment->comment_author,
            'comment_author_email' => $comment->comment_author_email,
            'comment_author_url'   => $comment->comment_author_url,
            'comment_date_gmt'     => $comment->comment_date_gmt,
            'comment_content'      => mb_strlen($content) > self::MAX_CONTENT ? mb_substr($content, 0, self::MAX_CONTENT) . '…' : $content,
            'status'               => self::status($comment),
            'comment_type'         => $comment->comment_type,
            'comment_parent'       => (int) $comment->comment_parent,
            'user_id'              => (int) $comment->user_id,
        ];
    }
}

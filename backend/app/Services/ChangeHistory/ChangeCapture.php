<?php

namespace McpMate\Services\ChangeHistory;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Settings\Preferences;

/**
 * Records what a write made through the plugin changed (docs/ui.md B7):
 * posts, post meta, terms, term meta, options, users, user meta, comments,
 * and WooCommerce products and orders (through their CRUD classes' hooks).
 *
 * Capture is armed only around a plugin write: RestController::dispatch()
 * for a handler that writes, and Approvals::approve() around the approved
 * operation. While armed, WordPress's "before" hooks mark each object the
 * write touches and snapshot it as it was (null for one being created);
 * end() snapshots each one again as it is now, and ChangeStore keeps one
 * row per object with both, linked to the audit row (`audit_id`) and the
 * request (`request_id`). Several changes to one object in one write are
 * one row. An object that ends as it started leaves no row.
 *
 * Nothing is captured while change history is off, or for object types a
 * site admin left out (Preferences). Values under secret-looking keys are
 * masked, and the plugin's own options are never captured (the sealed
 * connection secret is one of them): see ChangeSnapshots.
 */
final class ChangeCapture
{
    public const TYPES = ['post', 'post_meta', 'term', 'term_meta', 'option', 'user', 'user_meta', 'comment', 'product', 'order'];

    private static int $depth = 0;

    /** @var string[] Types captured in this write. */
    private static array $types = [];

    /**
     * Touched objects, `type|id|key` => {type, id, key, before}.
     *
     * @var array<string, array{type: string, id: int, key: string, before: array<string, mixed>|null}>
     */
    private static array $touched = [];

    // ──────────────────────────────────────────────
    //  Arming
    // ──────────────────────────────────────────────

    /**
     * Start capturing for a write. Nested calls share the outer capture.
     */
    public static function begin(): void
    {
        if (self::$depth++ > 0) {
            return;
        }

        self::$touched = [];
        self::$types   = Preferences::changeHistory() ? Preferences::changeHistoryTypes() : [];

        if (self::$types !== []) {
            self::registerHooks();
        }
    }

    /**
     * Stop capturing and store one row per changed object.
     *
     * @param array{audit_id?: int, request_id?: string, user_id?: int, grant_id?: string, mcp_tool?: string, tool?: string} $context
     * @return int Rows stored.
     */
    public static function end(array $context): int
    {
        if (self::$depth === 0 || --self::$depth > 0) {
            return 0;
        }

        $touched       = self::$touched;
        self::$touched = [];
        self::$types   = [];

        $stored = 0;

        foreach ($touched as $object) {
            $after = ChangeSnapshots::take($object['type'], $object['id'], $object['key']);

            if ($after === $object['before'] || ($after === null && $object['before'] === null)) {
                continue;
            }

            $stored += ChangeStore::insert($object['type'], $object['id'], $object['key'], $object['before'], $after, $context) ? 1 : 0;
        }

        return $stored;
    }

    public static function isCapturing(): bool
    {
        return self::$depth > 0 && self::$types !== [];
    }

    // ──────────────────────────────────────────────
    //  Touching objects
    // ──────────────────────────────────────────────

    /**
     * $type $id is about to change: snapshot it as it is, the first time only.
     */
    public static function touch(string $type, int $id, string $key = ''): void
    {
        self::mark($type, $id, $key, false);
    }

    /**
     * $type $id was just created: it had no "before".
     */
    public static function created(string $type, int $id, string $key = ''): void
    {
        self::mark($type, $id, $key, true);
    }

    private static function mark(string $type, int $id, string $key, bool $created): void
    {
        if (!self::isCapturing() || !in_array($type, self::$types, true) || ($id <= 0 && $type !== 'option')) {
            return;
        }

        if (!ChangeSnapshots::isTracked($type, $id, $key)) {
            return;
        }

        $index = $type . '|' . $id . '|' . $key;

        if (isset(self::$touched[$index])) {
            return;
        }

        self::$touched[$index] = [
            'type'   => $type,
            'id'     => $id,
            'key'    => $key,
            'before' => $created ? null : ChangeSnapshots::take($type, $id, $key),
        ];
    }

    // ──────────────────────────────────────────────
    //  WordPress hooks
    // ──────────────────────────────────────────────

    /**
     * The hooks are added on the first capture and do nothing unless one is
     * armed. Checked by the hook itself, not a flag, so hooks that were
     * removed (as WordPress's test library does between tests) come back.
     */
    private static function registerHooks(): void
    {
        if (has_action('pre_post_update', [self::class, 'onPost']) !== false) {
            return;
        }

        // Posts (and products and legacy orders, which are posts too).
        add_action('pre_post_update', [self::class, 'onPost'], 0);
        add_action('wp_trash_post', [self::class, 'onPost'], 0);
        add_action('before_delete_post', [self::class, 'onPost'], 0);
        add_action('wp_insert_post', [self::class, 'onPostInserted'], 0, 3);
        add_action('add_attachment', [self::class, 'onPostCreated'], 0);

        // Meta. The "before" hooks of add_metadata(), update_metadata() and delete_metadata().
        foreach (['post', 'term', 'user'] as $metaType) {
            add_action("add_{$metaType}_meta", static function ($objectId, $metaKey) use ($metaType): void {
                self::onMeta($metaType, $objectId, $metaKey);
            }, 0, 2);
            add_action("update_{$metaType}_meta", static function ($metaId, $objectId, $metaKey) use ($metaType): void {
                self::onMeta($metaType, $objectId, $metaKey);
            }, 0, 3);
            add_action("delete_{$metaType}_meta", static function ($metaIds, $objectId, $metaKey) use ($metaType): void {
                self::onMeta($metaType, $objectId, $metaKey);
            }, 0, 3);
        }

        // Terms.
        add_action('edit_terms', [self::class, 'onTerm'], 0);
        add_action('pre_delete_term', [self::class, 'onTerm'], 0);
        add_action('created_term', [self::class, 'onTermCreated'], 0);

        // Options.
        add_action('add_option', [self::class, 'onOption'], 0);
        add_action('update_option', [self::class, 'onOption'], 0);
        add_action('delete_option', [self::class, 'onOption'], 0);

        // Users.
        add_filter('wp_pre_insert_user_data', [self::class, 'onUserData'], 0, 3);
        add_action('user_register', [self::class, 'onUserCreated'], 0);
        add_action('delete_user', [self::class, 'onUser'], 0);

        // Comments.
        add_filter('wp_update_comment_data', [self::class, 'onCommentData'], 0, 2);
        add_action('wp_insert_comment', [self::class, 'onCommentCreated'], 0);
        add_action('trash_comment', [self::class, 'onComment'], 0);
        add_action('spam_comment', [self::class, 'onComment'], 0);
        add_action('delete_comment', [self::class, 'onComment'], 0);

        // WooCommerce CRUD classes (products and variations, and orders in any storage).
        foreach (['product', 'product_variation', 'order'] as $object) {
            $type = $object === 'order' ? 'order' : 'product';

            add_action("woocommerce_before_{$object}_object_save", static function ($data) use ($type): void {
                self::onWooObject($type, $data, false);
            }, 0);
            add_action("woocommerce_after_{$object}_object_save", static function ($data) use ($type): void {
                self::onWooObject($type, $data, true);
            }, 0);
        }
        add_action('woocommerce_before_delete_order', [self::class, 'onOrder'], 0);
        add_action('woocommerce_before_trash_order', [self::class, 'onOrder'], 0);
    }

    /**
     * @param mixed $postId
     */
    public static function onPost($postId): void
    {
        $postId = (int) $postId;
        self::touch(ChangeSnapshots::typeOfPost($postId), $postId);
    }

    /**
     * @param mixed $postId
     */
    public static function onPostCreated($postId): void
    {
        $postId = (int) $postId;
        self::created(ChangeSnapshots::typeOfPost($postId), $postId);
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     * @param mixed $update
     */
    public static function onPostInserted($postId, $post, $update): void
    {
        if (!$update) {
            self::onPostCreated($postId);
        }
    }

    /**
     * @param mixed $objectId
     * @param mixed $metaKey
     */
    public static function onMeta(string $metaType, $objectId, $metaKey): void
    {
        $objectId = (int) $objectId;
        $metaKey  = is_string($metaKey) ? $metaKey : '';

        // WooCommerce data and user roles live in meta; their own snapshot covers them.
        if ($metaType === 'post' && in_array(ChangeSnapshots::typeOfPost($objectId), ['product', 'order'], true)) {
            self::touch(ChangeSnapshots::typeOfPost($objectId), $objectId);

            return;
        }

        if ($metaType === 'user' && ChangeSnapshots::isRoleKey($metaKey)) {
            self::touch('user', $objectId);

            return;
        }

        if ($metaKey !== '') {
            self::touch($metaType . '_meta', $objectId, $metaKey);
        }
    }

    /**
     * @param mixed $termId
     */
    public static function onTerm($termId): void
    {
        self::touch('term', (int) $termId);
    }

    /**
     * @param mixed $termId
     */
    public static function onTermCreated($termId): void
    {
        self::created('term', (int) $termId);
    }

    /**
     * @param mixed $option
     */
    public static function onOption($option): void
    {
        if (is_string($option) && $option !== '') {
            self::touch('option', 0, $option);
        }
    }

    /**
     * `wp_pre_insert_user_data`: an existing user is about to be updated.
     *
     * @param mixed $data
     * @param mixed $update
     * @param mixed $userId
     * @return mixed $data, unchanged.
     */
    public static function onUserData($data, $update, $userId)
    {
        if ($update && $userId) {
            self::touch('user', (int) $userId);
        }

        return $data;
    }

    /**
     * @param mixed $userId
     */
    public static function onUser($userId): void
    {
        self::touch('user', (int) $userId);
    }

    /**
     * @param mixed $userId
     */
    public static function onUserCreated($userId): void
    {
        self::created('user', (int) $userId);
    }

    /**
     * `wp_update_comment_data`: a comment is about to be updated.
     *
     * @param mixed $data
     * @param mixed $comment
     * @return mixed $data, unchanged.
     */
    public static function onCommentData($data, $comment)
    {
        if (is_array($comment) && isset($comment['comment_ID'])) {
            self::touch('comment', (int) $comment['comment_ID']);
        }

        return $data;
    }

    /**
     * @param mixed $commentId
     */
    public static function onComment($commentId): void
    {
        self::touch('comment', (int) $commentId);
    }

    /**
     * @param mixed $commentId
     */
    public static function onCommentCreated($commentId): void
    {
        self::created('comment', (int) $commentId);
    }

    /**
     * A WooCommerce product or order is about to be saved (before), or was
     * saved (after: the first sight of a new one).
     *
     * @param mixed $data A WC_Data object.
     */
    public static function onWooObject(string $type, $data, bool $saved): void
    {
        if (!is_object($data) || !method_exists($data, 'get_id')) {
            return;
        }

        $id = (int) $data->get_id();

        if ($saved) {
            self::created($type, $id);
        } else {
            self::touch($type, $id);
        }
    }

    /**
     * @param mixed $orderId
     */
    public static function onOrder($orderId): void
    {
        self::touch('order', (int) $orderId);
    }
}

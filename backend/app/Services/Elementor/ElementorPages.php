<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Core\Base\Document;
use Elementor\Core\Files\CSS\Post as PostCss;
use Elementor\Core\Settings\Page\Manager as PageSettings;
use Elementor\Plugin as Elementor;

/**
 * Elementor pages through Elementor's own document API, never by writing
 * `_elementor_data` directly:
 *
 * - read: `Documents_Manager::get()` → `Document::get_elements_data()` (the
 *   stored tree, before any editor conversion);
 * - save: `Document::save(['elements', 'settings'])`, inside
 *   `switch_to_document()` / `DB::switch_to_post()` as Elementor's editor
 *   save (`Documents_Manager::ajax_save()`) does it. Elementor re-creates
 *   each element (`get_data_for_save()`), sanitizes for users without
 *   `unfiltered_html`, writes the plain-text `post_content`, the version and
 *   the template type, deletes the post CSS and its document cache; then
 *   `Post_CSS::update()` regenerates the CSS file right away;
 * - create: `Documents_Manager::create()` with `post_status` draft.
 *
 * Only `draft` and `pending` posts are saved (rule 2), and `post_status`
 * never reaches Elementor's page settings, whose save would publish when the
 * user may. Page settings are limited to PAGE_SETTINGS plus the title.
 */
final class ElementorPages
{
    public const EDITABLE_STATUSES = ['draft', 'pending'];

    /** Post types new pages can be created as, with their Elementor document type. */
    public const CREATE_TYPES = ['page' => 'wp-page', 'post' => 'wp-post'];

    /** Post types Elementor supports that McpMate leaves alone: they apply site-wide. */
    public const EXCLUDED_POST_TYPES = ['elementor_library', 'e-floating-buttons'];

    /** Page settings a request may change, besides the title. */
    public const PAGE_SETTINGS = ['template', 'hide_title'];

    /** Largest tree accepted, as JSON. */
    public const MAX_TREE_BYTES = 2097152;

    /**
     * Whether Elementor is loaded with the document API this uses.
     */
    public static function isAvailable(): bool
    {
        return did_action('elementor/loaded') > 0
            && class_exists(Elementor::class)
            && class_exists(Document::class);
    }

    /**
     * Post types whose Elementor content may be read and edited.
     *
     * @return string[]
     */
    public static function postTypes(): array
    {
        return array_values(array_diff(get_post_types_by_support('elementor'), self::EXCLUDED_POST_TYPES));
    }

    /**
     * The post's Elementor document, built with Elementor and of an allowed type.
     *
     * @throws ElementorException
     */
    public static function document(\WP_Post $post): Document
    {
        if (!in_array($post->post_type, self::postTypes(), true)) {
            throw new ElementorException(
                esc_html__('This post type cannot be edited with Elementor through McpMate.', 'mcpmate'),
                esc_html(ElementorException::POST_TYPE_NOT_ALLOWED),
                400,
                /* translators: %s: comma-separated post types. */
                sprintf(esc_html__('Elementor pages of these types can be used: %s.', 'mcpmate'), esc_html(implode(', ', self::postTypes())))
            );
        }

        $document = Elementor::$instance->documents->get($post->ID, false);

        if (!$document instanceof Document || !$document->is_built_with_elementor()) {
            throw new ElementorException(
                esc_html__('This post is not built with Elementor.', 'mcpmate'),
                esc_html(ElementorException::NOT_ELEMENTOR),
                409,
                esc_html__('Create a new Elementor page with POST /elementor instead.', 'mcpmate')
            );
        }

        return $document;
    }

    /**
     * The stored element tree.
     *
     * @return array<int, mixed>
     */
    public static function elements(Document $document): array
    {
        return array_values($document->get_elements_data());
    }

    /**
     * The page as GET /elementor/{id} returns it.
     *
     * @return array<string, mixed>
     */
    public static function describe(\WP_Post $post, Document $document, ?string $elementId): array
    {
        $elements  = self::elements($document);
        $truncated = [];

        if ($elementId !== null) {
            $element = ElementTree::find($elements, $elementId);

            if ($element === null) {
                throw new ElementorException(
                    esc_html__('No element has that id.', 'mcpmate'),
                    esc_html(ElementorException::ELEMENT_NOT_FOUND),
                    404,
                    esc_html__('Read the page without element_id to get the current element ids.', 'mcpmate')
                );
            }

            $tree = ElementTree::forReading([$element], false);
        } else {
            $tree = ElementTree::forReading($elements, true, $truncated);
        }

        return self::summary($post, $document, $elements) + [
            'page_settings'      => (object) self::pageSettings($document),
            'elements'           => $tree,
            'truncated_settings' => $truncated,
        ];
    }

    /**
     * Facts about the page shared by reads and saves.
     *
     * @param array<int, mixed> $elements
     * @return array<string, mixed>
     */
    public static function summary(\WP_Post $post, Document $document, array $elements): array
    {
        $post = get_post($post->ID) ?: $post;

        return [
            'post_id'           => $post->ID,
            'title'             => $post->post_title,
            'post_type'         => $post->post_type,
            'status'            => $post->post_status,
            'editable'          => in_array($post->post_status, self::EDITABLE_STATUSES, true),
            'template'          => (string) (get_post_meta($post->ID, '_wp_page_template', true) ?: 'default'),
            'version'           => ElementTree::version($elements),
            'element_count'     => ElementTree::count($elements),
            'elementor_version' => (string) $document->get_main_meta('_elementor_version'),
            'edit_url'          => $document->get_edit_url(),
            'preview_url'       => (string) get_preview_post_link($post),
        ];
    }

    /**
     * Check that $post can be saved now: an editable status, nobody else in
     * the editor, the current user allowed by Elementor, and $expectedVersion
     * (when given) still current.
     *
     * @throws ElementorException
     */
    public static function checkWritable(\WP_Post $post, Document $document, string $expectedVersion): void
    {
        if (!in_array($post->post_status, self::EDITABLE_STATUSES, true)) {
            throw new ElementorException(
                esc_html__('Only draft and pending posts can be changed.', 'mcpmate'),
                esc_html(ElementorException::NOT_DRAFT),
                409,
                esc_html__('Copy the page into a new draft with POST /elementor {copy_from}, edit that, and let the site owner publish it.', 'mcpmate')
            );
        }

        if (!$document->is_editable_by_current_user()) {
            throw new ElementorException(
                esc_html__('Elementor does not let this user edit this post.', 'mcpmate'),
                esc_html(ElementorException::FORBIDDEN),
                403
            );
        }

        if (!function_exists('wp_check_post_lock')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        if (wp_check_post_lock($post->ID)) {
            throw new ElementorException(
                esc_html__('Someone is editing this post right now.', 'mcpmate'),
                esc_html(ElementorException::LOCKED),
                409,
                esc_html__('Try again once they have closed the editor.', 'mcpmate')
            );
        }

        if ($expectedVersion !== '' && !hash_equals(ElementTree::version(self::elements($document)), $expectedVersion)) {
            throw new ElementorException(
                esc_html__('The page changed since it was read.', 'mcpmate'),
                esc_html(ElementorException::VERSION_CONFLICT),
                409,
                esc_html__('Read the page again and re-apply the changes to the current version.', 'mcpmate')
            );
        }
    }

    /**
     * Save $elements (already validated) and the page settings, then regenerate the CSS.
     *
     * The page settings are saved first, in a call of their own: for users
     * without manage_options, Elementor's content sanitizer walks the whole
     * save data with `DB::iterate_data()` and warns on every string in the
     * page settings, but skips data without elements.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed>|null $pageSettings From pageSettingsFor(); null leaves them as they are.
     * @return array<string, mixed> The CSS outcome: `{status}`.
     * @throws ElementorException
     */
    public static function save(Document $document, array $elements, ?array $pageSettings): array
    {
        $saves = [];
        if ($pageSettings !== null) {
            $saves[] = ['settings' => $pageSettings];
        }
        $saves[] = ['elements' => self::withWidgetTypes($elements)];

        $postId = $document->get_main_id();

        Elementor::$instance->documents->switch_to_document($document);
        Elementor::$instance->db->switch_to_post($postId);

        try {
            $saved = true;
            foreach ($saves as $data) {
                $saved = $saved && $document->save($data);
            }
        } catch (\Throwable $e) {
            $saved = false;
        } finally {
            Elementor::$instance->db->restore_current_post();
            Elementor::$instance->documents->restore_document();
        }

        if (!$saved) {
            throw new ElementorException(
                esc_html__('Elementor could not save the page.', 'mcpmate'),
                esc_html(ElementorException::SAVE_FAILED),
                500
            );
        }

        return self::regenerateCss($postId);
    }

    /**
     * $elements with an empty `widgetType` on every non-widget element.
     * Elementor's content sanitizer (`elementor/document/save/data`) reads
     * `widgetType` on every element when the user lacks manage_options, and
     * a container without one raises a PHP warning that would corrupt the
     * JSON response. Elementor rebuilds each element for storage
     * (`get_data_for_save()`), so the key is never saved.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private static function withWidgetTypes(array $elements): array
    {
        foreach ($elements as $i => $element) {
            $element += ['widgetType' => ''];
            $element['elements'] = self::withWidgetTypes($element['elements'] ?? []);
            $elements[$i]        = $element;
        }

        return $elements;
    }

    /**
     * Create a draft Elementor page and save $elements into it. A page that
     * fails to save is moved to the trash (rule 3) and the error rethrown.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, mixed>|null $pageSettings
     * @return array{post: \WP_Post, document: Document, css: array<string, mixed>}
     * @throws ElementorException
     */
    public static function create(string $postType, string $title, array $elements, ?array $pageSettings): array
    {
        $document = Elementor::$instance->documents->create(self::CREATE_TYPES[$postType], [
            'post_title'  => $title,
            'post_status' => 'draft',
            'post_type'   => $postType,
        ]);

        if (!$document instanceof Document || !get_post($document->get_main_id())) {
            throw new ElementorException(
                esc_html__('Elementor could not create the page.', 'mcpmate'),
                esc_html(ElementorException::SAVE_FAILED),
                500
            );
        }

        $postId = $document->get_main_id();

        try {
            $css = self::save($document, $elements, $pageSettings);
        } catch (ElementorException $e) {
            wp_trash_post($postId);

            throw $e;
        }

        /** @var \WP_Post $post */
        $post = get_post($postId);

        return ['post' => $post, 'document' => Elementor::$instance->documents->get($postId, false), 'css' => $css];
    }

    /**
     * Regenerate the post's CSS file now instead of on its next view.
     *
     * @return array<string, mixed>
     */
    public static function regenerateCss(int $postId): array
    {
        try {
            $css = PostCss::create($postId);
            $css->update();

            return ['status' => (string) $css->get_meta('status')];
        } catch (\Throwable $e) {
            // Elementor regenerates it on the next front-end view.
            return ['status' => 'pending'];
        }
    }

    // ──────────────────────────────────────────────
    //  Page settings
    // ──────────────────────────────────────────────

    /**
     * The page settings a read shows: the allowlisted ones that are set.
     *
     * @return array<string, mixed>
     */
    public static function pageSettings(Document $document): array
    {
        $stored = $document->get_meta(PageSettings::META_KEY);

        return array_intersect_key(is_array($stored) ? $stored : [], array_flip(self::PAGE_SETTINGS));
    }

    /**
     * The `settings` to pass to Document::save(): $base's stored page
     * settings with $changes merged in. Only PAGE_SETTINGS and `post_title`
     * may change; Elementor's other special settings (post_status above all)
     * are never passed. Null when nothing changes, unless $always (a copy,
     * which takes its source's settings).
     *
     * @param Document|null $base The document whose settings are kept: the page itself, or a copy's source.
     * @param array<string, mixed> $changes `template`, `hide_title`, and `post_title`.
     * @return array<string, mixed>|null
     * @throws ElementorException
     */
    public static function pageSettingsFor(?Document $base, string $postType, array $changes, bool $always = false): ?array
    {
        if ($changes === [] && !$always) {
            return null;
        }

        $stored   = $base ? $base->get_meta(PageSettings::META_KEY) : [];
        $settings = is_array($stored) ? $stored : [];

        // Stored settings never hold special settings, but strip them in case another plugin put one there.
        unset(
            $settings['post_status'],
            $settings['post_title'],
            $settings['template'],
            $settings['post_excerpt'],
            $settings['post_featured_image'],
            $settings['menu_order'],
            $settings['comment_status']
        );

        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'post_title':
                    $settings['post_title'] = sanitize_text_field((string) $value);
                    break;

                case 'template':
                    $templates = self::templates($postType);
                    if (!is_string($value) || !in_array($value, $templates, true)) {
                        throw new ElementorException(
                            esc_html__('That page template does not exist.', 'mcpmate'),
                            esc_html(ElementorException::INVALID_SETTINGS),
                            400,
                            /* translators: %s: comma-separated template names. */
                            sprintf(esc_html__('Use one of: %s.', 'mcpmate'), esc_html(implode(', ', $templates)))
                        );
                    }
                    $settings['template'] = $value;
                    break;

                case 'hide_title':
                    if ($value !== '' && $value !== 'yes') {
                        throw new ElementorException(
                            esc_html__('hide_title must be "yes" or "".', 'mcpmate'),
                            esc_html(ElementorException::INVALID_SETTINGS),
                            400
                        );
                    }

                    if ($value === '') {
                        unset($settings['hide_title']);
                    } else {
                        $settings['hide_title'] = 'yes';
                    }
                    break;
            }
        }

        return $settings;
    }

    /**
     * Page templates a post of $postType may use: `default`, the theme's and
     * Elementor's (`elementor_canvas`, `elementor_header_footer`).
     *
     * @return string[]
     */
    public static function templates(string $postType): array
    {
        return array_merge(['default'], array_map('strval', array_keys(wp_get_theme()->get_page_templates(null, $postType))));
    }
}

<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Elementor\ElementCatalog;
use McpMate\Services\Elementor\ElementorException;
use McpMate\Services\Elementor\ElementorPages;
use McpMate\Services\Elementor\ElementorSettings;
use McpMate\Services\Elementor\ElementTree;
use McpMate\Services\Elementor\TreeValidator;

/**
 * Elementor pages, read and written through Elementor's document API
 * (ElementorPages), registered only while Elementor is active.
 *
 * GET  /elementor/widgets?search=&category=&name=&tab=&page=&per_page= —
 *      element types and allowed widgets; with `name`, one of them with its controls.
 * GET  /elementor/{post_id}?element_id= — the element tree (long settings
 *      truncated), or one element in full.
 * POST /elementor/{post_id} { elements | patches, title, page_settings,
 *      expected_version, dry_run } — save a whole tree or apply patches.
 * POST /elementor { title, post_type, elements | copy_from, page_settings,
 *      dry_run } — create a draft page (201).
 *
 * Every route needs edit_posts and a site admin's opt-in; reads and writes
 * also need edit_post on the post (and create_posts for its type to create
 * one). Only draft and pending posts are written. Trees go through
 * TreeValidator first: a tree with errors is never saved (400, or 200 with
 * the full list for dry_run).
 */
final class ElementorController extends RestController
{
    protected const CAPABILITIES = [
        'widgets' => 'edit_posts',
        'show'    => 'edit_posts',
        'save'    => 'edit_posts',
        'create'  => 'edit_posts',
    ];

    public const MAX_PER_PAGE = 100;

    public const MAX_TITLE_CHARS = 200;

    /** Main-tab controls (Content, or Layout for element types) shown per item in the listing. */
    public const LISTED_CONTROLS = 40;

    public function widgets(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $catalog = new ElementCatalog();
        $name    = (string) $request->get('name', '');

        if ($name !== '') {
            $item = $catalog->describe($name, (string) $request->get('tab', ''));

            if ($item === null) {
                return $this->error(
                    __('This site has no such element or widget, or it cannot be used through McpMate.', 'mcpmate'),
                    404,
                    'mcpmate_elementor_widget_not_found',
                    __('GET /elementor/widgets lists the ones that can be used.', 'mcpmate')
                );
            }

            return $this->ok(['widget' => $item]);
        }

        $items   = $catalog->listing((string) $request->get('search', ''), (string) $request->get('category', ''));
        $total   = count($items);
        $page    = max(1, (int) $request->get('page', 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get('per_page', 50)));
        $slice   = array_slice($items, ($page - 1) * $perPage, $perPage);

        foreach ($slice as $i => $item) {
            // Element types keep their main controls on the Layout tab, widgets on Content.
            $described = $catalog->describe($item['name'], $item['kind'] === 'element' ? 'layout' : 'content');
            $controls  = [];

            foreach (array_slice($described['controls'] ?? [], 0, self::LISTED_CONTROLS) as $control) {
                $controls[$control['key']] = $control['type'];
            }

            $slice[$i]['content_controls'] = (object) $controls;
        }

        $categories = [];
        foreach ($items as $item) {
            foreach ($item['categories'] as $category) {
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }
        }
        ksort($categories);

        return $this->ok([
            'widgets'     => $slice,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'categories'  => (object) $categories,
            'containers'  => $catalog->hasElementType('container'),
        ]);
    }

    public function show(Request $request, string $postId): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $post = $this->post($postId);
        if ($post instanceof Response) {
            return $post;
        }

        $elementId = $request->has('element_id') ? (string) $request->get('element_id') : null;

        try {
            return $this->ok(ElementorPages::describe($post, ElementorPages::document($post), $elementId));
        } catch (ElementorException $e) {
            return $this->fromException($e);
        }
    }

    public function save(Request $request, string $postId): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $post = $this->post($postId);
        if ($post instanceof Response) {
            return $post;
        }

        $hasElements = $request->has('elements');
        $hasPatches  = $request->has('patches');
        $changes     = self::pageSettingChanges($request);

        if ($hasElements && $hasPatches) {
            return $this->error(
                __('Send either elements or patches, not both.', 'mcpmate'),
                400,
                'mcpmate_elementor_invalid_request',
                __('"elements" replaces the whole tree; "patches" changes parts of the stored one.', 'mcpmate')
            );
        }

        if (!$hasElements && !$hasPatches && $changes === []) {
            return $this->error(
                __('Nothing to save.', 'mcpmate'),
                400,
                'mcpmate_elementor_invalid_request',
                __('Send "elements" (the whole tree), "patches", "title" or "page_settings".', 'mcpmate')
            );
        }

        if ($hasPatches) {
            $this->auditAction('patch');
        }

        try {
            $document = ElementorPages::document($post);
            ElementorPages::checkWritable($post, $document, (string) $request->get('expected_version', ''));

            $stored = ElementorPages::elements($document);
            $tree   = $stored;

            if ($hasPatches) {
                $tree = ElementTree::applyPatches($stored, (array) $request->get('patches'));
            } elseif ($hasElements) {
                $tree = array_values((array) $request->get('elements'));
            }

            $dryRun = (bool) $request->get('dry_run', false);
            $result = $this->check($tree, $stored, $dryRun);
            if ($result instanceof Response) {
                return $result;
            }

            if ($dryRun) {
                return $this->ok(self::dryRun($result));
            }

            $pageSettings = ElementorPages::pageSettingsFor($document, $post->post_type, $changes);
            $css          = ElementorPages::save($document, $result['elements'], $pageSettings);
            $document     = ElementorPages::document($post);

            return $this->ok(ElementorPages::summary($post, $document, ElementorPages::elements($document)) + [
                'saved'         => true,
                'css'           => $css,
                'warning_count' => $result['warning_count'],
                'warnings'      => $result['warnings'],
            ]);
        } catch (ElementorException $e) {
            return $this->fromException($e);
        }
    }

    public function create(Request $request): Response
    {
        $disabled = $this->disabled();
        if ($disabled) {
            return $disabled;
        }

        $postType = (string) $request->get('post_type', 'page');

        if (!isset(ElementorPages::CREATE_TYPES[$postType]) || !in_array($postType, ElementorPages::postTypes(), true)) {
            return $this->error(
                __('Elementor pages cannot be created with this post type.', 'mcpmate'),
                400,
                ElementorException::POST_TYPE_NOT_ALLOWED,
                /* translators: %s: comma-separated post types. */
                sprintf(__('Use one of: %s.', 'mcpmate'), implode(', ', array_intersect(array_keys(ElementorPages::CREATE_TYPES), ElementorPages::postTypes())))
            );
        }

        $postTypeObject = get_post_type_object($postType);
        $denied         = $this->authorize($postTypeObject ? (string) $postTypeObject->cap->create_posts : '');
        if ($denied) {
            return $denied;
        }

        $title = trim(sanitize_text_field((string) $request->get('title', '')));
        if ($title === '') {
            return $this->error(__('The title is empty.', 'mcpmate'), 400, 'mcpmate_elementor_invalid_request');
        }

        if ($request->has('elements') && $request->has('copy_from')) {
            return $this->error(
                __('Send either elements or copy_from, not both.', 'mcpmate'),
                400,
                'mcpmate_elementor_invalid_request',
                __('Copy the page first, then change the copy with POST /elementor/{post_id}.', 'mcpmate')
            );
        }

        try {
            $source = null;
            $stored = [];
            $tree   = array_values((array) $request->get('elements', []));

            if ($request->has('copy_from')) {
                $sourcePost = $this->post((string) $request->get('copy_from'));
                if ($sourcePost instanceof Response) {
                    return $sourcePost;
                }

                $source = ElementorPages::document($sourcePost);
                $stored = ElementorPages::elements($source);
                $tree   = $stored;
            }

            $dryRun = (bool) $request->get('dry_run', false);
            $result = $this->check($tree, $stored, $dryRun);
            if ($result instanceof Response) {
                return $result;
            }

            if ($dryRun) {
                return $this->ok(self::dryRun($result));
            }

            $pageSettings = ElementorPages::pageSettingsFor($source, $postType, self::pageSettingChanges($request, false), $source !== null);
            $created      = ElementorPages::create($postType, $title, $result['elements'], $pageSettings);

            $this->auditTarget('post', $created['post']->ID);

            $data = ElementorPages::summary($created['post'], $created['document'], ElementorPages::elements($created['document'])) + [
                'saved'         => true,
                'css'           => $created['css'],
                'warning_count' => $result['warning_count'],
                'warnings'      => $result['warnings'],
            ];

            if ($source !== null) {
                $data['copied_from'] = $source->get_main_id();
            }

            return $this->ok($data, 201);
        } catch (ElementorException $e) {
            return $this->fromException($e);
        }
    }

    // ──────────────────────────────────────────────
    //  REST args
    // ──────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function widgetsArgs(): array
    {
        return [
            'name'     => [
                'type'        => 'string',
                'description' => 'One element type (container, section, column) or widget, returned with all its controls.',
                'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
            ],
            'tab'      => [
                'type'        => 'string',
                'description' => 'With name: only controls on this panel tab.',
                'enum'        => ['', 'content', 'style', 'advanced', 'layout'],
                'default'     => '',
            ],
            'search'   => [
                'type'        => 'string',
                'description' => 'Only widgets whose name, title or keywords contain this text.',
                'maxLength'   => 100,
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Only widgets in this panel category, e.g. "basic" or "pro-elements".',
                'maxLength'   => 100,
            ],
            'page'     => [
                'type'    => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
            'per_page' => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'default' => 50,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function showArgs(): array
    {
        return self::postIdArgs() + [
            'element_id' => [
                'type'        => 'string',
                'description' => 'Return only this element (with its children), with no setting truncated.',
                'pattern'     => '^[A-Za-z0-9]{1,32}$',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function saveArgs(): array
    {
        return self::postIdArgs() + self::writeArgs() + [
            'patches'          => [
                'type'        => 'array',
                'description' => 'Changes to the stored tree, applied in order: {op: "update", id, settings} merges settings (null removes one); '
                    . '{op: "insert", parent, position, element}; {op: "remove", id}; {op: "move", id, parent, position}. parent null is the top level.',
                'maxItems'    => ElementTree::MAX_PATCHES,
                'items'       => ['type' => 'object'],
            ],
            'expected_version' => [
                'type'        => 'string',
                'description' => 'The version from the last read; the save is refused (409) when the page changed since.',
                'pattern'     => '^[0-9a-f]{16}$',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function createArgs(): array
    {
        $args = self::writeArgs();

        $args['title']['required'] = true;

        return $args + [
            'post_type' => [
                'type'        => 'string',
                'description' => 'page (default) or post. The new post is always a draft.',
                'enum'        => array_keys(ElementorPages::CREATE_TYPES),
                'default'     => 'page',
            ],
            'copy_from' => [
                'type'        => 'integer',
                'description' => 'Copy this Elementor post\'s elements and page settings into the new draft.',
                'minimum'     => 1,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function postIdArgs(): array
    {
        return [
            'post_id' => [
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function writeArgs(): array
    {
        return [
            'elements'      => [
                'type'        => 'array',
                'description' => 'The whole element tree: [{id, elType, widgetType, settings, elements}]. A missing id is generated.',
                'items'       => ['type' => 'object'],
            ],
            'title'         => [
                'type'        => 'string',
                'description' => 'The post title.',
                'minLength'   => 1,
                'maxLength'   => self::MAX_TITLE_CHARS,
            ],
            'page_settings' => [
                'type'                 => 'object',
                'description'          => 'Elementor page settings: template (default, elementor_canvas, elementor_header_footer or a theme template) and hide_title ("yes" or "").',
                'properties'           => [
                    'template'   => ['type' => 'string', 'maxLength' => 200],
                    'hide_title' => ['type' => 'string', 'enum' => ['', 'yes']],
                ],
                'additionalProperties' => false,
            ],
            'dry_run'       => [
                'type'        => 'boolean',
                'description' => 'Check the tree and answer with every error and warning, without saving.',
                'default'     => false,
            ],
        ];
    }

    /**
     * The audit row keeps the shape of a tree, not its content (page text).
     */
    protected static function auditArgs(array $args): array
    {
        if (isset($args['elements']) && is_array($args['elements'])) {
            $args['elements'] = sprintf('[%d elements, %d bytes]', ElementTree::count($args['elements']), strlen((string) wp_json_encode($args['elements'])));
        }

        if (isset($args['patches']) && is_array($args['patches'])) {
            $ops = [];
            foreach (array_slice($args['patches'], 0, 50) as $patch) {
                $ops[] = is_array($patch) ? ($patch['op'] ?? '?') . ':' . (is_string($patch['id'] ?? null) ? $patch['id'] : '-') : '?';
            }
            $args['patches'] = $ops;
        }

        return $args;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private function disabled(): ?Response
    {
        if (ElementorSettings::isEnabled()) {
            return null;
        }

        return $this->error(
            __('Elementor editing is not enabled on this site.', 'mcpmate'),
            403,
            'mcpmate_elementor_disabled',
            __('A site admin can enable it under McpMate → Tools → Integrations (Elementor).', 'mcpmate')
        );
    }

    /**
     * The post, after checking that the current user may edit it.
     *
     * @return \WP_Post|Response
     */
    private function post(string $postId)
    {
        $post = get_post((int) $postId);

        if (!$post instanceof \WP_Post) {
            return $this->error(__('Post not found.', 'mcpmate'), 404, 'mcpmate_post_not_found');
        }

        $this->auditTarget('post', $post->ID);

        $denied = $this->authorize('edit_post', $post->ID);

        return $denied ?? $post;
    }

    /**
     * Validate $tree against $stored; a Response for a tree that is too large
     * or invalid (unless it is a dry run, which answers with the issues).
     *
     * @param array<int, mixed> $tree
     * @param array<int, mixed> $stored
     * @return array<string, mixed>|Response
     */
    private function check(array $tree, array $stored, bool $dryRun)
    {
        if (strlen((string) wp_json_encode($tree)) > ElementorPages::MAX_TREE_BYTES) {
            return $this->error(
                __('The element tree is too large.', 'mcpmate'),
                400,
                ElementorException::TOO_LARGE,
                /* translators: %d: size in MiB. */
                sprintf(__('Send at most %d MiB of elements; change large pages with patches.', 'mcpmate'), ElementorPages::MAX_TREE_BYTES / 1048576)
            );
        }

        $result = TreeValidator::validate($tree, $stored, new ElementCatalog());

        if (!$result['valid'] && !$dryRun) {
            return $this->error(
                sprintf(
                    /* translators: 1: number of errors; 2: the first errors. */
                    _n('The element tree has %1$d error: %2$s', 'The element tree has %1$d errors: %2$s', $result['error_count'], 'mcpmate'),
                    $result['error_count'],
                    TreeValidator::summarize($result['errors'], $result['error_count'])
                ),
                400,
                ElementorException::INVALID_TREE,
                __('Nothing was saved. Fix the errors and send the request again; dry_run: true lists every error.', 'mcpmate')
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function dryRun(array $result): array
    {
        return [
            'dry_run'       => true,
            'valid'         => $result['valid'],
            'element_count' => $result['element_count'],
            'error_count'   => $result['error_count'],
            'warning_count' => $result['warning_count'],
            'errors'        => $result['errors'],
            'warnings'      => $result['warnings'],
        ];
    }

    /**
     * The title and page settings the request changes, as Elementor names them.
     *
     * @return array<string, mixed>
     */
    private static function pageSettingChanges(Request $request, bool $withTitle = true): array
    {
        $changes  = [];
        $settings = $request->get('page_settings', []);

        if ($withTitle && $request->has('title')) {
            $changes['post_title'] = (string) $request->get('title');
        }

        foreach (is_array($settings) ? $settings : [] as $key => $value) {
            if (in_array($key, ElementorPages::PAGE_SETTINGS, true)) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    private function fromException(ElementorException $e): Response
    {
        return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
    }
}

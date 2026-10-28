<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks an element tree before Elementor saves it, and normalizes it.
 *
 * Elementor's own save drops elements whose type is not registered and keeps
 * any settings, so a bad tree loses content silently or opens broken in the
 * editor. This checks, for every element:
 *
 * - `elType` is container, section, column or widget, and registered
 *   (container needs Elementor's Flexbox Container feature);
 * - where it sits: the top level holds containers and sections, a section
 *   holds columns, a column holds widgets and one level of inner sections, a
 *   container holds containers and widgets, and only nested widgets (tabs,
 *   accordion, carousel…) hold containers;
 * - widgets are registered and allowed (Catalog::widgetStatus()); a denied
 *   widget that the stored page already has, unchanged, is kept;
 * - `id` is 1–32 letters or digits and unique (a missing one is generated);
 * - settings, through SettingsValidator;
 * - at most MAX_ELEMENTS elements, MAX_DEPTH levels deep.
 *
 * `isInner` is set from the element's place, as the editor sets it. Keys
 * other than id, elType, widgetType, settings, elements, isInner and
 * isLocked are dropped with a warning.
 */
final class TreeValidator
{
    public const MAX_ELEMENTS = 2000;

    public const MAX_DEPTH = 20;

    /** Issues returned per kind. */
    public const MAX_ISSUES = 100;

    public const ELEMENT_TYPES = ['container', 'section', 'column', 'widget'];

    public const ELEMENT_KEYS = ['id', 'elType', 'widgetType', 'settings', 'elements', 'isInner', 'isLocked'];

    public const ID_PATTERN = '/^[A-Za-z0-9]{1,32}$/';

    private Catalog $catalog;

    /** @var array<string, array<string, mixed>> Stored elements by id. */
    private array $stored = [];

    /** @var array<string, true> Ids sent in the tree, so generated ones never collide. */
    private array $givenIds = [];

    /** @var array<string, true> */
    private array $seenIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    private int $errorCount = 0;

    private int $warningCount = 0;

    private int $count = 0;

    private function __construct(Catalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * @param array<int, mixed> $elements The tree to save.
     * @param array<int, mixed> $stored   The tree as stored now ([] for a new page).
     * @return array{valid: bool, elements: array<int, array<string, mixed>>, element_count: int, error_count: int,
     *     warning_count: int, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public static function validate(array $elements, array $stored, Catalog $catalog): array
    {
        $validator = new self($catalog);
        $validator->index($stored);
        $validator->collectIds($elements);

        $out = [];
        foreach (array_values($elements) as $i => $element) {
            $checked = $validator->element($element, 'elements[' . $i . ']', null, 1);
            if ($checked !== null) {
                $out[] = $checked;
            }
        }

        return [
            'valid'         => $validator->errorCount === 0,
            'elements'      => $out,
            'element_count' => $validator->count,
            'error_count'   => $validator->errorCount,
            'warning_count' => $validator->warningCount,
            'errors'        => $validator->errors,
            'warnings'      => $validator->warnings,
        ];
    }

    /**
     * @param array<int, mixed> $elements
     */
    private function index(array $elements): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $id = ElementTree::idOf($element);
            if ($id !== '') {
                $this->stored[$id] = $element;
            }

            $this->index(ElementTree::children($element));
        }
    }

    /**
     * @param array<int, mixed> $elements
     */
    private function collectIds(array $elements): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $id = ElementTree::idOf($element);
            if ($id !== '') {
                $this->givenIds[$id] = true;
            }

            $this->collectIds(ElementTree::children($element));
        }
    }

    /**
     * Check one element and its children; null drops it (an error was recorded).
     *
     * @param mixed $element
     * @param array{elType: string, widgetType: string, isInner: bool}|null $parent
     * @return array<string, mixed>|null
     */
    private function element($element, string $path, ?array $parent, int $depth): ?array
    {
        if (!is_array($element) || ElementTree::isList($element)) {
            $this->error('invalid_element', __('Each element must be an object.', 'mcpmate'), $path, '', '');

            return null;
        }

        if ($depth > self::MAX_DEPTH) {
            /* translators: %d: maximum nesting depth. */
            $this->error('too_deep', sprintf(__('Elements can be nested at most %d levels deep.', 'mcpmate'), self::MAX_DEPTH), $path, '', '');

            return null;
        }

        if (++$this->count > self::MAX_ELEMENTS) {
            if ($this->count === self::MAX_ELEMENTS + 1) {
                /* translators: %d: maximum number of elements. */
                $this->error('too_many_elements', sprintf(__('A page can have at most %d elements.', 'mcpmate'), self::MAX_ELEMENTS), $path, '', '');
            }

            return null;
        }

        $id = $this->id($element, $path);

        foreach (array_keys($element) as $key) {
            if (!in_array($key, self::ELEMENT_KEYS, true)) {
                /* translators: %s: the key. */
                $this->warning('unknown_key', sprintf(__('"%s" is not an element key and was dropped.', 'mcpmate'), (string) $key), $path, $id, '');
            }
        }

        $elType = isset($element['elType']) && is_string($element['elType']) ? $element['elType'] : '';

        if (!in_array($elType, self::ELEMENT_TYPES, true)) {
            $this->error(
                'unknown_element_type',
                __('elType must be container, section, column or widget.', 'mcpmate'),
                $path,
                $id,
                ''
            );

            return null;
        }

        if ($elType !== 'widget' && !$this->catalog->hasElementType($elType)) {
            $this->error(
                'element_type_unavailable',
                /* translators: %s: element type. */
                sprintf(__('This site\'s Elementor has no "%s" element.', 'mcpmate'), $elType),
                $path,
                $id,
                $elType === 'container'
                    ? __('Turn on the Flexbox Container feature in Elementor → Settings → Features, or build with sections and columns.', 'mcpmate')
                    : ''
            );

            return null;
        }

        $widgetType = '';
        $stored     = $this->stored[$id] ?? null;
        if ($stored !== null && ($stored['elType'] ?? '') !== $elType) {
            $stored = null;
        }

        if ($elType === 'widget') {
            $widgetType = isset($element['widgetType']) && is_string($element['widgetType']) ? $element['widgetType'] : '';
            if ($stored !== null && ($stored['widgetType'] ?? '') !== $widgetType) {
                $stored = null;
            }

            if (!$this->widgetAllowed($element, $widgetType, $stored, $path, $id)) {
                return null;
            }
        }

        if (!$this->placementAllowed($elType, $widgetType, $parent, $path, $id)) {
            return null;
        }

        $isInner = false;
        if ($elType === 'section') {
            $isInner = $parent !== null;
        } elseif ($elType === 'container') {
            $isInner = $parent !== null;
        } elseif ($elType === 'column') {
            $isInner = $parent !== null && $parent['isInner'];
        }

        $settings = $element['settings'] ?? [];
        if (!is_array($settings) || ($settings !== [] && ElementTree::isList($settings))) {
            $this->error('invalid_settings', __('settings must be an object.', 'mcpmate'), $path, $id, '');

            return null;
        }

        $controls = $this->catalog->controls($elType, $widgetType);
        if ($controls !== null) {
            $storedSettings = $stored !== null && isset($stored['settings']) && is_array($stored['settings']) ? $stored['settings'] : [];
            $checked        = (new SettingsValidator($controls, $this->catalog->breakpoints(), $path, $id))->validate($settings, $storedSettings);
            $settings       = $checked['settings'];

            foreach ($checked['errors'] as $issue) {
                $this->add($this->errors, $this->errorCount, $issue);
            }
            foreach ($checked['warnings'] as $issue) {
                $this->add($this->warnings, $this->warningCount, $issue);
            }
        }

        $children = $element['elements'] ?? [];
        if (!is_array($children) || !ElementTree::isList($children)) {
            $this->error('invalid_children', __('elements must be a list.', 'mcpmate'), $path, $id, '');

            return null;
        }

        if ($elType === 'widget' && $children !== [] && !$this->catalog->acceptsChildren($widgetType)) {
            $this->error(
                'invalid_children',
                __('This widget cannot hold other elements.', 'mcpmate'),
                $path,
                $id,
                __('Put the elements next to the widget, in its container or column.', 'mcpmate')
            );

            return null;
        }

        $self = ['elType' => $elType, 'widgetType' => $widgetType, 'isInner' => $isInner];
        $out  = [];
        foreach ($children as $i => $child) {
            $checked = $this->element($child, $path . '.elements[' . $i . ']', $self, $depth + 1);
            if ($checked !== null) {
                $out[] = $checked;
            }
        }

        $normalized = [
            'id'       => $id,
            'elType'   => $elType,
            'settings' => $settings,
            'elements' => $out,
        ];

        if ($elType === 'widget') {
            $normalized['widgetType'] = $widgetType;
        } else {
            $normalized['isInner'] = $isInner;
        }

        if (isset($element['isLocked']) && is_bool($element['isLocked'])) {
            $normalized['isLocked'] = $element['isLocked'];
        }

        return $normalized;
    }

    /**
     * The element's id, or a new one when it has none.
     *
     * @param array<string, mixed> $element
     */
    private function id(array $element, string $path): string
    {
        if (!array_key_exists('id', $element) || $element['id'] === '' || $element['id'] === null) {
            do {
                $id = SettingsValidator::newId();
            } while (isset($this->givenIds[$id]) || isset($this->seenIds[$id]));

            $this->seenIds[$id] = true;

            return $id;
        }

        $id = is_string($element['id']) ? $element['id'] : '';

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            $this->error('invalid_id', __('id must be 1–32 letters or digits.', 'mcpmate'), $path, '', __('Leave "id" out to have one generated.', 'mcpmate'));
        } elseif (isset($this->seenIds[$id])) {
            /* translators: %s: element id. */
            $this->error(
                'duplicate_id',
                /* translators: %s: element id. */
                sprintf(__('Another element already has the id "%s".', 'mcpmate'), $id),
                $path,
                $id,
                __('Every element needs its own id; leave "id" out to have one generated.', 'mcpmate')
            );
        }

        $this->seenIds[$id] = true;

        return $id;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed>|null $stored The stored element with this id and widget type.
     */
    private function widgetAllowed(array $element, string $widgetType, ?array $stored, string $path, string $id): bool
    {
        $status = $widgetType === '' ? Catalog::WIDGET_UNKNOWN : $this->catalog->widgetStatus($widgetType);

        if ($status === Catalog::WIDGET_ALLOWED) {
            return true;
        }

        if ($status === Catalog::WIDGET_DENIED) {
            // Kept when the page already has it and nothing about it changes.
            if ($stored !== null && ($stored['settings'] ?? []) === ($element['settings'] ?? []) && ElementTree::children($element) === []) {
                return true;
            }

            $this->error(
                'widget_not_allowed',
                /* translators: %s: widget name. */
                sprintf(__('The "%s" widget cannot be added or changed through McpMate.', 'mcpmate'), $widgetType),
                $path,
                $id,
                __('It runs code, embeds other content or sends data elsewhere. A site admin can add it in the Elementor editor.', 'mcpmate')
            );

            return false;
        }

        $hint = $stored !== null
            ? __('The plugin that provides it is not active, and saving would drop it. Activate the plugin, or remove the element.', 'mcpmate')
            : __('GET /elementor/widgets lists the widgets this site has.', 'mcpmate');

        $suggestion = $widgetType !== '' ? $this->suggest($widgetType) : '';
        if ($stored === null && $suggestion !== '') {
            /* translators: %s: a registered widget name. */
            $hint = sprintf(__('Did you mean "%s"?', 'mcpmate'), $suggestion);
        }

        $this->error(
            'unknown_widget',
            $widgetType === ''
                ? __('A widget needs a widgetType.', 'mcpmate')
                /* translators: %s: widget name. */
                : sprintf(__('This site has no "%s" widget.', 'mcpmate'), SettingsValidator::clip($widgetType)),
            $path,
            $id,
            $hint
        );

        return false;
    }

    /**
     * @param array{elType: string, widgetType: string, isInner: bool}|null $parent
     */
    private function placementAllowed(string $elType, string $widgetType, ?array $parent, string $path, string $id): bool
    {
        $parentType = $parent === null ? '' : $parent['elType'];

        switch ($parentType) {
            case '':
                $allowed = in_array($elType, ['container', 'section'], true);
                $hint    = __('Put widgets inside a container (or a section\'s column); the top level holds containers and sections only.', 'mcpmate');
                break;
            case 'section':
                $allowed = $elType === 'column';
                $hint    = __('A section holds columns only; put the element inside a column.', 'mcpmate');
                break;
            case 'column':
                // One level of inner sections: a column of an inner section holds widgets only.
                $allowed = $elType === 'widget' || ($elType === 'section' && !$parent['isInner']);
                $hint    = __('A column holds widgets, and inner sections when it is not inside one already.', 'mcpmate');
                break;
            case 'container':
                $allowed = in_array($elType, ['container', 'widget'], true);
                $hint    = __('A container holds containers and widgets.', 'mcpmate');
                break;
            default:
                $allowed = $elType === 'container';
                $hint    = __('A nested widget (tabs, accordion…) holds one container per item.', 'mcpmate');
        }

        if ($elType === 'column' && $parentType !== 'section') {
            $allowed = false;
            $hint    = __('Columns go inside sections only; with containers, nest containers instead.', 'mcpmate');
        }

        if (!$allowed) {
            $what  = $widgetType !== '' ? $widgetType . ' widget' : $elType;
            $where = $parent !== null && $parent['widgetType'] !== '' ? $parent['widgetType'] . ' widget' : $parentType;

            $this->error(
                'invalid_parent',
                $parentType === ''
                    /* translators: %s: element type. */
                    ? sprintf(__('A %s cannot be at the top level.', 'mcpmate'), $what)
                    /* translators: 1: element type; 2: parent element type. */
                    : sprintf(__('A %1$s cannot be inside a %2$s.', 'mcpmate'), $what, $where),
                $path,
                $id,
                $hint
            );
        }

        return $allowed;
    }

    /**
     * A registered widget name at most 3 edits from $name.
     */
    private function suggest(string $name): string
    {
        $best     = '';
        $distance = 4;

        foreach ($this->catalog->widgetNames() as $candidate) {
            if ($this->catalog->widgetStatus($candidate) !== Catalog::WIDGET_ALLOWED) {
                continue;
            }

            $d = levenshtein($name, $candidate);
            if ($d < $distance) {
                $best     = $candidate;
                $distance = $d;
            }
        }

        return $best;
    }

    private function error(string $code, string $message, string $path, string $id, string $hint): void
    {
        $this->add($this->errors, $this->errorCount, [
            'code'       => $code,
            'message'    => $message,
            'path'       => $path,
            'element_id' => $id,
            'setting'    => '',
            'hint'       => $hint,
        ]);
    }

    private function warning(string $code, string $message, string $path, string $id, string $hint): void
    {
        $this->add($this->warnings, $this->warningCount, [
            'code'       => $code,
            'message'    => $message,
            'path'       => $path,
            'element_id' => $id,
            'setting'    => '',
            'hint'       => $hint,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @param array<string, mixed> $issue
     */
    private function add(array &$list, int &$count, array $issue): void
    {
        ++$count;

        if (count($list) < self::MAX_ISSUES) {
            $list[] = $issue;
        }
    }

    /**
     * The first errors as one line, for an error message.
     *
     * @param array<int, array<string, mixed>> $errors
     */
    public static function summarize(array $errors, int $total, int $shown = 5): string
    {
        $lines = [];

        foreach (array_slice($errors, 0, $shown) as $issue) {
            $where = (string) $issue['path'];
            if ($issue['setting'] !== '') {
                $where .= ' ' . $issue['setting'];
            }

            $line = $issue['code'] . ' at ' . $where . ': ' . $issue['message'];
            if ($issue['hint'] !== '') {
                $line .= ' ' . $issue['hint'];
            }

            $lines[] = $line;
        }

        if ($total > count($lines)) {
            /* translators: %d: number of errors not listed. */
            $lines[] = sprintf(__('…and %d more.', 'mcpmate'), $total - count($lines));
        }

        return implode(' | ', $lines);
    }
}

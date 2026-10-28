<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure operations on an Elementor element tree, the decoded `_elementor_data`
 * list: `[{id, elType, widgetType?, settings, elements, isInner?}, …]`.
 *
 * - applyPatches(): update settings by element id, insert, remove and move
 *   elements. All patches apply to a copy, in order; the first one that does
 *   not apply throws and nothing is changed.
 * - forReading(): the tree for GET, with long strings in settings replaced by
 *   a truncation marker (TRUNCATED_KEY). TreeValidator refuses a tree that
 *   still contains one, so a truncated value can never be saved back.
 * - version(): a short hash of the tree, for optimistic concurrency.
 *
 * Nothing here checks element types or settings; TreeValidator does.
 */
final class ElementTree
{
    /** Strings in settings longer than this are truncated by forReading(). */
    public const MAX_READ_CHARS = 1000;

    /** Characters kept in a truncated value's preview. */
    public const PREVIEW_CHARS = 200;

    /** Key of the object that replaces a truncated string. */
    public const TRUNCATED_KEY = '__mcpmate_truncated';

    public const MAX_PATCHES = 200;

    public const OPS = ['update', 'insert', 'remove', 'move'];

    /**
     * Short hash of the tree as stored; changes whenever any element changes.
     *
     * @param array<int, mixed> $elements
     */
    public static function version(array $elements): string
    {
        return substr(hash('sha256', (string) wp_json_encode($elements)), 0, 16);
    }

    /**
     * Number of elements in the tree, nested ones included.
     *
     * @param array<int, mixed> $elements
     */
    public static function count(array $elements): int
    {
        $count = 0;

        foreach ($elements as $element) {
            if (is_array($element)) {
                $count += 1 + self::count(self::children($element));
            }
        }

        return $count;
    }

    /**
     * The element with $id, or null.
     *
     * @param array<int, mixed> $elements
     * @return array<string, mixed>|null
     */
    public static function find(array $elements, string $id): ?array
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (self::idOf($element) === $id) {
                return $element;
            }

            $found = self::find(self::children($element), $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────

    /**
     * The tree for a read: long setting strings truncated, empty settings as
     * objects (JSON `{}`, not `[]`).
     *
     * @param array<int, mixed> $elements
     * @param string[] $truncated Receives `<element id>.<setting path>` of each truncated value.
     * @return array<int, mixed>
     */
    public static function forReading(array $elements, bool $truncate, array &$truncated = []): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $id       = self::idOf($element);
            $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];

            if ($truncate) {
                $settings = self::truncateValue($settings, $id, $truncated);
            }

            $element['settings'] = $settings === [] ? new \stdClass() : $settings;
            $element['elements'] = self::forReading(self::children($element), $truncate, $truncated);
            $out[]               = $element;
        }

        return $out;
    }

    /**
     * Whether $value contains a truncation marker at any depth.
     *
     * @param mixed $value
     */
    public static function hasTruncationMarker($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (array_key_exists(self::TRUNCATED_KEY, $value)) {
            return true;
        }

        foreach ($value as $item) {
            if (self::hasTruncationMarker($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param string[] $truncated
     * @return mixed
     */
    private static function truncateValue($value, string $path, array &$truncated)
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_READ_CHARS) {
            $truncated[] = $path;

            return [
                self::TRUNCATED_KEY => true,
                'length'            => mb_strlen($value),
                'preview'           => mb_substr($value, 0, self::PREVIEW_CHARS),
            ];
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::truncateValue($item, $path . '.' . $key, $truncated);
            }
        }

        return $value;
    }

    // ──────────────────────────────────────────────
    //  Patches
    // ──────────────────────────────────────────────

    /**
     * Apply $patches in order to a copy of $elements.
     *
     * - `{op: "update", id, settings: {key: value|null}}` merges settings; null removes the key.
     * - `{op: "insert", parent: id|null, position?, element}` adds an element (with any children).
     * - `{op: "remove", id}` removes an element and its children.
     * - `{op: "move", id, parent: id|null, position?}` moves an element.
     *
     * `parent: null` is the top level. `position` is the 0-based index among
     * the parent's children (after the moved element is taken out); left out,
     * the element goes last.
     *
     * @param array<int, mixed> $elements
     * @param array<int, mixed> $patches
     * @return array<int, mixed>
     * @throws ElementorException When a patch is malformed or does not apply.
     */
    public static function applyPatches(array $elements, array $patches): array
    {
        if (count($patches) > self::MAX_PATCHES) {
            throw self::patchError(
                /* translators: %d: maximum number of patches. */
                sprintf(esc_html__('At most %d patches can be applied at once.', 'mcpmate'), (int) self::MAX_PATCHES),
                esc_html__('Split the changes over several requests, or send the whole tree in "elements".', 'mcpmate')
            );
        }

        foreach (array_values($patches) as $index => $patch) {
            $elements = self::applyPatch($elements, $patch, $index);
        }

        return $elements;
    }

    /**
     * @param array<int, mixed> $elements
     * @param mixed $patch
     * @return array<int, mixed>
     */
    private static function applyPatch(array $elements, $patch, int $index): array
    {
        $op = is_array($patch) && isset($patch['op']) && is_string($patch['op']) ? $patch['op'] : '';

        if (!in_array($op, self::OPS, true)) {
            throw self::patchError(
                /* translators: %d: patch number, from 0. */
                sprintf(esc_html__('Patch %d has no valid "op".', 'mcpmate'), (int) $index),
                esc_html__('Each patch needs "op": one of update, insert, remove or move.', 'mcpmate')
            );
        }

        // The op check above means $patch is an array.
        /** @var array<string, mixed> $patch */
        switch ($op) {
            case 'update':
                return self::update($elements, $patch, $index);
            case 'insert':
                return self::insert($elements, $patch, $index);
            case 'remove':
                $id = self::patchId($elements, $patch, $index);

                return self::without($elements, $id);
            default:
                return self::move($elements, $patch, $index);
        }
    }

    /**
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $patch
     * @return array<int, mixed>
     */
    private static function update(array $elements, array $patch, int $index): array
    {
        $id      = self::patchId($elements, $patch, $index);
        $changes = $patch['settings'] ?? null;

        if (!is_array($changes) || ($changes !== [] && self::isList($changes))) {
            throw self::patchError(
                /* translators: %d: patch number, from 0. */
                sprintf(esc_html__('Patch %d (update) needs "settings" as an object.', 'mcpmate'), (int) $index),
                esc_html__('Send the settings to change as {"key": value}; null removes a setting.', 'mcpmate')
            );
        }

        return self::mapElement($elements, $id, static function (array $element) use ($changes): array {
            $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];

            foreach ($changes as $key => $value) {
                if ($value === null) {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
            }

            $element['settings'] = $settings;

            return $element;
        });
    }

    /**
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $patch
     * @return array<int, mixed>
     */
    private static function insert(array $elements, array $patch, int $index): array
    {
        $element = $patch['element'] ?? null;

        if (!is_array($element) || self::isList($element)) {
            throw self::patchError(
                /* translators: %d: patch number, from 0. */
                sprintf(esc_html__('Patch %d (insert) needs "element" as an object.', 'mcpmate'), (int) $index),
                esc_html__('Send the new element as {"elType": …, "settings": {…}, "elements": […]}.', 'mcpmate')
            );
        }

        $newId = self::idOf($element);
        if ($newId !== '' && self::find($elements, $newId) !== null) {
            throw self::patchError(
                /* translators: 1: patch number, from 0; 2: element id. */
                sprintf(esc_html__('Patch %1$d (insert): an element with id "%2$s" already exists.', 'mcpmate'), (int) $index, esc_html($newId)),
                esc_html__('Give the new element another id, or leave "id" out to have one generated.', 'mcpmate')
            );
        }

        return self::placeAt($elements, $element, self::parentOf($elements, $patch, $index), $patch['position'] ?? null, $index);
    }

    /**
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $patch
     * @return array<int, mixed>
     */
    private static function move(array $elements, array $patch, int $index): array
    {
        $id     = self::patchId($elements, $patch, $index);
        $parent = self::parentOf($elements, $patch, $index);
        /** @var array<string, mixed> $element patchId() checked that it exists. */
        $element = self::find($elements, $id);

        if ($parent !== null && ($parent === $id || self::find(self::children($element), $parent) !== null)) {
            throw self::patchError(
                /* translators: %d: patch number, from 0. */
                sprintf(esc_html__('Patch %d (move): an element cannot be moved into itself.', 'mcpmate'), (int) $index),
                esc_html__('Pick a parent outside the element being moved.', 'mcpmate')
            );
        }

        return self::placeAt(self::without($elements, $id), $element, $parent, $patch['position'] ?? null, $index);
    }

    /**
     * Insert $element into $parent's children (the top level for null) at $position.
     *
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $element
     * @param mixed $position
     * @return array<int, mixed>
     */
    private static function placeAt(array $elements, array $element, ?string $parent, $position, int $index): array
    {
        $place = static function (array $children) use ($element, $position, $index): array {
            $children = array_values($children);
            $count    = count($children);

            if ($position === null) {
                $position = $count;
            }

            if (!is_int($position) || $position < 0 || $position > $count) {
                throw self::patchError(
                    /* translators: 1: patch number, from 0; 2: largest allowed position. */
                    sprintf(esc_html__('Patch %1$d: "position" must be a whole number from 0 to %2$d.', 'mcpmate'), (int) $index, (int) $count),
                    esc_html__('Positions count from 0 among the parent\'s children; leave "position" out to add the element last.', 'mcpmate')
                );
            }

            array_splice($children, $position, 0, [$element]);

            return $children;
        };

        if ($parent === null) {
            return $place($elements);
        }

        return self::mapElement($elements, $parent, static function (array $target) use ($place): array {
            $target['elements'] = $place(self::children($target));

            return $target;
        });
    }

    /**
     * The tree without the element $id.
     *
     * @param array<int, mixed> $elements
     * @return array<int, mixed>
     */
    private static function without(array $elements, string $id): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (is_array($element) && self::idOf($element) === $id) {
                continue;
            }

            if (is_array($element) && isset($element['elements'])) {
                $element['elements'] = self::without(self::children($element), $id);
            }

            $out[] = $element;
        }

        return $out;
    }

    /**
     * The tree with $fn applied to the element $id.
     *
     * @param array<int, mixed> $elements
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     * @return array<int, mixed>
     */
    private static function mapElement(array $elements, string $id, callable $fn): array
    {
        foreach ($elements as $i => $element) {
            if (!is_array($element)) {
                continue;
            }

            if (self::idOf($element) === $id) {
                $elements[$i] = $fn($element);
            } elseif (isset($element['elements'])) {
                $element['elements'] = self::mapElement(self::children($element), $id, $fn);
                $elements[$i]        = $element;
            }
        }

        return $elements;
    }

    /**
     * The patch's `id`, which must name an element in the tree.
     *
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $patch
     */
    private static function patchId(array $elements, array $patch, int $index): string
    {
        $id = isset($patch['id']) && is_string($patch['id']) ? $patch['id'] : '';

        if ($id === '' || self::find($elements, $id) === null) {
            throw new ElementorException(
                /* translators: 1: patch number, from 0; 2: patch op. */
                sprintf(esc_html__('Patch %1$d (%2$s): no element has that id.', 'mcpmate'), (int) $index, esc_html((string) $patch['op'])),
                esc_html(ElementorException::ELEMENT_NOT_FOUND),
                400,
                esc_html__('Read the page again to get the current element ids.', 'mcpmate')
            );
        }

        return $id;
    }

    /**
     * The patch's `parent`: null for the top level, else an existing element's id.
     *
     * @param array<int, mixed> $elements
     * @param array<string, mixed> $patch
     */
    private static function parentOf(array $elements, array $patch, int $index): ?string
    {
        $parent = $patch['parent'] ?? null;

        if ($parent === null) {
            return null;
        }

        if (!is_string($parent) || self::find($elements, $parent) === null) {
            throw new ElementorException(
                /* translators: 1: patch number, from 0; 2: patch op. */
                sprintf(esc_html__('Patch %1$d (%2$s): the parent element does not exist.', 'mcpmate'), (int) $index, esc_html((string) $patch['op'])),
                esc_html(ElementorException::ELEMENT_NOT_FOUND),
                400,
                esc_html__('Use an existing element id as "parent", or null for the top level.', 'mcpmate')
            );
        }

        return $parent;
    }

    private static function patchError(string $message, string $hint): ElementorException
    {
        return new ElementorException(esc_html($message), esc_html(ElementorException::INVALID_PATCH), 400, esc_html($hint));
    }

    /**
     * Whether $value is a JSON array (0…n-1 keys) rather than an object.
     * array_is_list() needs PHP 8.1 (WordPress polyfills it only from 6.5).
     *
     * @param array<array-key, mixed> $value
     */
    public static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function idOf(array $element): string
    {
        return isset($element['id']) && is_string($element['id']) ? $element['id'] : '';
    }

    /**
     * @param array<string, mixed> $element
     * @return array<int, mixed>
     */
    public static function children(array $element): array
    {
        return isset($element['elements']) && is_array($element['elements']) ? array_values($element['elements']) : [];
    }
}

<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks one element's settings against its controls (pure).
 *
 * Only settings that differ from the stored element are checked, so a page
 * whose existing content predates these rules can still be saved. For each
 * new or changed value:
 *
 * - errors: a value whose shape does not match its control type (the editor
 *   cannot open it, or the CSS is wrong), a select/choose value outside the
 *   control's options, a `url` or `media` URL with a scheme other than
 *   http(s), mailto, tel or sms, a `code` control (custom CSS or HTML),
 *   dynamic tags (`__dynamic__`, which can run shortcodes or read other
 *   posts' fields), a `__globals__` reference that is not a global colour or
 *   font, a truncation marker from a read, or a string over MAX_STRING_BYTES;
 * - warnings: a key no control defines (Elementor keeps it and ignores it).
 *
 * Responsive variants (`padding_tablet`) resolve to their base control when
 * it is responsive, as Elementor's editor creates them. Repeater items get a
 * generated `_id` when they have none.
 */
final class SettingsValidator
{
    /** Longest string accepted in one setting (rich text included). */
    public const MAX_STRING_BYTES = 262144;

    /** Most items in a repeater or gallery. */
    public const MAX_ITEMS = 100;

    /** Control types that only lay out the panel and hold no value. */
    public const UI_TYPES = ['section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'alert', 'notice', 'deprecated_notice', 'button'];

    /** Control types McpMate never writes: they put raw code on the page. */
    public const CODE_TYPES = ['code'];

    /** URL schemes allowed in `url` and `media` values (besides relative URLs and #anchors). */
    public const URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms'];

    /** A `__globals__` value: the kit's global colours and fonts. */
    public const GLOBAL_REFERENCE = '/^globals\/(colors|typography)\?id=[A-Za-z0-9_-]{1,64}$/';

    private const COLOR = '/^(#[0-9a-fA-F]{3,8}|(rgb|rgba|hsl|hsla)\([0-9a-zA-Z%.,\s\/+-]{1,80}\)|[a-zA-Z]{3,30}|var\(--[A-Za-z0-9_-]{1,80}\))$/';

    /** @var array<string, array<string, mixed>> */
    private array $controls;

    /** @var string[] Longest first, so `mobile_extra` wins over `extra`. */
    private array $breakpoints;

    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    private string $path;

    private string $elementId;

    /**
     * @param array<string, array<string, mixed>> $controls
     * @param string[] $breakpoints
     */
    public function __construct(array $controls, array $breakpoints, string $path, string $elementId)
    {
        $this->controls  = $controls;
        $this->path      = $path;
        $this->elementId = $elementId;

        usort($breakpoints, static fn (string $a, string $b): int => strlen($b) - strlen($a));
        $this->breakpoints = $breakpoints;
    }

    /**
     * Check $settings; values equal to the same key in $original are skipped.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $original The stored element's settings ([] for a new element).
     * @return array{settings: array<string, mixed>, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function validate(array $settings, array $original): array
    {
        foreach ($settings as $key => $value) {
            $key = (string) $key;

            if (array_key_exists($key, $original) && $original[$key] === $value) {
                continue;
            }

            if (ElementTree::hasTruncationMarker($value)) {
                $this->error(
                    'truncated_value',
                    __('This value was shortened when the page was read and cannot be saved back.', 'mcpmate'),
                    $key,
                    __('Leave the setting out (an update patch keeps it), or read the element in full with element_id and send the whole value.', 'mcpmate')
                );
                continue;
            }

            if ($key === '__dynamic__') {
                $this->error(
                    'dynamic_tags_not_allowed',
                    __('Dynamic tags cannot be set through McpMate.', 'mcpmate'),
                    $key,
                    __('Write the value itself into the setting instead.', 'mcpmate')
                );
                continue;
            }

            if ($key === '__globals__') {
                $settings[$key] = $this->globals($value, isset($original[$key]) && is_array($original[$key]) ? $original[$key] : []);
                continue;
            }

            $control = $this->control($key);

            if ($control === null) {
                $this->warning(
                    'unknown_setting',
                    __('No control of this element uses this setting, so Elementor ignores it.', 'mcpmate'),
                    $key,
                    __('Check the control keys with GET /elementor/widgets?name=…', 'mcpmate')
                );
                continue;
            }

            $settings[$key] = $this->value($value, $control, $key, $original[$key] ?? null);
        }

        return ['settings' => $settings, 'errors' => $this->errors, 'warnings' => $this->warnings];
    }

    /**
     * The control for $key: its own, or the base control of a responsive variant.
     *
     * @return array<string, mixed>|null
     */
    private function control(string $key): ?array
    {
        $control = $this->controls[$key] ?? null;

        if ($control === null) {
            foreach ($this->breakpoints as $device) {
                $suffix = '_' . $device;

                if (strlen($key) > strlen($suffix) && str_ends_with($key, $suffix)) {
                    $base = $this->controls[substr($key, 0, -strlen($suffix))] ?? null;

                    if ($base !== null && (!empty($base['is_responsive']) || isset($base['responsive']))) {
                        $control = $base;
                    }

                    break;
                }
            }
        }

        if ($control === null || in_array($control['type'] ?? '', self::UI_TYPES, true)) {
            return null;
        }

        return $control;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $original
     * @return mixed
     */
    private function globals($value, array $original)
    {
        if (!is_array($value) || ($value !== [] && ElementTree::isList($value))) {
            $this->error('invalid_value', __('__globals__ must be an object of setting → global reference.', 'mcpmate'), '__globals__', '');

            return $value;
        }

        foreach ($value as $key => $reference) {
            if (array_key_exists($key, $original) && $original[$key] === $reference) {
                continue;
            }

            $setting = '__globals__.' . $key;

            if (!is_string($reference) || ($reference !== '' && preg_match(self::GLOBAL_REFERENCE, $reference) !== 1)) {
                $this->error(
                    'invalid_global',
                    __('A global reference must look like "globals/colors?id=primary" or "globals/typography?id=primary".', 'mcpmate'),
                    $setting,
                    __('Use one of the kit\'s global colours or fonts, or set the value itself instead.', 'mcpmate')
                );
            } elseif ($this->control((string) $key) === null) {
                $this->warning('unknown_setting', __('No control of this element uses this setting, so Elementor ignores it.', 'mcpmate'), $setting, '');
            }
        }

        return $value;
    }

    /**
     * Check $value against $control; returns it with repeater `_id`s filled in.
     *
     * @param mixed $value
     * @param array<string, mixed> $control
     * @param mixed $original The stored value, for repeaters.
     * @return mixed
     */
    private function value($value, array $control, string $key, $original)
    {
        $type = (string) ($control['type'] ?? '');

        if (in_array($type, self::CODE_TYPES, true)) {
            $this->error(
                'code_not_allowed',
                __('Custom code settings cannot be set through McpMate.', 'mcpmate'),
                $key,
                __('Use the element\'s style controls instead of custom CSS or HTML.', 'mcpmate')
            );

            return $value;
        }

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'hidden':
            case 'font':
            case 'popover_toggle':
            case 'animation':
            case 'hover_animation':
            case 'exit_animation':
            case 'date_time':
                $this->scalar($value, $key);
                break;

            case 'number':
                if ($value !== '' && !is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                    $this->invalid($key, __('a number', 'mcpmate'));
                }
                break;

            case 'color':
                if (!is_string($value) || ($value !== '' && preg_match(self::COLOR, $value) !== 1)) {
                    $this->invalid($key, __('a CSS colour such as "#1e293b" or "rgba(0,0,0,0.5)"', 'mcpmate'));
                }
                break;

            case 'select':
            case 'choose':
            case 'visual_choice':
                if ($this->scalar($value, $key)) {
                    $this->option($value, $control, $key);
                }
                break;

            case 'select2':
                if (!empty($control['multiple'])) {
                    if (!is_array($value) || !ElementTree::isList($value)) {
                        $this->invalid($key, __('a list of values', 'mcpmate'));
                        break;
                    }

                    foreach ($value as $item) {
                        if ($this->scalar($item, $key)) {
                            $this->option($item, $control, $key);
                        }
                    }
                } elseif ($this->scalar($value, $key)) {
                    $this->option($value, $control, $key);
                }
                break;

            case 'switcher':
                $on = isset($control['return_value']) ? (string) $control['return_value'] : 'yes';
                if ($value !== '' && $value !== $on) {
                    /* translators: %s: the value that switches the control on, e.g. "yes". */
                    $this->invalid($key, sprintf(__('"%s" (on) or "" (off)', 'mcpmate'), $on));
                }
                break;

            case 'slider':
                if ($this->object($value, $key, __('{"unit": "px", "size": 20}', 'mcpmate'))) {
                    $this->number($value['size'] ?? '', $key);
                    $this->unit($value['unit'] ?? null, $control, $key);
                }
                break;

            case 'dimensions':
                if ($this->object($value, $key, __('{"unit": "px", "top": "10", "right": "10", "bottom": "10", "left": "10", "isLinked": true}', 'mcpmate'))) {
                    foreach (['top', 'right', 'bottom', 'left'] as $side) {
                        $this->number($value[$side] ?? '', $key);
                    }
                    $this->unit($value['unit'] ?? null, $control, $key);
                }
                break;

            case 'gaps':
                if ($this->object($value, $key, __('{"unit": "px", "column": "20", "row": "20", "isLinked": true}', 'mcpmate'))) {
                    $this->number($value['column'] ?? '', $key);
                    $this->number($value['row'] ?? '', $key);
                    $this->unit($value['unit'] ?? null, $control, $key);
                }
                break;

            case 'url':
                if ($this->object($value, $key, __('{"url": "https://example.com", "is_external": "", "nofollow": ""}', 'mcpmate'))) {
                    $this->url($value['url'] ?? '', $key);
                }
                break;

            case 'media':
                if ($this->object($value, $key, __('{"id": 123, "url": "https://…/image.jpg"}', 'mcpmate'))) {
                    $this->media($value, $key);
                }
                break;

            case 'gallery':
                if (!is_array($value) || !ElementTree::isList($value) || count($value) > self::MAX_ITEMS) {
                    /* translators: %d: maximum number of items. */
                    $this->invalid($key, sprintf(__('a list of at most %d images, each {"id": 123, "url": "…"}', 'mcpmate'), self::MAX_ITEMS));
                    break;
                }

                foreach ($value as $image) {
                    if ($this->object($image, $key, __('{"id": 123, "url": "…"}', 'mcpmate'))) {
                        $this->media($image, $key);
                    }
                }
                break;

            case 'icons':
                if ($this->object($value, $key, __('{"value": "fas fa-star", "library": "fa-solid"}', 'mcpmate'))) {
                    $icon = $value['value'] ?? '';
                    if (is_array($icon)) {
                        $this->media($icon, $key);
                    } else {
                        $this->scalar($icon, $key);
                    }
                }
                break;

            case 'box_shadow':
            case 'text_shadow':
            case 'image_dimensions':
                $this->object($value, $key, '{…}');
                break;

            case 'repeater':
                return $this->repeater($value, $control, $key, $original);
        }

        // Other control types (add-ons' own) are kept as sent.
        return $value;
    }

    /**
     * Items are checked against the stored item with the same `_id`, so
     * unchanged fields are skipped there too.
     *
     * @param mixed $value
     * @param array<string, mixed> $control
     * @param mixed $original
     * @return mixed
     */
    private function repeater($value, array $control, string $key, $original)
    {
        if (!is_array($value) || !ElementTree::isList($value) || count($value) > self::MAX_ITEMS) {
            /* translators: %d: maximum number of items. */
            $this->invalid($key, sprintf(__('a list of at most %d items', 'mcpmate'), self::MAX_ITEMS));

            return $value;
        }

        $fields = isset($control['fields']) && is_array($control['fields']) ? $control['fields'] : [];
        $ids    = [];
        $stored = [];

        foreach (is_array($original) ? $original : [] as $item) {
            if (is_array($item) && isset($item['_id']) && is_string($item['_id'])) {
                $stored[$item['_id']] = $item;
            }
        }

        foreach ($value as $i => $item) {
            $itemKey = $key . '[' . $i . ']';

            if (!is_array($item) || ($item !== [] && ElementTree::isList($item))) {
                $this->invalid($itemKey, __('an object of the repeater\'s fields', 'mcpmate'));
                continue;
            }

            $id = $item['_id'] ?? '';
            if (!is_string($id) || preg_match('/^[A-Za-z0-9]{1,32}$/', $id) !== 1 || isset($ids[$id])) {
                $id = self::newId();
            }
            $ids[$id] = true;

            $fieldValidator = new self($fields, $this->breakpoints, $this->path, $this->elementId);
            $checked        = $fieldValidator->validate(array_diff_key($item, ['_id' => true]), $stored[$id] ?? []);

            foreach ($checked['errors'] as $issue) {
                $issue['setting'] = $itemKey . '.' . $issue['setting'];
                $this->errors[]   = $issue;
            }
            foreach ($checked['warnings'] as $issue) {
                $issue['setting'] = $itemKey . '.' . $issue['setting'];
                $this->warnings[] = $issue;
            }

            $value[$i] = ['_id' => $id] + $checked['settings'];
        }

        return $value;
    }

    // ──────────────────────────────────────────────
    //  Value checks
    // ──────────────────────────────────────────────

    /**
     * @param mixed $value
     */
    private function scalar($value, string $key): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $this->invalid($key, __('a string', 'mcpmate'));

            return false;
        }

        if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
            /* translators: %d: size in KiB. */
            $this->error('value_too_large', sprintf(__('The value is larger than %d KiB.', 'mcpmate'), self::MAX_STRING_BYTES / 1024), $key, '');

            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    private function number($value, string $key): void
    {
        if ($value !== '' && !is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            $this->invalid($key, __('numbers (or "") for its sizes', 'mcpmate'));
        }
    }

    /**
     * @param mixed $value
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private function object($value, string $key, string $example): bool
    {
        if (!is_array($value) || ($value !== [] && ElementTree::isList($value))) {
            /* translators: %s: example JSON value. */
            $this->invalid($key, sprintf(__('an object like %s', 'mcpmate'), $example));

            return false;
        }

        return true;
    }

    /**
     * @param string|int|float $value
     * @param array<string, mixed> $control
     */
    private function option($value, array $control, string $key): void
    {
        $options = isset($control['options']) && is_array($control['options']) ? array_map('strval', array_keys($control['options'])) : [];
        // Older values the control still maps, e.g. align "left" → "start".
        $legacy = isset($control['selectors_dictionary']) && is_array($control['selectors_dictionary']) ? array_map('strval', array_keys($control['selectors_dictionary'])) : [];

        if ($value === '' || $options === [] || in_array((string) $value, array_merge($options, $legacy), true)) {
            return;
        }

        $this->error(
            'invalid_option',
            /* translators: %s: the value sent. */
            sprintf(__('"%s" is not one of this control\'s options.', 'mcpmate'), self::clip((string) $value)),
            $key,
            /* translators: %s: comma-separated list of options. */
            sprintf(__('Use one of: %s.', 'mcpmate'), self::clip(implode(', ', $options), 300))
        );
    }

    /**
     * @param mixed $unit
     * @param array<string, mixed> $control
     */
    private function unit($unit, array $control, string $key): void
    {
        if ($unit === null || $unit === '') {
            return;
        }

        $units = isset($control['size_units']) && is_array($control['size_units']) ? $control['size_units'] : [];

        if (!is_string($unit) || ($units !== [] && !in_array($unit, $units, true))) {
            $this->error(
                'invalid_unit',
                __('The unit is not one this control accepts.', 'mcpmate'),
                $key,
                /* translators: %s: comma-separated list of units. */
                $units !== [] ? sprintf(__('Use one of: %s.', 'mcpmate'), implode(', ', array_map('strval', $units))) : ''
            );
        }
    }

    /**
     * @param mixed $url
     */
    private function url($url, string $key): void
    {
        if (!is_string($url)) {
            $this->invalid($key, __('a URL string', 'mcpmate'));

            return;
        }

        // Browsers ignore whitespace and control characters inside a scheme ("java\tscript:").
        $plain = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));

        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $plain, $match) === 1 && !in_array($match[1], self::URL_SCHEMES, true)) {
            $this->error(
                'url_not_allowed',
                __('This URL scheme is not allowed.', 'mcpmate'),
                $key,
                __('Use an http(s), mailto:, tel: or sms: link, a relative URL or a #anchor.', 'mcpmate')
            );
        }
    }

    /**
     * @param array<string, mixed> $media
     */
    private function media(array $media, string $key): void
    {
        $id = $media['id'] ?? '';
        if ($id !== '' && !is_int($id) && !(is_string($id) && ctype_digit($id))) {
            $this->invalid($key, __('a media object with a numeric "id"', 'mcpmate'));
        }

        $this->url($media['url'] ?? '', $key);
    }

    private function invalid(string $key, string $expected): void
    {
        $this->error(
            'invalid_value',
            /* translators: %s: description of the expected value. */
            sprintf(__('The value must be %s.', 'mcpmate'), $expected),
            $key,
            __('GET /elementor/widgets?name=… lists each control\'s type and options.', 'mcpmate')
        );
    }

    private function error(string $code, string $message, string $setting, string $hint): void
    {
        $this->errors[] = $this->issue($code, $message, $setting, $hint);
    }

    private function warning(string $code, string $message, string $setting, string $hint): void
    {
        $this->warnings[] = $this->issue($code, $message, $setting, $hint);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $code, string $message, string $setting, string $hint): array
    {
        return [
            'code'       => $code,
            'message'    => $message,
            'path'       => $this->path,
            'element_id' => $this->elementId,
            'setting'    => $setting,
            'hint'       => $hint,
        ];
    }

    /**
     * A new element or repeater item id: 7 lowercase hex characters, as the editor makes them.
     */
    public static function newId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    public static function clip(string $text, int $max = 100): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}

<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use Elementor\Core\Frontend\Performance;
use Elementor\Plugin as Elementor;

/**
 * The site's Elementor element and widget types, read from Elementor's
 * registries (`elements_manager`, `widgets_manager`, `breakpoints`).
 *
 * Widgets are allowed when registered and not denied. DENIED_WIDGETS and
 * DENIED_WIDGET_PREFIXES are never added or changed through McpMate: they
 * output raw HTML or run shortcodes or other plugins' widget code (rule 6),
 * embed other saved content by ID, change every page that uses them, send
 * form submissions to any address or URL, or set where payments go. The
 * `MCPMATE_elementor_denied_widgets` filter can deny more, never fewer.
 *
 * Controls are read with Elementor's style controls included (as its CSS
 * generator does), so style keys such as `typography_font_size` are listed.
 */
final class ElementCatalog implements Catalog
{
    public const DENIED_WIDGETS = [
        'html',             // Raw HTML and scripts.
        'shortcode',        // Runs any shortcode.
        'template',         // Embeds another saved template by ID.
        'global',           // A global widget: changes every page that uses it.
        'form',             // Submissions go to any email address or webhook URL.
        'paypal-button',    // Sets the account payments go to.
        'stripe-button',
        'e-component',      // Atomic components (Elementor 4), a different data format.
        'inner-section',    // The editor's placeholder; use elType "section" in a column.
        'common',           // Shared control stacks, not placeable.
        'common-base',
        'common-optimized',
    ];

    /** WordPress widgets wrapped by Elementor (custom HTML, block, text…). */
    public const DENIED_WIDGET_PREFIXES = ['wp-widget-'];

    /** Element types other than widgets, as TreeValidator knows them. */
    public const ELEMENT_TYPES = ['container', 'section', 'column'];

    /** @var array<string, array<string, array<string, mixed>>|null> */
    private array $controls = [];

    /** @var string[]|null */
    private ?array $denied = null;

    /** @var string[]|null */
    private ?array $breakpoints = null;

    public function hasElementType(string $elType): bool
    {
        return in_array($elType, self::ELEMENT_TYPES, true)
            && Elementor::$instance->elements_manager->get_element_types($elType) !== null;
    }

    public function widgetStatus(string $widgetType): string
    {
        if (!$this->widget($widgetType)) {
            return Catalog::WIDGET_UNKNOWN;
        }

        return $this->isDenied($widgetType) ? Catalog::WIDGET_DENIED : Catalog::WIDGET_ALLOWED;
    }

    public function widgetNames(): array
    {
        return array_map('strval', array_keys(Elementor::$instance->widgets_manager->get_widget_types()));
    }

    public function acceptsChildren(string $widgetType): bool
    {
        $widget = $this->widget($widgetType);

        return $widget instanceof \Elementor\Modules\NestedElements\Base\Widget_Nested_Base;
    }

    public function controls(string $elType, string $widgetType = ''): ?array
    {
        $key = $elType === 'widget' ? 'widget:' . $widgetType : $elType;

        if (!array_key_exists($key, $this->controls)) {
            $element = $elType === 'widget' ? $this->widget($widgetType) : Elementor::$instance->elements_manager->get_element_types($elType);

            $this->controls[$key] = $element ? self::readControls($element) : null;
        }

        return $this->controls[$key];
    }

    public function breakpoints(): array
    {
        if ($this->breakpoints === null) {
            $this->breakpoints = array_map('strval', array_keys(Elementor::$instance->breakpoints->get_active_breakpoints()));
        }

        return $this->breakpoints;
    }

    public function isDenied(string $widgetType): bool
    {
        if ($this->denied === null) {
            /**
             * Widgets McpMate never adds or changes, beyond its own list.
             * Names returned here are added to DENIED_WIDGETS; none can be removed.
             *
             * @param string[] $widgets Denied widget names.
             */
            // Another plugin's callback may return anything; only strings are names.
            $extra        = (array) Hooks::applyFilter(Config::withPrefix('elementor_denied_widgets'), self::DENIED_WIDGETS);
            $this->denied = array_values(array_unique(array_merge(self::DENIED_WIDGETS, array_filter($extra, 'is_string'))));
        }

        if (in_array($widgetType, $this->denied, true)) {
            return true;
        }

        foreach (self::DENIED_WIDGET_PREFIXES as $prefix) {
            if (strpos($widgetType, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  Listing (GET /elementor/widgets)
    // ──────────────────────────────────────────────

    /**
     * Element types and allowed widgets the editor shows, filtered and sorted
     * by kind (elements first) and name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listing(string $search, string $category): array
    {
        $items = [];

        foreach (self::ELEMENT_TYPES as $elType) {
            $element = Elementor::$instance->elements_manager->get_element_types($elType);
            if ($element) {
                $items[] = $this->summary($element, 'element', $elType);
            }
        }

        foreach (Elementor::$instance->widgets_manager->get_widget_types() as $name => $widget) {
            $name = (string) $name;

            if ($this->isDenied($name) || !$widget->show_in_panel()) {
                continue;
            }

            $items[] = $this->summary($widget, 'widget', $name);
        }

        $search = strtolower($search);

        return array_values(array_filter($items, static function (array $item) use ($search, $category): bool {
            if ($category !== '' && !in_array($category, $item['categories'], true)) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = strtolower($item['name'] . ' ' . $item['title'] . ' ' . implode(' ', $item['keywords']));

            return strpos($haystack, $search) !== false;
        }));
    }

    /**
     * One element or allowed widget with its controls; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $name, string $tab): ?array
    {
        if (in_array($name, self::ELEMENT_TYPES, true)) {
            $element = Elementor::$instance->elements_manager->get_element_types($name);
            $kind    = 'element';
        } else {
            $element = $this->isDenied($name) ? null : $this->widget($name);
            $kind    = 'widget';
        }

        if (!$element) {
            return null;
        }

        $item = $this->summary($element, $kind, $name);

        $item['accepts_children'] = $kind === 'widget' && $this->acceptsChildren($name);
        $item['controls']         = [];
        $item['breakpoints']      = $this->breakpoints();

        foreach (self::readControls($element) as $key => $control) {
            if (in_array($control['type'] ?? '', SettingsValidator::UI_TYPES, true)) {
                continue;
            }

            if ($tab !== '' && ($control['tab'] ?? '') !== $tab) {
                continue;
            }

            $item['controls'][] = self::describeControl($key, $control);
        }

        return $item;
    }

    /**
     * @param \Elementor\Element_Base $element
     * @return array<string, mixed>
     */
    private function summary($element, string $kind, string $name): array
    {
        $categories = $kind === 'widget' && method_exists($element, 'get_categories') ? (array) $element->get_categories() : [];
        $keywords   = $kind === 'widget' && method_exists($element, 'get_keywords') ? (array) $element->get_keywords() : [];

        return [
            'name'       => $name,
            'kind'       => $kind,
            'title'      => wp_strip_all_tags((string) $element->get_title()),
            'categories' => array_values(array_map('strval', $categories)),
            'keywords'   => array_values(array_map('strval', $keywords)),
        ];
    }

    /**
     * A control as GET /elementor/widgets?name= lists it.
     *
     * @param array<string, mixed> $control
     * @return array<string, mixed>
     */
    public static function describeControl(string $key, array $control): array
    {
        $out = [
            'key'   => $key,
            'type'  => (string) ($control['type'] ?? ''),
            'tab'   => (string) ($control['tab'] ?? ''),
            'label' => isset($control['label']) && is_string($control['label']) ? wp_strip_all_tags($control['label']) : '',
        ];

        if (!empty($control['is_responsive']) || isset($control['responsive'])) {
            $out['responsive'] = true;
        }

        if (isset($control['default']) && $control['default'] !== '' && $control['default'] !== []) {
            $out['default'] = $control['default'];
        }

        if (!empty($control['options']) && is_array($control['options'])) {
            $out['options'] = array_slice(array_map('strval', array_keys($control['options'])), 0, 60);
        }

        if (!empty($control['size_units']) && is_array($control['size_units'])) {
            $out['size_units'] = array_values($control['size_units']);
        }

        if (isset($control['return_value']) && $out['type'] === 'switcher') {
            $out['return_value'] = (string) $control['return_value'];
        }

        if (!empty($control['multiple'])) {
            $out['multiple'] = true;
        }

        if (!empty($control['groupType'])) {
            $out['group'] = (string) $control['groupType'];
        }

        if (!empty($control['global']['default']) && is_string($control['global']['default'])) {
            $out['global_default'] = $control['global']['default'];
        }

        if (!empty($control['condition']) && is_array($control['condition'])) {
            $out['condition'] = $control['condition'];
        }

        if (in_array($out['type'], SettingsValidator::CODE_TYPES, true)) {
            $out['writable'] = false;
        }

        if ($out['type'] === 'repeater' && isset($control['fields']) && is_array($control['fields'])) {
            $out['fields'] = [];
            foreach ($control['fields'] as $fieldKey => $field) {
                if (is_array($field) && $fieldKey !== '_id' && !in_array($field['type'] ?? '', SettingsValidator::UI_TYPES, true)) {
                    $out['fields'][] = self::describeControl((string) $fieldKey, $field);
                }
            }
        }

        return $out;
    }

    /**
     * @param \Elementor\Controls_Stack $element
     * @return array<string, array<string, mixed>>
     */
    private static function readControls($element): array
    {
        // Elementor leaves style controls out on front-end requests; its CSS generator switches them on the same way.
        $before = Performance::is_use_style_controls();
        Performance::set_use_style_controls(true);

        try {
            $controls = $element->get_controls();
        } finally {
            Performance::set_use_style_controls($before);
        }

        return is_array($controls) ? $controls : [];
    }

    /**
     * @return \Elementor\Widget_Base|null
     */
    private function widget(string $widgetType)
    {
        if ($widgetType === '') {
            return null;
        }

        $widget = Elementor::$instance->widgets_manager->get_widget_types($widgetType);

        return $widget instanceof \Elementor\Widget_Base ? $widget : null;
    }
}

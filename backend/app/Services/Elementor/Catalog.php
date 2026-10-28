<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What TreeValidator needs to know about the site's Elementor: which element
 * and widget types exist, which widgets may be added, and their controls.
 * ElementCatalog reads it from Elementor; unit tests pass a fixed one.
 */
interface Catalog
{
    public const WIDGET_ALLOWED = 'allowed';

    public const WIDGET_DENIED = 'denied';

    public const WIDGET_UNKNOWN = 'unknown';

    /**
     * Whether a non-widget element type (container, section, column) is
     * registered. `container` is only registered when Elementor's Flexbox
     * Container feature is active.
     */
    public function hasElementType(string $elType): bool;

    /**
     * WIDGET_ALLOWED, WIDGET_DENIED (registered, but McpMate never adds it)
     * or WIDGET_UNKNOWN (not registered).
     */
    public function widgetStatus(string $widgetType): string;

    /**
     * Registered widget names, for "did you mean" hints.
     *
     * @return string[]
     */
    public function widgetNames(): array;

    /**
     * Whether a widget holds child containers (nested tabs, accordion, carousel…).
     */
    public function acceptsChildren(string $widgetType): bool;

    /**
     * The element's controls keyed by setting name, each with at least
     * `type`; null when the type is unknown.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function controls(string $elType, string $widgetType = ''): ?array;

    /**
     * Active breakpoint names other than desktop (`mobile`, `tablet`…): the
     * suffixes of responsive setting keys such as `padding_tablet`.
     *
     * @return string[]
     */
    public function breakpoints(): array;
}

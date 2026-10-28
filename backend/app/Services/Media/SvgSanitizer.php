<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rewrites an SVG file so that nothing in it runs or loads anything.
 *
 * The file is parsed as XML (as browsers parse SVG files) and rebuilt from
 * allowlists, so unknown constructs are dropped rather than missed:
 *
 * - Elements: only ELEMENTS in the SVG namespace stay. `script`,
 *   `foreignObject`, `iframe`, `embed`, `object`, animation elements
 *   (`animate`, `set`, …, which can rewrite `href` to `javascript:`) and
 *   anything in another namespace (`html:script`) are removed with their
 *   content. Comments and processing instructions (`<?xml-stylesheet?>`) go too.
 * - Attributes: event handlers (`on*`) are removed, as are attributes in
 *   other namespaces and `xml:base`.
 * - External references: `href` / `xlink:href` must point inside the file
 *   (`#id`); `image`/`feImage` may also embed a raster `data:image/…;base64`.
 *   A `url(…)` that is not `url(#id)`, `@import`, `javascript:`, CSS
 *   expressions and CSS escapes remove the attribute, or the whole `<style>`.
 * - A DOCTYPE (and with it every entity declaration: XXE, billion laughs)
 *   rejects the file, and the parser never touches the network.
 */
final class SvgSanitizer
{
    public const SVG_NS = 'http://www.w3.org/2000/svg';

    public const XLINK_NS = 'http://www.w3.org/1999/xlink';

    public const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /** Elements that stay (static SVG 1.1/2 graphics). Matched case-sensitively, as XML is. */
    public const ELEMENTS = [
        'svg', 'g', 'defs', 'desc', 'title', 'metadata', 'symbol', 'use', 'image', 'switch', 'a', 'view', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix',
        'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA',
        'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology',
        'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
    ];

    /** Elements whose `href` may embed a raster image. */
    private const IMAGE_ELEMENTS = ['image', 'feImage'];

    /** `#id` inside the same file. */
    private const FRAGMENT = '/^#[A-Za-z_][\w.:-]*$/D';

    private const RASTER_DATA_URI = '#^data:image/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]+$#D';

    /** CSS (in `style` attributes, presentation attributes and `<style>`) that loads or runs something. */
    private const UNSAFE_CSS = '/@import|expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|\\\\|<\//i';

    private const LOCAL_URL = '/url\(\s*([\'"]?)#[\w.:-]+\1\s*\)/i';

    private int $removedNodes = 0;

    private int $removedAttributes = 0;

    /**
     * @return array{svg: string, removed_nodes: int, removed_attributes: int}
     * @throws MediaException When the file is not a well-formed SVG, or has a DOCTYPE.
     */
    public static function sanitize(string $svg): array
    {
        return (new self())->run($svg);
    }

    /**
     * @return array{svg: string, removed_nodes: int, removed_attributes: int}
     */
    private function run(string $svg): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new MediaException(
                esc_html__('SVG files cannot be checked on this server.', 'mcpmate'),
                esc_html(MediaException::SVG_INVALID),
                415,
                esc_html__('The PHP DOM extension is missing. Upload a PNG or JPEG instead.', 'mcpmate')
            );
        }

        if (trim($svg) === '') {
            throw self::invalid(esc_html__('The SVG file is empty.', 'mcpmate'));
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            throw self::invalid(esc_html__('SVG files with a DOCTYPE or entity declarations are not accepted.', 'mcpmate'));
        }

        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No LIBXML_NOENT (entities are never expanded) and no network access.
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Checked on the parsed tree too: another encoding (UTF-16) hides the text from the check above.
        if (!$loaded || $doc->doctype !== null) {
            throw self::invalid(esc_html__('The SVG file is not well-formed XML.', 'mcpmate'));
        }

        $root = $doc->documentElement;

        if (!$root instanceof \DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            throw self::invalid(esc_html__('The file is not an SVG image.', 'mcpmate'));
        }

        // Comments and processing instructions next to the root are not serialized below.
        foreach (self::children($doc) as $node) {
            if ($node !== $root) {
                $this->removedNodes++;
            }
        }

        $this->cleanElement($root);

        $out = $doc->saveXML($root);

        if (!is_string($out) || $out === '') {
            throw self::invalid(esc_html__('The SVG file could not be rewritten.', 'mcpmate'));
        }

        return [
            'svg'                => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n",
            'removed_nodes'      => $this->removedNodes,
            'removed_attributes' => $this->removedAttributes,
        ];
    }

    private function cleanElement(\DOMElement $element): void
    {
        $this->cleanAttributes($element);

        foreach (self::children($element) as $child) {
            if ($child instanceof \DOMElement) {
                if ($child->namespaceURI !== self::SVG_NS || !in_array($child->localName, self::ELEMENTS, true)) {
                    $this->remove($child);
                    continue;
                }

                if ($child->localName === 'style' && !self::isSafeCss($child->textContent)) {
                    $this->remove($child);
                    continue;
                }

                $this->cleanElement($child);
            } elseif (!$child instanceof \DOMText) {
                // Comments, processing instructions, entity references. DOMCdataSection is a DOMText.
                $this->remove($child);
            }
        }
    }

    private function cleanAttributes(\DOMElement $element): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            if ($this->isSafeAttribute($element, $attribute)) {
                continue;
            }

            $element->removeAttributeNode($attribute);
            $this->removedAttributes++;
        }
    }

    private function isSafeAttribute(\DOMElement $element, \DOMAttr $attribute): bool
    {
        $namespace = $attribute->namespaceURI;
        $name      = strtolower((string) $attribute->localName);
        $value     = (string) $attribute->value;

        if ($namespace !== null && $namespace !== self::XLINK_NS && $namespace !== self::XML_NS) {
            return false;
        }

        if (str_starts_with($name, 'on') || ($namespace === self::XML_NS && $name === 'base')) {
            return false;
        }

        if ($name === 'href') {
            $trimmed = trim($value);

            return preg_match(self::FRAGMENT, $trimmed)
                || (in_array($element->localName, self::IMAGE_ELEMENTS, true) && preg_match(self::RASTER_DATA_URI, $trimmed));
        }

        // Whitespace and control characters are ignored by URL parsers (`java\tscript:`).
        $compact = (string) preg_replace('/[\s\x00-\x1F]+/', '', $value);

        if (preg_match('/(?:javascript|vbscript|data):/i', $compact)) {
            return false;
        }

        return self::isSafeCss($value);
    }

    /**
     * No @import, script URL, expression or escape, and no `url(…)` other than `url(#id)`.
     */
    private static function isSafeCss(string $css): bool
    {
        $rest = (string) preg_replace(self::LOCAL_URL, '', $css);

        return !preg_match(self::UNSAFE_CSS, $css) && !preg_match('/url\s*\(/i', $rest);
    }

    /**
     * A copy of $node's children: removing a child while iterating childNodes
     * skips its sibling.
     *
     * @return \DOMNode[]
     */
    private static function children(\DOMNode $node): array
    {
        $children = [];

        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            $children[] = $child;
        }

        return $children;
    }

    private function remove(\DOMNode $node): void
    {
        if ($node->parentNode !== null) {
            $node->parentNode->removeChild($node);
        }

        $this->removedNodes++;
    }

    private static function invalid(string $message): MediaException
    {
        return new MediaException(
            esc_html($message),
            esc_html(MediaException::SVG_INVALID),
            400,
            esc_html__('Send a well-formed SVG file with an <svg> root in the SVG namespace and no DOCTYPE, or a PNG instead.', 'mcpmate')
        );
    }
}

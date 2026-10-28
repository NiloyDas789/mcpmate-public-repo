<?php

namespace McpMate\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns an escaped exception message back into plain text for a JSON answer.
 *
 * The CLI, ability, approval, database inspection, media, theme draft and
 * Elementor services escape their exception messages and hints where they
 * are thrown (esc_html__(), esc_html()), as WordPress.org's Plugin Check
 * requires. None of them reaches HTML: REST envelopes, admin-ajax JSON, MCP
 * tool output and stored approval outcomes carry them as JSON strings, and
 * the admin app renders them as text. Each of those boundaries decodes them
 * once here, so an AI client reads "Can't", not "Can&#039;t".
 *
 * Never echo the result into HTML.
 */
final class ErrorPresenter
{
    /**
     * The exception's message as plain text.
     */
    public static function plain(\Throwable $e): string
    {
        return self::text($e->getMessage());
    }

    /**
     * An escaped message or hint as plain text.
     */
    public static function text(string $escaped): string
    {
        return html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

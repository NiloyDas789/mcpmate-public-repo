<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * get_page_html → GET page-html (cloud getPageHtml.ts).
 */
final class GetPageHtmlTool extends Tool
{
    public const MAX_HTML_BYTES = 18000;

    /** PageFetcher::MIN_BYTES / MAX_BYTES. */
    private const PLUGIN_MIN_BYTES = 1024;

    private const PLUGIN_MAX_BYTES = 200000;

    public function run(\stdClass $args): ToolResult
    {
        $url    = $args->url ?? null;
        $postId = $args->post_id ?? null;

        if (($url === null) === ($postId === null)) {
            return ToolResult::invalidInput('Give either url or post_id, not both or neither.');
        }

        // Collapsing whitespace shrinks most pages a lot, so ask for more than will be shown.
        $fetch = min(self::PLUGIN_MAX_BYTES, max(self::PLUGIN_MIN_BYTES, $args->max_bytes * 2));
        $reply = $this->client->pageHtml($url === null ? ['post_id' => $postId] : ['url' => $url], $args->strip_scripts, $fetch);

        return self::out(self::format($this->siteId, $reply['data'], $args->max_bytes));
    }

    /**
     * Blank lines, indentation and trailing spaces removed: cheap for a model, same markup.
     */
    public static function collapseWhitespace(string $html): string
    {
        $html = (string) preg_replace('/\r\n?/', "\n", $html);
        $html = (string) preg_replace('/[ \t]+\n/', "\n", $html);
        $html = (string) preg_replace('/\n[ \t]+/', "\n", $html);
        $html = (string) preg_replace('/\n{2,}/', "\n", $html);
        $html = (string) preg_replace('/[ \t]{2,}/', ' ', $html);

        return Js::trim($html);
    }

    /**
     * @param array<string, mixed> $page
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $page, int $maxBytes): array
    {
        $collapsed = self::collapseWhitespace($page['html']);
        $html      = C::clipBytes($collapsed, $maxBytes);
        $cut       = $page['html_truncated'] || Js::length($html) < Js::length($collapsed);
        $shown     = strlen($html);

        $structured = [
            'site_id'          => $siteId,
            'url'              => $page['url'],
            'final_url'        => $page['final_url'],
            'status_code'      => $page['status_code'],
            'content_type'     => $page['content_type'],
            'redirects'        => $page['redirects'],
            'html'             => $html,
            'page_bytes'       => $page['bytes'],
            'shown_bytes'      => $shown,
            'html_truncated'   => $cut,
            'scripts_stripped' => $page['scripts_stripped'],
            'nonces_redacted'  => $page['nonces_redacted'],
        ];

        $redirects = $page['redirects'] === [] ? '' : ' after ' . C::plural(count($page['redirects']), 'redirect');
        $notes     = array_values(array_filter([
            $cut ? 'Showing the first ' . $shown . ' bytes (whitespace collapsed) of a ' . $page['bytes'] . '-byte page.' : 'Whole page shown (' . $shown . ' bytes, whitespace collapsed).',
            $page['scripts_stripped'] > 0 ? C::plural($page['scripts_stripped'], 'script') . ' stripped.' : '',
            $page['nonces_redacted'] > 0 ? C::plural($page['nonces_redacted'], 'nonce') . ' redacted.' : '',
        ], static fn (string $note): bool => $note !== ''));
        $finalUrl = C::oneLine($page['final_url'], 300);
        $header   = 'Page ' . $finalUrl . ': HTTP ' . $page['status_code'] . ' ' . C::oneLine($page['content_type'], 100) . $redirects . '. ' . implode(' ', $notes);

        $next = null;
        if ($page['status_code'] >= 400) {
            $next = 'The page answered with an error status; check the URL (or use post_id) before reading anything into its HTML.';
        } elseif ($cut && $maxBytes < self::MAX_HTML_BYTES) {
            $next = 'The page is longer than shown. Call again with max_bytes up to ' . self::MAX_HTML_BYTES . ' to see more of it.';
        } elseif ($cut) {
            $next = 'The page is longer than this tool returns; work from the part shown, which is the start of the document.';
        }

        return ['text' => C::finish([$header, C::siteData('HTML of ' . $finalUrl, $html)], $next), 'structured' => $structured];
    }
}

<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP's time limit (docs/mcp-in-plugin.md §10.5, check 7). A tool call is
 * one PHP request; if PHP or the web server stops it half-way, the AI app
 * sees a dropped connection although the work may have finished.
 */
final class ExecutionTimeCheck extends Check
{
    public const ID = 'execution_time';

    /** Seconds from which every tool has room. */
    public const ENOUGH = 120;

    /** Seconds below which ordinary tools can be cut off. */
    public const TOO_SHORT = 30;

    /** Tools that can run long (uploads, page builds, search-replace, page fetches). */
    private const LONG_TOOLS = ['upload_media', 'elementor_save', 'run_wp_cli search-replace', 'get_page_html'];

    private int $seconds;

    private bool $canRaise;

    /**
     * @param string $maxExecutionTime ini_get('max_execution_time').
     * @param string $disabledFunctions ini_get('disable_functions').
     */
    public function __construct(string $maxExecutionTime, string $disabledFunctions)
    {
        $this->seconds  = max(0, (int) $maxExecutionTime);
        $this->canRaise = !in_array('set_time_limit', array_map('trim', explode(',', strtolower($disabledFunctions))), true);
    }

    public function run(): array
    {
        $title = __('Requests have enough time to finish', 'mcpmate');

        if ($this->seconds === 0 || $this->seconds >= self::ENOUGH) {
            return self::result(
                self::PASS,
                $title,
                $this->seconds === 0
                    ? __('PHP has no time limit on this site.', 'mcpmate')
                    /* translators: %d: seconds. */
                    : sprintf(__('PHP may run for %d seconds per request.', 'mcpmate'), $this->seconds)
            );
        }

        $details = [
            ['text' => __('Raise max_execution_time to 120 or more (in php.ini, .user.ini or your host\'s PHP settings).', 'mcpmate')],
            ['text' => __('With PHP-FPM, also raise request_terminate_timeout; behind a proxy, its read timeout (Cloudflare stops at 100 seconds).', 'mcpmate')],
        ];

        if (!$this->canRaise) {
            $details[] = ['text' => __('Your host turned off set_time_limit, so McpMate can\'t ask for more time itself.', 'mcpmate')];
        }

        if ($this->seconds < self::TOO_SHORT) {
            return self::result(
                self::FAIL,
                $title,
                /* translators: %d: seconds. */
                sprintf(__('PHP stops every request after %d seconds. Ordinary AI requests can be cut off half-way, after the change was already made.', 'mcpmate'), $this->seconds),
                __('Ask your host to raise the limit, or change it yourself:', 'mcpmate'),
                $details
            );
        }

        return self::result(
            self::WARN,
            $title,
            sprintf(
                /* translators: 1: seconds, 2: comma-separated tool names. */
                __('PHP stops every request after %1$d seconds. Long jobs (%2$s) can be cut off half-way, after the change was already made.', 'mcpmate'),
                $this->seconds,
                implode(', ', self::LONG_TOOLS)
            ),
            __('You can continue. To give long jobs room:', 'mcpmate'),
            $details
        );
    }
}

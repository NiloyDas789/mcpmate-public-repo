<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One row of the wizard's "Check your site" step.
 *
 * A check is given every fact it needs in its constructor (EnvironmentChecks
 * gathers them: HTTP calls, options, versions) and run() only decides, so
 * each check is unit-tested without WordPress or the network. run() never
 * throws for a failed check: a problem is a `warn` or `fail` result with a
 * fix.
 *
 * Statuses: `pass`, `warn` (can continue), `fail` (fix first), `info`
 * (nothing to judge yet) and `later` (tested in a later step).
 */
abstract class Check
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const INFO = 'info';

    public const LATER = 'later';

    /** Stable row ID, e.g. `https`. */
    public const ID = '';

    /**
     * @return array{status: string, title: string, message: string, fix: string, details: array<int, array{text: string, code?: string}>}
     */
    abstract public function run(): array;

    /**
     * @param array<int, array{text: string, code?: string}> $details Extra lines; `code` is shown with a Copy button.
     * @return array{status: string, title: string, message: string, fix: string, details: array<int, array{text: string, code?: string}>}
     */
    protected static function result(string $status, string $title, string $message, string $fix = '', array $details = []): array
    {
        return [
            'status'  => $status,
            'title'   => $title,
            'message' => $message,
            'fix'     => $fix,
            'details' => $details,
        ];
    }

    /**
     * The host of $url, lowercase, or ''.
     */
    protected static function host(string $url): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, unit-tested without WordPress.
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}

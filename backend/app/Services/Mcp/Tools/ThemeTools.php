<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Url;

/**
 * Shared by the theme draft tools (cloud theme.ts): paths checked the way
 * DraftPath checks them, so a path that can only be refused never reaches
 * the route (the route is the real check), and the publish / rollback
 * plan formatter.
 */
final class ThemeTools
{
    /** DraftPath::EXTENSIONS, MAX_LENGTH and MAX_DEPTH. */
    public const EXTENSIONS = ['php', 'css', 'js', 'json', 'html', 'svg', 'txt', 'md'];

    public const MAX_FILE_BYTES = 524288;

    private const MAX_DEPTH = 12;

    private const SEGMENT = '/^[A-Za-z0-9_@+][A-Za-z0-9 _@+.-]*$/D';

    private const EXECUTABLE = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps'];

    /** Paths of changed files shown per category in a publish plan. */
    private const LISTED_CHANGES = 40;

    /**
     * Why the route would refuse this path, or null. `folder` allows the
     * draft root ('') and paths without an extension.
     */
    public static function pathProblem(string $path, string $kind): ?string
    {
        $trimmed = trim($path);

        if ($kind === 'folder' && in_array($trimmed, ['', '/', '.'], true)) {
            return null;
        }

        if ($trimmed === '') {
            return 'Give the path of a file in the draft.';
        }

        if (str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            return 'Use "/" between folders, without backslashes.';
        }

        if (str_starts_with($trimmed, '/') || preg_match('/^[A-Za-z]:/', $trimmed)) {
            return 'Paths are relative to the draft theme folder, like "functions.php" or "assets/css/main.css".';
        }

        $segments = explode('/', rtrim($trimmed, '/'));

        if (count($segments) > self::MAX_DEPTH) {
            return 'Paths have at most ' . self::MAX_DEPTH . ' folders.';
        }

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                return 'The path must not contain "." or ".." segments; nothing outside the draft can be reached.';
            }

            if (!preg_match(self::SEGMENT, $segment) || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                return 'Use letters, digits, spaces and _ @ + . - in names, "/" between folders, and no hidden (dot) names.';
            }
        }

        if ($kind === 'folder') {
            return null;
        }

        $parts     = explode('.', (string) end($segments));
        $extension = count($parts) > 1 ? (string) array_pop($parts) : '';

        if (!in_array($extension, self::EXTENSIONS, true)) {
            return 'Only these file types (lowercase extensions) can be used: ' . implode(', ', self::EXTENSIONS) . '.';
        }

        foreach (array_slice($parts, 1) as $part) {
            if (in_array(strtolower($part), self::EXECUTABLE, true)) {
                return 'Name PHP files with a single .php extension.';
            }
        }

        return null;
    }

    public static function kb(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : number_format($bytes / 1024, 1, '.', '') . ' KB';
    }

    /**
     * publish_draft_theme and rollback_theme (cloud formatThemeChange()).
     *
     * @param array<string, mixed> $outcome The route's answer and `pending` (202).
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function formatChange(string $siteId, string $siteUrl, array $outcome): array
    {
        $plan      = $outcome['pending'] ? $outcome['preview'] : $outcome;
        $counts    = isset($plan['changes']) ? (array) $plan['changes']['counts'] : null;
        $changes   = $counts === null ? null : [
            'added'     => $counts['added'] ?? 0,
            'modified'  => $counts['modified'] ?? 0,
            'removed'   => $counts['removed'] ?? 0,
            'unchanged' => $counts['unchanged'] ?? 0,
        ];
        $operation = $outcome['operation'];
        $what      = self::describePlan($plan);

        if ($outcome['pending']) {
            $link       = Url::approvalLink($siteUrl, $outcome['approval_url'], $outcome['approval_id']);
            $structured = [
                'site_id'   => $siteId,
                'operation' => $operation,
                'status'    => 'pending_approval',
                'changes'   => $changes,
                'approval'  => ['approval_id' => $outcome['approval_id'], 'approval_url' => $link, 'expires_at' => $outcome['expires_at']],
            ];
            $verb       = $operation === 'publish' ? 'Publishing the draft theme' : 'Rolling back the theme';
            $text       = C::finish([
                $verb . " is waiting for a site admin's approval: approval #" . $outcome['approval_id'] . ', expires ' . $outcome['expires_at'] . '. Nothing has changed yet.',
                'Approval link: ' . $link,
                'What it would do:',
                C::siteData($operation . ' plan', implode("\n", $what)),
            ], Url::pendingNextStep($outcome['approval_id']));

            return ['text' => $text, 'structured' => $structured];
        }

        $structured = ['site_id' => $siteId, 'operation' => $operation, 'status' => 'dry_run', 'changes' => $changes, 'approval' => null];
        $next       = $operation === 'publish'
            ? 'This was a preview; nothing changed. Publish only when the user has explicitly asked to: call publish_draft_theme without dry_run, then show them the approval link.'
            : 'This was a preview; nothing changed. To roll back, call rollback_theme without dry_run, then show the user the approval link.';

        return ['text' => C::finish(['Dry run of the theme ' . $operation . ': nothing changed.', C::siteData($operation . ' plan', implode("\n", $what))], $next), 'structured' => $structured];
    }

    /**
     * @param array<string, mixed> $plan
     * @return string[]
     */
    private static function describePlan(array $plan): array
    {
        $lines = [];

        if (isset($plan['live_theme'])) {
            $lines[] = 'Live theme now: ' . C::oneLine($plan['live_theme']['stylesheet'], 200) . ' (' . C::oneLine($plan['live_theme']['name'], 200) . ')';
        }

        if (isset($plan['draft'])) {
            $lines[] = 'Draft to publish: ' . C::oneLine($plan['draft']['slug'], 200);
        }

        if (($plan['source_changed'] ?? null) === true) {
            $lines[] = 'Warning: the live theme is not the theme this draft was copied from.';
        }

        if (isset($plan['restore'])) {
            $lines[] = 'Theme to restore: ' . C::oneLine($plan['restore'], 200);
        }

        if (isset($plan['backup'])) {
            $lines[] = 'From backup ' . C::oneLine($plan['backup']['id'], 20) . ' (' . C::oneLine($plan['backup']['file'], 300) . ', ' . ($plan['backup']['created_at'] ?? 'unknown date') . ')';
        }

        if (isset($plan['folder'])) {
            $aside   = $plan['moved_aside_to'] ?? null;
            $lines[] = 'Theme folder: ' . C::oneLine($plan['folder'], 20) . ($aside !== null && $aside !== '' ? '; the current folder is kept as ' . C::oneLine($aside, 300) : '');
        }

        if (isset($plan['changes'])) {
            $counts  = (array) $plan['changes']['counts'];
            $lines[] = 'Files compared with the live theme: ' . C::plural($counts['added'] ?? 0, 'added file') . ', ' . C::plural($counts['modified'] ?? 0, 'modified file') . ', '
                . C::plural($counts['removed'] ?? 0, 'removed file') . ', ' . ($counts['unchanged'] ?? 0) . ' unchanged';

            foreach (['Added' => 'added', 'Modified' => 'modified', 'Removed' => 'removed'] as $label => $key) {
                $paths = $plan['changes'][$key];

                if ($paths === []) {
                    continue;
                }

                $shown   = array_map(static fn (string $path): string => C::oneLine($path, 300), array_slice($paths, 0, self::LISTED_CHANGES));
                $lines[] = $label . ': ' . implode(', ', $shown) . (count($paths) > count($shown) ? ', … ' . (count($paths) - count($shown)) . ' more' : '');
            }
        }

        foreach ($plan['steps'] ?? [] as $step) {
            $lines[] = '- ' . C::oneLine($step, 500);
        }

        return $lines;
    }
}

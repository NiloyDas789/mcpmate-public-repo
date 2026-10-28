<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pretty permalinks (docs/mcp-in-plugin.md §10, check 1): with Plain
 * permalinks WordPress has no rewrite rules, so the sign-in addresses under
 * `/mcpmate/` and `/.well-known/` don't exist (§4.2, Q6).
 */
final class PermalinksCheck extends Check
{
    public const ID = 'permalinks';

    private string $structure;

    public function __construct(string $structure)
    {
        $this->structure = $structure;
    }

    public function isPretty(): bool
    {
        return $this->structure !== '';
    }

    public function run(): array
    {
        $title = __('Your site has readable addresses (permalinks)', 'mcpmate');

        if ($this->isPretty()) {
            return self::result(self::PASS, $title, __('AI apps can find the addresses they sign in with.', 'mcpmate'));
        }

        return self::result(
            self::FAIL,
            $title,
            __('Your site uses Plain permalinks (addresses like ?p=123). AI apps sign in at addresses that only exist with another setting.', 'mcpmate'),
            __('Go to Settings → Permalinks, choose any option other than Plain (Post name is a good choice), and save. Then select Check again.', 'mcpmate')
        );
    }
}

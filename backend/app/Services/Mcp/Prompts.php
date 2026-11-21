<?php

namespace MCPMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Services\Mcp\Skills\SkillStore;
use MCPMate\Services\Mcp\Skills\SystemSkills;
use MCPMate\Services\Mcp\Tools\GetSkillTool;

/**
 * Skills as MCP prompts (docs/pro-parity.md P3), for clients that offer
 * prompts to the user (a slash command, a menu): MCPMate's playbooks, from
 * the plugin's skills/ and every feature's skillsDirectory() (MCPMate Pro's),
 * and the skills this WordPress user saved.
 *
 * A prompt follows the grant's tool list: none at all unless the grant sees
 * get_skill (a prompt is get_skill by another route), and a playbook only
 * while the grant sees every tool its front matter names (`tools`) and, for
 * a playbook that changes the site (`writes`), may write: a read-only grant
 * is not offered a playbook that writes. prompts/get answers
 * with get_skill's own text, so a saved skill stays fenced as the user's.
 * Read per request while the grant's McpContext is active.
 */
final class Prompts
{
    /**
     * The prompts this grant may get, by name: {name, title, description}.
     *
     * @return array<int, array{name: string, title: string, description: string}>
     */
    public static function listed(): array
    {
        return array_map(static fn (array $skill): array => [
            'name'        => $skill['name'],
            'title'       => self::title($skill),
            'description' => $skill['description'],
        ], array_values(self::skills()));
    }

    /**
     * The prompt's description and messages, or null when this grant has no prompt by that name.
     *
     * @return array{description: string, messages: array<int, array{role: string, content: array{type: string, text: string}}>}|null
     */
    public static function get(string $name): ?array
    {
        $listed = self::skills()[$name] ?? null;

        if ($listed === null) {
            return null;
        }

        $siteId = McpSite::id();
        $skill  = $listed['owner'] === 'system' ? $listed : SkillStore::find(McpContext::userId(), $name, $siteId);

        if ($skill === null) {
            return null;
        }

        $alsoSaved = [];
        if ($skill['owner'] === 'system') {
            foreach (SkillStore::list(McpContext::userId(), $siteId) as $other) {
                if ($other['owner'] === 'user') {
                    $alsoSaved[] = $other['name'];
                }
            }
        }

        return [
            'description' => $skill['description'],
            'messages'    => [['role' => 'user', 'content' => ['type' => 'text', 'text' => GetSkillTool::format($skill, $alsoSaved, $siteId)['text']]]],
        ];
    }

    /**
     * The skills this grant gets as prompts, by name: playbooks first, then
     * the user's (one for this site wins over one for all sites).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function skills(): array
    {
        if (!ToolRegistry::isVisible('get_skill')) {
            return [];
        }

        $skills = [];

        foreach (SystemSkills::all() as $skill) {
            $missing = array_filter($skill['tools'], static fn (string $tool): bool => !ToolRegistry::isVisible($tool));

            if ($missing === [] && (!$skill['writes'] || McpContext::canWrite())) {
                $skills[$skill['name']] = $skill;
            }
        }

        // SkillStore::list() puts the user's all-sites skills before this site's, so this site's win.
        foreach (SkillStore::list(McpContext::userId(), McpSite::id()) as $skill) {
            if ($skill['owner'] === 'user' && !in_array($skill['name'], SystemSkills::NAMES, true)) {
                $skills[$skill['name']] = $skill;
            }
        }

        return $skills;
    }

    /**
     * A playbook's first heading ("SEO audit"), or the skill's name.
     *
     * @param array<string, mixed> $skill
     */
    private static function title(array $skill): string
    {
        if (isset($skill['body']) && preg_match('/^#\s+(.+)$/m', (string) $skill['body'], $heading) === 1) {
            return trim($heading[1]);
        }

        return (string) $skill['name'];
    }
}

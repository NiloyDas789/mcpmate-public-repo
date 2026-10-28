<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\Mcp\Skills\SkillStore;
use McpMate\Services\Mcp\ToolResult;

/**
 * list_skills: playbooks and this user's saved skills (cloud listSkills.ts).
 */
final class ListSkillsTool extends Tool
{
    public function run(\stdClass $args): ToolResult // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This tool takes no arguments.
    {
        return self::out(self::format(SkillStore::list(McpContext::userId(), $this->siteId), $this->siteId));
    }

    /**
     * @param array<int, array<string, mixed>> $skills
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(array $skills, ?string $siteId = null): array
    {
        $structured = [
            'site_id' => $siteId,
            'skills'  => array_map(
                static fn (array $skill): array => ['name' => $skill['name'], 'description' => $skill['description'], 'owner' => $skill['owner'], 'site_id' => $skill['siteId']],
                $skills
            ),
        ];
        $system     = array_filter($skills, static fn (array $skill): bool => $skill['owner'] === 'system');
        $saved      = array_values(array_filter($skills, static fn (array $skill): bool => $skill['owner'] === 'user'));

        $lines = ['McpMate playbooks (load the one that matches the task with get_skill before starting, then follow it):'];
        $lines = array_merge($lines, $system === [] ? ['(none)'] : array_map(static fn (array $skill): string => '- ' . $skill['name'] . ' — ' . $skill['description'], array_values($system)));

        if ($saved === []) {
            $lines[] = 'This WordPress user has saved no skills of their own. When the user asks you to remember a convention, save_skill keeps it for later conversations.';
        } else {
            $entries = implode("\n", array_map(static fn (array $skill): string => '- ' . $skill['name'] . ' (' . SkillTools::scopeOf($skill, $siteId) . ') — ' . $skill['description'], $saved));
            $lines[] = 'Saved by this WordPress user (' . count($saved) . '); load the ones that apply to the task too:';
            $lines[] = SkillTools::savedByAccount('skill names and descriptions', $entries);
        }

        return ['text' => C::finish($lines), 'structured' => $structured];
    }
}

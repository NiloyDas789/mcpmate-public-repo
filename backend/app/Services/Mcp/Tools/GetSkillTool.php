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
 * get_skill: one playbook or saved skill in full (cloud getSkill.ts).
 */
final class GetSkillTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $userId = McpContext::userId();
        $skill  = SkillStore::find($userId, $args->name, $this->siteId);

        if ($skill === null) {
            return ToolResult::error('skill_not_found', 'This account has no skill named "' . $args->name . '".', null, 'Call list_skills for the names. Do not guess skill names.');
        }

        $alsoSaved = [];
        if ($skill['owner'] === 'system') {
            foreach (SkillStore::list($userId, $this->siteId) as $other) {
                if ($other['owner'] === 'user') {
                    $alsoSaved[] = $other['name'];
                }
            }
        }

        return self::out(self::format($skill, $alsoSaved, $this->siteId));
    }

    /**
     * @param array<string, mixed> $skill
     * @param string[]             $alsoSaved
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(array $skill, array $alsoSaved, ?string $siteId = null): array
    {
        $updated    = $skill['updatedAt'];
        $structured = [
            'name'        => $skill['name'],
            'description' => $skill['description'],
            'owner'       => $skill['owner'],
            'site_id'     => $skill['siteId'],
            'updated_at'  => $updated,
            'body'        => $skill['body'],
            'also_saved'  => $alsoSaved,
        ];

        if ($skill['owner'] === 'user') {
            $header = 'Skill ' . $skill['name'] . ', saved by this WordPress user for ' . SkillTools::scopeOf($skill, $siteId) . ' (updated ' . substr($updated, 0, 10) . ').';

            return ['text' => C::finish([$header, SkillTools::savedByAccount('skill ' . $skill['name'], $skill['body'])]), 'structured' => $structured];
        }

        $lines = ['Skill ' . $skill['name'] . ': McpMate playbook (updated ' . substr($updated, 0, 10) . '). Follow it for this task.'];
        if ($alsoSaved !== []) {
            $lines[] = 'This WordPress user also saved: ' . implode(', ', $alsoSaved) . '. Load the ones that apply to this task with get_skill.';
        }
        $lines[] = '';
        $lines[] = $skill['body'];

        return ['text' => C::finish($lines), 'structured' => $structured];
    }
}

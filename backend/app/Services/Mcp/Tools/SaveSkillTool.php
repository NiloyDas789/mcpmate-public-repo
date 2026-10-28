<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\Mcp\Skills\SkillStore;
use McpMate\Services\Mcp\Skills\SystemSkills;
use McpMate\Services\Mcp\ToolResult;

/**
 * save_skill: a convention of this WordPress user, kept for later
 * conversations (cloud saveSkill.ts). Writes the skills table only.
 */
final class SaveSkillTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        if (strlen($args->body) > SystemSkills::MAX_BODY_BYTES) {
            return ToolResult::invalidInput('body must be at most ' . SystemSkills::MAX_BODY_BYTES . ' bytes.');
        }

        $userId   = McpContext::userId();
        $siteOnly = (bool) $args->this_site_only;
        $name     = $args->name;

        // A user's skill cannot stand in for a playbook: get_skill would still return the system one.
        $found = SkillStore::find($userId, $name, $this->siteId);
        if (in_array($name, SystemSkills::NAMES, true) || ($found['owner'] ?? null) === 'system') {
            return ToolResult::error(
                'skill_name_reserved',
                '"' . $name . '" is one of McpMate’s own skills and cannot be replaced.',
                null,
                'Save it under another name, such as "' . substr($name, 0, 50) . '-conventions", and say in its description which work it is for.'
            );
        }

        $existing = SkillStore::findOwn($userId, $siteOnly, $name, $this->siteId);
        if ($existing === null && SkillStore::countForUser($userId) >= SkillStore::MAX_PER_USER) {
            return ToolResult::error(
                'skill_limit_reached',
                'This WordPress user already has ' . SkillStore::MAX_PER_USER . ' skills.',
                null,
                'Tell the user. An existing skill can be replaced by saving under its name, for example to merge two of them.'
            );
        }

        $skill = SkillStore::save($userId, $siteOnly, $name, $args->description, Js::trim($args->body), $this->siteId);

        if ($skill === null) {
            return ToolResult::internal();
        }

        return self::out(self::format($skill, $existing === null, $siteOnly ? $this->siteId : null));
    }

    /**
     * @param array<string, mixed> $skill
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(array $skill, bool $created, ?string $siteId = null): array
    {
        $structured = ['name' => $skill['name'], 'site_id' => $skill['siteId'], 'created' => $created, 'updated_at' => $skill['updatedAt']];
        $text       = C::finish([
            'Saved skill ' . $skill['name'] . ' for ' . SkillTools::scopeOf($skill, $siteId) . ($created ? '' : ', replacing the earlier version') . '.',
            'Later conversations see it in list_skills and load it with get_skill.',
        ]);

        return ['text' => $text, 'structured' => $structured];
    }
}

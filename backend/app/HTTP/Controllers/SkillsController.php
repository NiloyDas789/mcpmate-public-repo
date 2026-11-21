<?php

namespace MCPMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\AuditLog\Logger;
use MCPMate\Services\AuditLog\Retention;
use MCPMate\Services\Mcp\Skills\SkillStore;
use MCPMate\Services\Mcp\Skills\SystemSkills;

/**
 * Tools → Skills (docs/mcp-in-plugin.md §6.1, docs/pro-parity.md P3): a site
 * admin sees the skills AI apps saved for this site's users and MCPMate's
 * playbooks, edits a saved skill, moves it to the trash and back, and
 * deletes a trashed one for good. Playbooks are files: read-only here too.
 *
 * AJAX routes (nonce + admin): `skills/list {status: live|trash}`,
 * `skills/show {id | name}` (a playbook by name), `skills/update {id,
 * description, body}` (save_skill's limits), `skills/trash {id}`,
 * `skills/restore {id}` and `skills/delete {id}` (trashed skills only).
 * Each change writes an audit row, tool `skills`, with the body's length,
 * never the body.
 */
final class SkillsController
{
    private const AUDIT_TOOL = 'skills';

    public function index(Request $request): Response
    {
        $trashed = $request->get('status', 'live') === 'trash';

        return Response::success([
            'skills'     => SkillStore::all($trashed),
            'counts'     => SkillStore::counts(),
            'system'     => array_map(static fn (array $skill): array => [
                'name'        => $skill['name'],
                'description' => $skill['description'],
                'tools'       => $skill['tools'],
                'updated_at'  => $skill['updatedAt'],
            ], SystemSkills::all()),
            'trash_days' => Retention::skillTrashDays(),
            'limits'     => ['description_chars' => SystemSkills::MAX_DESCRIPTION_LENGTH, 'body_bytes' => SystemSkills::MAX_BODY_BYTES],
        ]);
    }

    /**
     * `{id}` for a saved skill, `{name}` for a playbook.
     */
    public function show(Request $request): Response
    {
        $name = $request->get('name', '');

        if (is_string($name) && $name !== '') {
            foreach (SystemSkills::all() as $skill) {
                if ($skill['name'] === $name) {
                    $playbook = [
                        'name'        => $skill['name'],
                        'description' => $skill['description'],
                        'tools'       => $skill['tools'],
                        'updated_at'  => $skill['updatedAt'],
                        'body'        => $skill['body'],
                        'owner'       => 'system',
                    ];

                    return Response::success(['skill' => $playbook]);
                }
            }

            return self::notFound();
        }

        $skill = SkillStore::get(absint($request->get('id', 0)));

        return $skill === null ? self::notFound() : Response::success(['skill' => $skill + ['owner' => 'user']]);
    }

    public function update(Request $request): Response
    {
        $before = SkillStore::get(absint($request->get('id', 0)));

        if ($before === null) {
            return self::notFound();
        }

        if ($before['trashed_at'] !== null) {
            return Response::error(__('Restore this skill before you edit it.', 'mcpmate'), 409, 'mcpmate_skill_trashed');
        }

        $description = $request->get('description');
        $body        = $request->get('body');
        $description = is_string($description) ? trim($description) : '';
        $body        = is_string($body) ? trim(str_replace(["\r\n", "\r"], "\n", $body)) : '';
        $errors      = [];

        $length = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
        if ($length === 0 || $length > SystemSkills::MAX_DESCRIPTION_LENGTH || preg_match('/[\r\n]/', $description)) {
            /* translators: %s: the limit in characters. */
            $errors['description'] = sprintf(__('Give a description of one line, up to %s characters.', 'mcpmate'), number_format_i18n(SystemSkills::MAX_DESCRIPTION_LENGTH));
        }

        if ($body === '' || strlen($body) > SystemSkills::MAX_BODY_BYTES || preg_match('//u', $body) !== 1) {
            /* translators: %s: the limit in bytes. */
            $errors['body'] = sprintf(__('Write the skill as Markdown, up to %s bytes.', 'mcpmate'), number_format_i18n(SystemSkills::MAX_BODY_BYTES));
        }

        if ($errors !== []) {
            return Response::error(reset($errors), 400, 'mcpmate_invalid_skill')->withDetails(['errors' => $errors]);
        }

        if (!SkillStore::update($before['id'], $description, $body)) {
            return self::notFound();
        }

        $skill = (array) SkillStore::get($before['id']);
        self::audit('update', $skill, ['description' => $description, 'body_bytes' => ['from' => $before['bytes'], 'to' => $skill['bytes']]]);

        return Response::success(['message' => __('Skill saved.', 'mcpmate'), 'skill' => $skill + ['owner' => 'user']]);
    }

    public function trash(Request $request): Response
    {
        $id = absint($request->get('id', 0));

        if (!SkillStore::trash($id)) {
            return self::notFound();
        }

        $skill = (array) SkillStore::get($id);
        self::audit('trash', $skill, []);

        return Response::success(['message' => __('Skill moved to the trash.', 'mcpmate'), 'skill' => $skill + ['owner' => 'user']]);
    }

    public function restore(Request $request): Response
    {
        $trashed = SkillStore::get(absint($request->get('id', 0)));

        if ($trashed === null || $trashed['trashed_at'] === null) {
            return self::notFound();
        }

        if (SkillStore::countForUser($trashed['user']['id']) >= SkillStore::MAX_PER_USER) {
            return Response::error(
                /* translators: %s: the most skills a user can save. */
                sprintf(__('This user already has %s skills. Move one to the trash first.', 'mcpmate'), number_format_i18n(SkillStore::MAX_PER_USER)),
                409,
                'mcpmate_skill_limit'
            );
        }

        if (!SkillStore::restore($trashed['id'])) {
            return self::notFound();
        }

        $skill = (array) SkillStore::get($trashed['id']);
        self::audit('restore', $skill, []);

        return Response::success(['message' => __('Skill restored.', 'mcpmate'), 'skill' => $skill + ['owner' => 'user']]);
    }

    /**
     * `{id}` of a skill in the trash: the one permanent delete.
     */
    public function delete(Request $request): Response
    {
        $skill = SkillStore::get(absint($request->get('id', 0)));

        if ($skill === null) {
            return self::notFound();
        }

        if ($skill['trashed_at'] === null) {
            return Response::error(__('Move the skill to the trash before you delete it for good.', 'mcpmate'), 409, 'mcpmate_skill_not_trashed');
        }

        if (!SkillStore::delete($skill['id'])) {
            return self::notFound();
        }

        self::audit('delete', $skill, []);

        return Response::success(['message' => __('Skill deleted for good.', 'mcpmate')]);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $skill
     * @param array<string, mixed> $args
     */
    private static function audit(string $action, array $skill, array $args): void
    {
        Logger::log([
            'tool'   => self::AUDIT_TOOL,
            'action' => $action,
            'target' => 'skill:' . $skill['id'],
            'args'   => ['name' => $skill['name'], 'user_id' => $skill['user']['id']] + $args,
            'result' => Logger::RESULT_OK,
        ]);
    }

    private static function notFound(): Response
    {
        return Response::error(__('Skill not found.', 'mcpmate'), 404, 'mcpmate_skill_not_found');
    }
}

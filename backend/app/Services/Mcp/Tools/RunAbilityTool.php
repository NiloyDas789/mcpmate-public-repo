<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;
use McpMate\Services\Mcp\Url;

/**
 * run_ability → the block markup gate, then POST abilities/run (cloud
 * runAbility.ts). The route holds destructive abilities for approval.
 */
final class RunAbilityTool extends Tool
{
    /** AbilityRunner::MAX_INPUT_BYTES. */
    public const MAX_INPUT_BYTES = 65536;

    /** Deepest nesting accepted in an ability's input. */
    public const MAX_INPUT_DEPTH = 20;

    private const RESULT_TEXT_BYTES = 16000;

    private const RESULT_STRUCTURED_BYTES = 16000;

    public function run(\stdClass $args): ToolResult
    {
        $hasInput = property_exists($args, 'input');
        $input    = $hasInput ? $args->input : null;

        if ($hasInput && strlen(Js::stringify($input)) > self::MAX_INPUT_BYTES) {
            return ToolResult::invalidInput('input must be at most ' . (self::MAX_INPUT_BYTES / 1024) . ' KiB as JSON.');
        }

        if ($hasInput && self::depthOf($input) > self::MAX_INPUT_DEPTH) {
            return ToolResult::invalidInput('input must be nested at most ' . self::MAX_INPUT_DEPTH . ' levels deep.');
        }

        $markup = BlockMarkup::in($input);
        if (count($markup) > BlockMarkup::MAX_FIELDS) {
            return ToolResult::invalidInput(
                'input holds block markup in ' . count($markup) . ' strings; at most ' . BlockMarkup::MAX_FIELDS . ' can be checked in one call.',
                'Split the work into several calls.'
            );
        }

        // Checked before the ability runs: nothing is written when the markup is invalid.
        $refused = BlockMarkup::refuseInvalid($markup);
        if ($refused !== null) {
            return $refused;
        }

        $reply = $this->client->runAbility($hasInput ? ['name' => $args->name, 'input' => $input] : ['name' => $args->name]);

        return self::out(self::format($this->siteId, $this->siteUrl, $reply['data']));
    }

    /**
     * @param mixed $value
     */
    public static function depthOf($value): int
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            return 0;
        }

        $deepest = 0;
        foreach ((array) $value as $item) {
            $deepest = max($deepest, self::depthOf($item));
        }

        return $deepest + 1;
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $siteUrl, array $outcome): array
    {
        $name = C::oneLine($outcome['name'], 200);

        if ($outcome['pending']) {
            $link       = Url::approvalLink($siteUrl, $outcome['approval_url'], $outcome['approval_id']);
            $structured = [
                'site_id'  => $siteId,
                'status'   => 'pending_approval',
                'name'     => $name,
                'risk'     => $outcome['risk'],
                'result'   => null,
                'approval' => [
                    'approval_id'  => $outcome['approval_id'],
                    'approval_url' => $link,
                    'expires_at'   => $outcome['expires_at'],
                    'preview'      => C::fit($outcome['preview'] ?? null, self::RESULT_STRUCTURED_BYTES),
                ],
            ];
            $text       = C::finish([
                '"' . $name . '" (' . $outcome['risk'] . ") is waiting for a site admin's approval: approval #" . $outcome['approval_id']
                    . ', expires ' . $outcome['expires_at'] . '. Nothing has changed yet.',
                'Approval link: ' . $link,
                'What would run:',
                C::siteData('the ability and its input', implode("\n", C::render(C::fit($outcome['preview'] ?? null, self::RESULT_TEXT_BYTES)))),
            ], Url::pendingNextStep($outcome['approval_id']));

            return ['text' => $text, 'structured' => $structured];
        }

        $result     = $outcome['result'] ?? null;
        $structured = [
            'site_id'  => $siteId,
            'status'   => 'ok',
            'name'     => $name,
            'risk'     => $outcome['risk'],
            'result'   => C::fit($result, self::RESULT_STRUCTURED_BYTES),
            'approval' => null,
        ];
        $shown      = C::fit($result, self::RESULT_TEXT_BYTES);
        $summarized = Js::stringify($shown) !== Js::stringify($result);
        $text       = C::finish(
            ['Ran "' . $name . '" (' . $outcome['risk'] . ').', C::siteData('ability result', implode("\n", C::render($shown)))],
            $summarized ? 'The result was summarized ("… more" markers). If the ability takes filters or pagination in its input, use them to see the rest.' : null
        );

        return ['text' => $text, 'structured' => $structured];
    }
}

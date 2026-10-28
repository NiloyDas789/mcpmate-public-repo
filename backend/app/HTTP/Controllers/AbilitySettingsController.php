<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Abilities\Abilities;
use McpMate\Services\Abilities\AbilitySettings;

/**
 * Admin settings: which abilities POST /abilities/run may run.
 *
 * AJAX routes (nonce + admin), used by the Settings page.
 */
final class AbilitySettingsController
{
    public function index(Request $request): Response
    {
        return Response::success(self::state());
    }

    public function update(Request $request): Response
    {
        $enabled = $request->get('enabled', []);

        // FormData sends arrays as JSON strings.
        if (is_string($enabled)) {
            $enabled = json_decode($enabled, true);
        }

        if (!is_array($enabled)) {
            return Response::error(__('The list of enabled abilities is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_abilities');
        }

        AbilitySettings::update(array_map('sanitize_text_field', array_filter($enabled, 'is_string')), array_keys(Abilities::all()));

        return Response::success(['message' => __('Ability settings saved.', 'mcpmate')] + self::state());
    }

    /**
     * @return array{supported: bool, abilities: array<int, array<string, mixed>>}
     */
    private static function state(): array
    {
        return [
            'supported' => Abilities::isSupported(),
            'abilities' => array_values(array_map(
                static fn (\WP_Ability $ability) => Abilities::describe($ability, false),
                Abilities::all()
            )),
        ];
    }
}

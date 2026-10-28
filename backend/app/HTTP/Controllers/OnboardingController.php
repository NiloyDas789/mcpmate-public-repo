<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Abilities\Abilities;
use McpMate\Services\AuditLog\AuditLogQuery;
use McpMate\Services\Connection\Connection;
use McpMate\Services\Connection\Grants;
use McpMate\Services\Elementor\ElementorPages;
use McpMate\Services\Onboarding\EnvironmentChecks;
use McpMate\Services\Onboarding\OnboardingState;
use McpMate\Utils\Capabilities;

/**
 * The first-run setup wizard (`#/setup`): its saved progress, what each step
 * waits for, and the site checks.
 *
 * AJAX routes (nonce + admin), never REST: bearer tokens never set a user
 * on admin-ajax, so an AI client can neither mark setup done nor change a
 * switch.
 */
final class OnboardingController
{
    /**
     * Everything the wizard needs to resume: progress, the MCP URL, the
     * grant step 4 found and its first call.
     */
    public function state(Request $request): Response
    {
        $state = OnboardingState::get();
        $grant = $state['grant_id'] !== '' ? Grants::find($state['grant_id']) : null;

        return Response::success([
            'onboarding'   => OnboardingState::forClient($state),
            'connection'   => Connection::summary(),
            'grant'        => $grant !== null ? Grants::format($grant) : null,
            'first_call'   => $grant !== null ? AuditLogQuery::firstCall((string) $grant['grant_id']) : null,
            'features'     => self::features(),
        ]);
    }

    /**
     * `{step?, status?, grant_id?}`; anything else is ignored.
     */
    public function update(Request $request): Response
    {
        $input = [];

        foreach (['step', 'status', 'grant_id'] as $key) {
            $value = $request->get($key, null);

            if ($value !== null) {
                $input[$key] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }

        $state = OnboardingState::update($input);

        if (is_wp_error($state)) {
            return Response::error($state->get_error_message(), 400, (string) $state->get_error_code());
        }

        return Response::success(['onboarding' => OnboardingState::forClient($state)]);
    }

    /**
     * Run every check now (never cached).
     */
    public function checks(Request $request): Response
    {
        try {
            return Response::success(EnvironmentChecks::run());
        } catch (\Throwable $e) {
            return Response::error(
                __('The site checks could not run.', 'mcpmate'),
                500,
                'mcpmate_checks_failed'
            );
        }
    }

    /**
     * What step 3 can offer on this site.
     *
     * @return array{abilities_supported: bool, abilities_available: int, elementor_available: bool, can_edit_themes: bool}
     */
    private static function features(): array
    {
        $supported = Abilities::isSupported();

        return [
            'abilities_supported' => $supported,
            'abilities_available' => $supported ? count(Abilities::all()) : 0,
            'elementor_available' => ElementorPages::isAvailable(),
            'can_edit_themes'     => Capabilities::check('edit_themes'),
        ];
    }
}

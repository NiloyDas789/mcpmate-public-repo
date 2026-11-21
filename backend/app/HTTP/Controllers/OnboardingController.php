<?php

namespace MCPMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;
use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\Services\Abilities\Abilities;
use MCPMate\Services\AuditLog\AuditLogQuery;
use MCPMate\Services\Connection\Connection;
use MCPMate\Services\Connection\Grants;
use MCPMate\Services\Onboarding\EnvironmentChecks;
use MCPMate\Services\Onboarding\OnboardingState;
use MCPMate\Utils\Capabilities;

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
     * What step 3 can offer on this site. `pro_features` says whether MCPMate
     * Pro's switches exist here; its features add their own facts
     * (`elementor_available`).
     *
     * @return array<string, mixed>
     */
    private static function features(): array
    {
        $supported = Abilities::isSupported();
        $facts     = [
            'abilities_supported' => $supported,
            'abilities_available' => $supported ? count(Abilities::all()) : 0,
            'elementor_available' => false,
            'can_edit_themes'     => Capabilities::check('edit_themes'),
            'pro_features'        => Features::any(),
        ];

        foreach (Features::all() as $feature) {
            $facts = array_merge($facts, $feature->setupFacts());
        }

        return $facts;
    }
}

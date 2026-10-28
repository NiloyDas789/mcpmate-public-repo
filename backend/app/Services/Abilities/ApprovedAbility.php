<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Controllers\Api\AbilitiesController;
use McpMate\Services\Approvals\ApprovalException;
use McpMate\Utils\Capabilities;

/**
 * Executor for approved `POST /abilities/run` requests (tool `abilities` in
 * Approvals).
 *
 * Runs in the approving admin's request. The stored name and input go
 * through the same checks as the original call, against the site as it is
 * now:
 *
 * 1. The requester still has the route's capability, and so does the
 *    approving admin.
 * 2. AbilityRunner::check() as the admin: the API exists, the ability is
 *    still registered, exposed over REST and enabled, the input still
 *    matches its schema, and its permission callback allows the admin.
 * 3. Its permission callback still allows the requester too.
 *
 * Then the ability runs once, as the admin.
 */
final class ApprovedAbility
{
    /**
     * @param array<string, mixed> $args The stored request: `name` (string) and `input`.
     * @return array<string, mixed>
     * @throws ApprovalException When any check fails or the ability fails.
     */
    public static function run(array $args, int $requestedBy): array
    {
        $capability = AbilitiesController::capabilityFor('run');

        if (!user_can($requestedBy, $capability)) {
            throw new ApprovalException(
                esc_html__('The user who requested this ability can no longer run it.', 'mcpmate'),
                esc_html(AbilityException::FORBIDDEN),
                403,
                /* translators: %s: WordPress capability name. */
                sprintf(esc_html__('The requester needs the "%s" capability.', 'mcpmate'), esc_html($capability))
            );
        }

        if (!Capabilities::check($capability)) {
            throw new ApprovalException(
                esc_html__('Sorry, you are not allowed to do that.', 'mcpmate'),
                esc_html(AbilityException::FORBIDDEN),
                403,
                /* translators: %s: WordPress capability name. */
                sprintf(esc_html__('Approving this ability needs the "%s" capability.', 'mcpmate'), esc_html($capability))
            );
        }

        try {
            $checked = AbilityRunner::check((string) ($args['name'] ?? ''), $args['input'] ?? null);

            if (!self::requesterMayRun($checked['ability'], $checked['input'], $requestedBy)) {
                throw new ApprovalException(
                    esc_html__('The user who requested this ability can no longer run it.', 'mcpmate'),
                    esc_html(AbilityException::FORBIDDEN),
                    403,
                    esc_html__('The ability\'s own permission check refused the requester.', 'mcpmate')
                );
            }

            $result = AbilityRunner::run($checked['ability'], $checked['input']);
        } catch (AbilityException $e) {
            throw new ApprovalException(esc_html($e->getMessage()), esc_html($e->errorCode()), (int) $e->status(), esc_html($e->hint()));
        }

        return [
            'name'   => $checked['ability']->get_name(),
            'risk'   => $checked['risk'],
            'result' => $result,
        ];
    }

    /**
     * The ability's permission callback, evaluated as $userId. The current
     * user is restored afterwards, whatever the callback does.
     *
     * @param mixed $input
     */
    private static function requesterMayRun(\WP_Ability $ability, $input, int $userId): bool
    {
        $current = get_current_user_id();

        wp_set_current_user($userId);
        try {
            return $ability->check_permissions($input) === true;
        } finally {
            wp_set_current_user($current);
        }
    }
}

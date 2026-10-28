<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks and runs one ability for POST /abilities/run (and, after approval,
 * for ApprovedAbility). check() runs nothing of a disabled ability, not even
 * its input filters or permission callback:
 *
 * 1. The Abilities API exists → else 501.
 * 2. The ability is registered and exposed over REST → else 404.
 * 3. A site admin enabled it (AbilitySettings) → else 403.
 * 4. The input is at most MAX_INPUT_BYTES of JSON → else 400.
 * 5. The input is normalized (schema default) and validated against the
 *    ability's input schema, then coerced to it as core's REST route does → else 400.
 * 6. The ability's own permission callback allows the current user → else 403.
 *
 * run() then calls WP_Ability::execute(), which validates again, checks the
 * permission again and validates the output.
 */
final class AbilityRunner
{
    /** Largest input accepted, as JSON. */
    public const MAX_INPUT_BYTES = 65536;

    /** Longest error message passed on from an ability. */
    private const MAX_MESSAGE_LENGTH = 500;

    /**
     * @param mixed $input
     * @return array{ability: \WP_Ability, input: mixed, risk: string}
     * @throws AbilityException
     */
    public static function check(string $name, $input): array
    {
        if (!Abilities::isSupported()) {
            throw self::unsupported();
        }

        $ability = Abilities::find($name);
        if ($ability === null) {
            throw new AbilityException(
                /* translators: %s: ability name. */
                sprintf(esc_html__('No ability named "%s" is available on this site.', 'mcpmate'), esc_html($name)),
                esc_html(AbilityException::NOT_FOUND),
                404,
                esc_html__('List the abilities with GET /abilities. Abilities their plugin keeps out of the REST API are not available.', 'mcpmate')
            );
        }

        if (!AbilitySettings::isEnabled($name)) {
            throw new AbilityException(
                /* translators: %s: ability name. */
                sprintf(esc_html__('"%s" is not enabled on this site.', 'mcpmate'), esc_html($name)),
                esc_html(AbilityException::DISABLED),
                403,
                esc_html__('A site admin can enable it under McpMate → Tools → Abilities.', 'mcpmate')
            );
        }

        $json = wp_json_encode($input);
        if ($json === false || strlen($json) > self::MAX_INPUT_BYTES) {
            throw new AbilityException(
                esc_html__('The input is too large.', 'mcpmate'),
                esc_html(AbilityException::INPUT_TOO_LARGE),
                400,
                /* translators: %d: size in KiB. */
                sprintf(esc_html__('Send at most %d KiB of input.', 'mcpmate'), (int) (self::MAX_INPUT_BYTES / 1024))
            );
        }

        $input = $ability->normalize_input($input);
        if (is_wp_error($input)) {
            throw self::invalidInput(esc_html(self::message($input)));
        }

        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) {
            throw self::invalidInput(esc_html(self::message($valid)));
        }

        $input = self::coerce($ability, $input);

        if ($ability->check_permissions($input) !== true) {
            // The callback's own WP_Error is not passed on: it may say more than a refused user should learn.
            throw new AbilityException(
                esc_html__('Sorry, you are not allowed to run this ability.', 'mcpmate'),
                esc_html(AbilityException::FORBIDDEN),
                403,
                esc_html__('The ability\'s own permission check refused the WordPress user McpMate is connected as.', 'mcpmate')
            );
        }

        return ['ability' => $ability, 'input' => $input, 'risk' => Abilities::risk($ability)];
    }

    /**
     * Run a checked ability once.
     *
     * @param mixed $input The input returned by check().
     * @return mixed The ability's result.
     * @throws AbilityException When the ability returns a WP_Error or throws.
     */
    public static function run(\WP_Ability $ability, $input)
    {
        try {
            $result = $ability->execute($input);
        } catch (\Throwable $e) {
            // An ability_class may throw past WP_Ability's own catch. Never pass on the message: it can carry paths or SQL.
            throw self::unexpected();
        }

        if (!is_wp_error($result)) {
            return $result;
        }

        switch ($result->get_error_code()) {
            case 'ability_invalid_input':
            case 'ability_missing_input_schema':
                throw self::invalidInput(esc_html(self::message($result)));
            case 'ability_invalid_permissions':
                throw new AbilityException(
                    esc_html__('Sorry, you are not allowed to run this ability.', 'mcpmate'),
                    esc_html(AbilityException::FORBIDDEN),
                    403
                );
            case 'ability_callback_exception':
                throw self::unexpected();
        }

        $data   = $result->get_error_data();
        $status = is_array($data) && isset($data['status']) && is_int($data['status']) && $data['status'] >= 400 && $data['status'] <= 599
            ? $data['status']
            : 500;

        throw new AbilityException(
            esc_html(self::message($result)),
            esc_html(AbilityException::FAILED),
            (int) $status,
            sprintf(
                /* translators: %s: error code returned by the ability. */
                esc_html__('The ability returned the error code "%s".', 'mcpmate'),
                esc_html((string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) $result->get_error_code()))
            )
        );
    }

    public static function unsupported(): AbilityException
    {
        return new AbilityException(
            esc_html__('Abilities are not supported on this site: they need WordPress 6.9 or later.', 'mcpmate'),
            esc_html(AbilityException::UNSUPPORTED),
            501,
            esc_html__('Ask a site admin to update WordPress.', 'mcpmate')
        );
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * Valid input coerced to its schema (e.g. "5" → 5), as core's REST run
     * route does; input the schema cannot coerce cleanly stays as given.
     *
     * @param mixed $input
     * @return mixed
     */
    private static function coerce(\WP_Ability $ability, $input)
    {
        $schema = $ability->get_input_schema();
        if ($input === null || $schema === []) {
            return $input;
        }

        $coerced = rest_sanitize_value_from_schema($input, $schema, 'input');

        return self::containsError($coerced) ? $input : $coerced;
    }

    /**
     * @param mixed $value
     */
    private static function containsError($value): bool
    {
        if (is_wp_error($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsError($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function invalidInput(string $message): AbilityException
    {
        return new AbilityException(
            esc_html($message),
            esc_html(AbilityException::INVALID_INPUT),
            400,
            esc_html__('Send input that matches the ability\'s input_schema from GET /abilities.', 'mcpmate')
        );
    }

    private static function unexpected(): AbilityException
    {
        return new AbilityException(
            esc_html__('The ability failed with an unexpected error.', 'mcpmate'),
            esc_html(AbilityException::FAILED),
            500,
            esc_html__('Ask a site admin to check the PHP error log.', 'mcpmate')
        );
    }

    /**
     * The error's message as one line of plain text, cut to MAX_MESSAGE_LENGTH.
     */
    private static function message(\WP_Error $error): string
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($error->get_error_message())));

        if ($message === '') {
            return __('The ability failed.', 'mcpmate');
        }

        return mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8') . '…'
            : $message;
    }
}

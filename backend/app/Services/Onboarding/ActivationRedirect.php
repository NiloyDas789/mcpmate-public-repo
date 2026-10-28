<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\Services\Connection\Grants;
use McpMate\Utils\Capabilities;

/**
 * Opens the setup wizard once, right after a site admin activates the
 * plugin in wp-admin.
 *
 * arm() (activation) sets a 60-second transient holding the activating
 * user's ID, but only for a single activation by a site admin in wp-admin:
 * never network-wide, from WP-CLI, AJAX, REST or cron, or in a bulk
 * activation. maybeRedirect() (admin_init) deletes the transient before
 * anything else, so it fires at most once, then redirects only that user,
 * only on a plain admin page, and only while setup is not started or in
 * progress and no AI app is connected.
 */
final class ActivationRedirect
{
    public const TRANSIENT = 'activation_redirect';

    public const TTL = 60;

    public static function register(): void
    {
        Hooks::addAction('admin_init', [self::class, 'maybeRedirect']);
    }

    /**
     * Activation hook.
     */
    public static function arm(bool $networkWide): void
    {
        if (!self::shouldArm($networkWide)) {
            return;
        }

        set_transient(Config::withPrefix(self::TRANSIENT), get_current_user_id(), self::TTL);
    }

    /**
     * `admin_init`: consume the transient, then redirect if everything holds.
     */
    public static function maybeRedirect(): void
    {
        $name   = Config::withPrefix(self::TRANSIENT);
        $userId = get_transient($name);

        if ($userId === false) {
            return;
        }

        // First, so the redirect happens once at most.
        delete_transient($name);

        if (!self::shouldRedirect((int) $userId)) {
            return;
        }

        wp_safe_redirect(self::setupUrl());
        exit;
    }

    public static function setupUrl(): string
    {
        return admin_url('admin.php?page=' . Config::SLUG . '&setup=1');
    }

    // ──────────────────────────────────────────────
    //  Conditions
    // ──────────────────────────────────────────────

    public static function shouldArm(bool $networkWide): bool
    {
        if ($networkWide || is_network_admin() || self::isBackgroundRequest()) {
            return false;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: only tells a bulk activation apart; core checked its nonce.
        $bulk = isset($_GET['activate-multi'])
            || (isset($_REQUEST['action']) && sanitize_key(wp_unslash($_REQUEST['action'])) === 'activate-selected')
            || (isset($_REQUEST['action2']) && sanitize_key(wp_unslash($_REQUEST['action2'])) === 'activate-selected');
        // phpcs:enable

        return !$bulk && Capabilities::check('manage_options');
    }

    public static function shouldRedirect(int $activatedBy): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by core after a bulk activation.
        if ($activatedBy <= 0 || get_current_user_id() !== $activatedBy || isset($_GET['activate-multi'])) {
            return false;
        }

        if (is_network_admin() || self::isBackgroundRequest() || (defined('IFRAME_REQUEST') && IFRAME_REQUEST)) {
            return false;
        }

        if (!Capabilities::check('manage_options')) {
            return false;
        }

        $status = OnboardingState::status();

        if (!in_array($status, [OnboardingState::STATUS_NOT_STARTED, OnboardingState::STATUS_IN_PROGRESS], true)) {
            return false;
        }

        return Grants::countLive() === 0;
    }

    private static function isBackgroundRequest(): bool
    {
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_ajax() || wp_doing_cron()) {
            return true;
        }

        if (function_exists('wp_is_serving_rest_request')) {
            return wp_is_serving_rest_request();
        }

        return defined('REST_REQUEST') && REST_REQUEST;
    }
}

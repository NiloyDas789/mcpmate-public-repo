<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Hooks\Hooks;
use McpMate\Utils\Capabilities;

/**
 * "McpMate isn't set up yet" on the Dashboard and Plugins screens,
 * while setup is not started, in progress or skipped.
 *
 * Plain PHP markup: it works without the plugin's script. "Don't show
 * again" is a link to admin-post.php (nonce + manage_options) that
 * remembers the user; WordPress's own dismiss button only hides it for that
 * page view. The plugin's own pages remove every notice (Layout), so it
 * never shows there, nor in the network admin.
 */
final class SetupNotice
{
    public const DISMISS_ACTION = 'mcpmate_dismiss_setup_notice';

    /** Screens the notice appears on. */
    private const SCREENS = ['dashboard', 'plugins'];

    public static function register(): void
    {
        Hooks::addAction('admin_notices', [self::class, 'render']);
        Hooks::addAction('admin_post_' . self::DISMISS_ACTION, [self::class, 'dismiss']);
    }

    public static function shouldShow(string $screenId, int $userId): bool
    {
        if (!in_array($screenId, self::SCREENS, true) || is_network_admin() || !Capabilities::check('manage_options')) {
            return false;
        }

        $status = OnboardingState::status();

        return in_array($status, [OnboardingState::STATUS_NOT_STARTED, OnboardingState::STATUS_IN_PROGRESS, OnboardingState::STATUS_SKIPPED], true)
            && !OnboardingState::noticeDismissedBy($userId);
    }

    /**
     * `admin_notices`.
     */
    public static function render(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || !self::shouldShow((string) $screen->id, get_current_user_id())) {
            return;
        }

        $dismissUrl = wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS_ACTION), self::DISMISS_ACTION);

        printf(
            '<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> %2$s <a class="button button-primary" href="%3$s">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
            esc_html__('McpMate isn\'t set up yet.', 'mcpmate'),
            esc_html__('Connect your AI to this site in about 3 minutes.', 'mcpmate'),
            esc_url(ActivationRedirect::setupUrl()),
            esc_html__('Resume setup', 'mcpmate'),
            esc_url($dismissUrl),
            esc_html__('Don\'t show again', 'mcpmate')
        );
    }

    /**
     * `admin_post_mcpmate_dismiss_setup_notice`: remember the user, then go back.
     */
    public static function dismiss(): void
    {
        check_admin_referer(self::DISMISS_ACTION);

        if (!Capabilities::check('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'mcpmate'), '', ['response' => 403]);
        }

        OnboardingState::dismissNotice(get_current_user_id());

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}

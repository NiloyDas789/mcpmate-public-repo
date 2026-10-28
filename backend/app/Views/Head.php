<?php

namespace McpMate\Views;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Helpers\DateTimeHelper;
use McpMate\I18n;
use McpMate\Services\Approvals\ApprovalStore;
use McpMate\Services\Connection\AccessPause;
use McpMate\Services\Connection\Connection;
use McpMate\Services\Connection\Grants;
use McpMate\Services\OAuth\OAuthConsent;
use McpMate\Services\Onboarding\OnboardingState;

/**
 * Enqueues scripts and styles for the plugin's admin pages.
 *
 * In development mode the Vite dev server is used for HMR. In production
 * the pre-built bundle from the assets folder is loaded instead.
 */
final class Head
{
    /**
     * Enqueue plugin assets only on this plugin's admin pages.
     */
    public function enqueueAssets(string $currentScreen): void
    {
        if (!str_contains($currentScreen, Config::SLUG)) {
            return;
        }

        $slug     = Config::SLUG;
        $version  = Config::VERSION;
        $codeName = Config::get('BUILD_CODE_NAME');

        if (Config::isDev()) {
            $devUrl    = Config::getEnv('DEV_URL');
            $devBase   = esc_url($devUrl . '/wp-content/plugins/' . dirname(Config::get('BASENAME')) . '/frontend');
            $varPrefix = esc_js(Config::VAR_PREFIX);

            // In dev mode, we need to output scripts directly in the head in the correct order
            // WordPress's enqueue system puts scripts in footer which breaks the preamble timing
            add_action('admin_head', function () use ($devBase, $varPrefix) {
                $config = wp_json_encode(self::createFrontendConfig());
                // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped -- Dev-only Vite HMR; vars escaped above (esc_js, esc_url, wp_json_encode).
                echo '<script>window.' . $varPrefix . ' = ' . $config . ';</script>';
                echo '<script type="module">';
                echo 'import RefreshRuntime from "' . esc_url($devBase . '/@react-refresh') . '";';
                echo 'RefreshRuntime.injectIntoGlobalHook(window);';
                echo 'window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (type) => type;';
                echo 'window.__vite_plugin_react_preamble_installed__ = true;';
                echo '</script>';
                // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped -- Dev-only; $devBase is esc_url() above.
                echo '<script type="module" src="' . $devBase . '/@vite/client"></script>';
                // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped -- Dev-only; $devBase is esc_url() above.
                echo '<script type="module" src="' . $devBase . '/src/main.tsx"></script>';
            }, 99);
        } else {
            $assetUri = Config::get('ASSET_URI');
            wp_enqueue_script(
                $slug . '-index-MODULE',
                $assetUri . '/main-' . $codeName . '.js',
                [],
                $version,
                true
            );
            wp_enqueue_style($slug . '-styles', $assetUri . '/main-' . $slug . '-ba-assets-' . $codeName . '.css', [], $version, 'screen');
            wp_localize_script($slug . '-index-MODULE', Config::VAR_PREFIX, self::createFrontendConfig());
        }

        if (!wp_script_is('media-upload')) {
            wp_enqueue_media();
        }
    }

    /**
     * Build the config object passed to the React frontend via wp_localize_script.
     */
    public static function createFrontendConfig(): array
    {
        $config = [
            'nonce'          => wp_create_nonce(Config::withPrefix('nonce')),
            'restNonce'      => wp_create_nonce('wp_rest'),
            'rootURL'        => Config::get('ROOT_URI'),
            'siteURL'        => Config::get('SITE_URL'),
            'siteBaseURL'    => is_multisite() ? network_site_url() : site_url(),
            'assetsURL'      => Config::get('ASSET_URI'),
            'pluginAdminURL' => get_admin_url(null, 'admin.php?page=' . Config::SLUG . '#'),
            'ajaxURL'        => admin_url('admin-ajax.php'),
            'apiURL'         => Config::get('API_URL'),
            'routePrefix'    => Config::VAR_PREFIX,
            'dateFormat'     => Config::getOption('date_format', false, true),
            'timeFormat'     => Config::getOption('time_format', false, true),
            'timeZone'       => DateTimeHelper::timezoneString(),
            'pluginSlug'     => Config::SLUG,
            'pluginName'     => Config::title(),
            'version'        => Config::VERSION,
            'connection'     => Connection::summary(),
            'connectRequest' => self::connectRequest(),
            'onboarding'     => self::onboarding(),
            'lang'           => I18n::currentLocale(),
            'translations'   => I18n::frontendLocaleData(),
            // Shell data (docs/ui.md B11): status pill, notices, tab counts, footer.
            'grants_live'       => Grants::countLive(),
            'pending_approvals' => ApprovalStore::countPending(),
            'access'            => AccessPause::summary(),
            'rtl'               => is_rtl(),
            'links'             => Config::links(),
            // The UI kit page (`#/ui-kit`) exists only while WP_DEBUG is on.
            'debug'             => defined('WP_DEBUG') && WP_DEBUG,
        ];

        $hook_name = Config::withPrefix('localized_script');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook is prefixed via Config::withPrefix().
        $config = apply_filters($hook_name, $config);

        return $config;
    }

    /**
     * Setup progress for the Dashboard card and the Settings link.
     *
     * @return array{status: string, step: string}
     */
    private static function onboarding(): array
    {
        $state = OnboardingState::get();

        return ['status' => (string) $state['status'], 'step' => (string) $state['step']];
    }

    /**
     * The AI-app approval request in `?connect=`: an authorization request
     * of the plugin's own OAuth server (`/authorize` sends the browser
     * there), checked here so the approval screen shows only what this site
     * checked. Null without one.
     *
     * @return array<string, mixed>|null
     */
    private static function connectRequest(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; checked against a strict pattern and the stored request; approving needs the AJAX nonce.
        $id = isset($_GET['connect']) ? (string) wp_unslash($_GET['connect']) : '';

        if ($id === '') {
            return null;
        }

        $checked = OAuthConsent::inspect($id);

        if (!$checked['ok']) {
            return ['ok' => false, 'request' => '', 'code' => $checked['code'], 'message' => $checked['message']];
        }

        return ['ok' => true, 'request' => $id] + OAuthConsent::describe($checked);
    }
}

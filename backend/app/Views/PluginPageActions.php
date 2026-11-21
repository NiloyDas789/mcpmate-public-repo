<?php

namespace MCPMate\Views;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Services\Onboarding\ActivationRedirect;
use MCPMate\Services\Onboarding\OnboardingState;

/**
 * Renders quick-links under the plugin name on the Plugins page.
 */
final class PluginPageActions
{
    /**
     * Define action links shown on the Plugins list page.
     *
     * @return array<string, array{title: string, url: string}>
     */
    public function getActionLinks(): array
    {
        $adminUrl = Config::get('ADMIN_URL') . 'admin.php?page=' . Config::SLUG;

        $links = [];

        if (OnboardingState::isOpen()) {
            $links['setup'] = [
                'title' => __('Set up', 'mcpmate'),
                'url'   => ActivationRedirect::setupUrl(),
            ];
        }

        $links['settings'] = [
            'title' => __('Settings', 'mcpmate'),
            'url'   => $adminUrl . '#/settings',
        ];

        // What Remove all data on uninstall deletes, and a copy of the logs first (Settings → Advanced).
        $links['review_uninstall'] = [
            'title' => __('Review data before deleting', 'mcpmate'),
            'url'   => $adminUrl . '#/settings?section=advanced&review=uninstall',
        ];

        $upgradeUrl = Config::links()['upgrade'];

        if ($upgradeUrl !== '' && !Config::isProActivated()) {
            $links['upgrade_to_pro'] = [
                'title' => __('Upgrade to Pro', 'mcpmate'),
                'url'   => $upgradeUrl,
            ];
        }

        return $links;
    }

    /**
     * Append action links to the Plugins page.
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function renderActionLinks(array $links): array
    {
        foreach ($this->getActionLinks() as $link) {
            $links[] = '<a href="' . esc_url($link['url']) . '">' . esc_html($link['title']) . '</a>';
        }

        return $links;
    }
}

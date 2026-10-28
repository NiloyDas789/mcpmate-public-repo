<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Onboarding\HostClassifier;

/**
 * Check 1: the site's address is HTTPS. Plain HTTP is a warning on a local
 * site (the environment check explains the tunnel) and a failure on a
 * public one: the OAuth and MCP endpoints accept only HTTPS (rule 8).
 */
final class HttpsCheck extends Check
{
    public const ID = 'https';

    private string $homeUrl;

    private string $hostClass;

    public function __construct(string $homeUrl, string $hostClass)
    {
        $this->homeUrl   = $homeUrl;
        $this->hostClass = $hostClass;
    }

    public function run(): array
    {
        $title = __('Your site uses HTTPS', 'mcpmate');

        if (stripos($this->homeUrl, 'https://') === 0) {
            return self::result(self::PASS, $title, __('Your site\'s address starts with https://.', 'mcpmate'));
        }

        if ($this->hostClass === HostClassifier::LOCAL) {
            return self::result(
                self::WARN,
                $title,
                __('Your site uses http://. That\'s normal on your own computer; "Where this site runs" below explains how AI apps reach it over HTTPS.', 'mcpmate')
            );
        }

        return self::result(
            self::FAIL,
            $title,
            __('Your site uses http://. AI apps connect only over HTTPS, so they can\'t reach it.', 'mcpmate'),
            __('Turn on HTTPS for your site (your host can do this; Let\'s Encrypt certificates are free).', 'mcpmate')
                . ' ' . __('Then change both addresses under Settings → General to https:// and select Check again.', 'mcpmate')
        );
    }
}

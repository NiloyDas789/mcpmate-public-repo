<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\OAuth\OAuthUrls;
use McpMate\Services\Onboarding\HostClassifier;
use McpMate\Services\Onboarding\LoopbackDiagnosis;

/**
 * Where the site runs (HostClassifier; docs/mcp-in-plugin.md §10.7). Web
 * apps such as Claude.ai reach a local site only through a tunnel
 * (MCPMATE_PUBLIC_URL). A tunnel to a plain-HTTP address works only on
 * a local site (WP_ENVIRONMENT_TYPE `local` and a local host name, rule 8);
 * one to the site's HTTPS address works anywhere. With a tunnel set, the
 * MCP URL request went through it, and its answer says whether it works.
 * Never a failure: users who know better can continue.
 */
final class EnvironmentCheck extends Check
{
    public const ID = 'environment';

    private string $hostClass;

    private bool $environmentLocal;

    private string $homeUrl;

    private string $publicUrl;

    /** @var array{status: string, key: string, location: string, http_status: int}|null */
    private ?array $tunnel;

    /**
     * @param array{status: string, key: string, location: string, http_status: int}|null $tunnel
     *        LoopbackDiagnosis of the MCP URL request through the tunnel; null without one, or when WordPress rejects its address.
     */
    public function __construct(string $hostClass, bool $environmentLocal, string $homeUrl, string $publicUrl, ?array $tunnel)
    {
        $this->hostClass        = $hostClass;
        $this->environmentLocal = $environmentLocal;
        $this->homeUrl          = $homeUrl;
        $this->publicUrl        = $publicUrl;
        $this->tunnel           = $tunnel;
    }

    public function run(): array
    {
        $title = __('Where this site runs', 'mcpmate');

        if ($this->hostClass === HostClassifier::STAGING) {
            return self::result(
                self::WARN,
                $title,
                __('This looks like a staging copy. That\'s fine for trying McpMate. Your live site has its own MCP URL; AI apps connected to a copy can\'t use it.', 'mcpmate')
            );
        }

        if ($this->hostClass !== HostClassifier::LOCAL) {
            return self::result(self::PASS, $title, __('Your site is on the public internet, where AI apps can reach it.', 'mcpmate'));
        }

        if ($this->publicUrl === '') {
            return $this->tunnelInstructions($title);
        }

        $host = self::host($this->publicUrl);
        $key  = $this->tunnel['key'] ?? LoopbackDiagnosis::UNREACHABLE;

        if ($key === LoopbackDiagnosis::UNREACHABLE) {
            return self::result(
                self::WARN,
                $title,
                /* translators: %s: host name of the tunnel. */
                sprintf(__('Your tunnel %s doesn\'t answer. Is cloudflared still running?', 'mcpmate'), $host),
                __('Start the tunnel again. A quick tunnel gets a new address each time; if it changed, update MCPMATE_PUBLIC_URL in wp-config.php.', 'mcpmate')
            );
        }

        if ($key === LoopbackDiagnosis::INSECURE) {
            return $this->plainHttpTunnel($title, $host);
        }

        if ($key !== LoopbackDiagnosis::OK) {
            return self::result(
                self::WARN,
                $title,
                /* translators: %s: host name of the tunnel. */
                sprintf(__('Your tunnel %s answers, but not with McpMate.', 'mcpmate'), $host)
                    . ' ' . __('Check that it points at this site\'s address; "AI apps can reach your MCP URL" above says what answered.', 'mcpmate'),
                '',
                [['text' => '', 'code' => self::tunnelCommand($this->homeUrl)]]
            );
        }

        /* translators: %s: host name of the tunnel. */
        return self::result(self::PASS, $title, sprintf(__('Your tunnel %s works.', 'mcpmate'), $host));
    }

    /**
     * The tunnel reaches WordPress over plain HTTP, which only a local site
     * on a local host name accepts.
     *
     * @return array{status: string, title: string, message: string, fix: string, details: array<int, array{text: string, code?: string}>}
     */
    private function plainHttpTunnel(string $title, string $host): array
    {
        if (!$this->environmentLocal && OAuthUrls::isLocalHost(self::host($this->homeUrl))) {
            return self::result(
                self::WARN,
                $title,
                /* translators: %s: host name of the tunnel. */
                sprintf(__('Your tunnel %s works, but WordPress doesn\'t know this is a local site, so it refuses AI apps\' requests over the tunnel.', 'mcpmate'), $host),
                __('Add this line to wp-config.php, then select Check again:', 'mcpmate'),
                [['text' => '', 'code' => self::environmentLine()]]
            );
        }

        return self::result(
            self::WARN,
            $title,
            /* translators: %s: host name of the tunnel. */
            sprintf(__('Your tunnel %s reaches WordPress over plain HTTP, which McpMate accepts only for local names (.test, .local, .localhost).', 'mcpmate'), $host),
            __('Point the tunnel at your site\'s HTTPS address instead:', 'mcpmate'),
            [['text' => '', 'code' => 'cloudflared tunnel --url https://' . self::host($this->homeUrl)]]
        );
    }

    /**
     * @return array{status: string, title: string, message: string, fix: string, details: array<int, array{text: string, code?: string}>}
     */
    private function tunnelInstructions(string $title): array
    {
        $lines = "define( 'MCPMATE_PUBLIC_URL', 'https://your-tunnel-address' );";

        if (!$this->environmentLocal) {
            $lines .= "\n" . self::environmentLine();
        }

        return self::result(
            self::WARN,
            $title,
            __('This site runs on your computer. AI apps on this computer can reach it, but web apps such as Claude.ai and ChatGPT can\'t.', 'mcpmate'),
            __('A free Cloudflare Tunnel gives it a temporary public HTTPS address:', 'mcpmate'),
            [
                ['text' => __('1. Install cloudflared (Cloudflare\'s free tunnel tool).', 'mcpmate')],
                [
                    'text' => __('2. Run this command and copy the https:// tunnel address it prints:', 'mcpmate'),
                    'code' => self::tunnelCommand($this->homeUrl),
                ],
                [
                    'text' => __('3. Add these lines to wp-config.php (with your tunnel address), then select Check again:', 'mcpmate'),
                    'code' => $lines,
                ],
                [
                    'text' => __('Your WordPress address doesn\'t change. A quick tunnel gets a new address each time you start it, and then every AI app must be connected again.', 'mcpmate')
                        . ' ' . __('For daily use, a named Cloudflare Tunnel keeps one address.', 'mcpmate'),
                ],
            ]
        );
    }

    private static function tunnelCommand(string $homeUrl): string
    {
        return 'cloudflared tunnel --url ' . rtrim($homeUrl, '/');
    }

    private static function environmentLine(): string
    {
        return "define( 'WP_ENVIRONMENT_TYPE', 'local' );";
    }
}

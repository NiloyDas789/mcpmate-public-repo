<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Onboarding\LoopbackDiagnosis;

/**
 * AI apps can reach the MCP URL (docs/mcp-in-plugin.md §10, checks 4 and 6):
 * the site's own request to its MCP URL without a token must get the
 * plugin's 401, which sends an AI app to sign in. Security plugins, REST API
 * blockers and firewalls that answer instead are named, with the paths to
 * allow. A loopback that cannot connect is only a warning: many hosts block
 * it, and connecting an AI app tests the real path.
 */
final class McpEndpointCheck extends Check
{
    public const ID = 'mcp_endpoint';

    /** @var array{status: string, key: string, location: string, http_status: int} */
    private array $diagnosis;

    /** @var string[] */
    private array $securityPlugins;

    private bool $cloudflare;

    private bool $tunnel;

    /** @var string[] */
    private array $paths;

    private string $server;

    /**
     * @param array{status: string, key: string, location: string, http_status: int} $diagnosis From LoopbackDiagnosis::diagnose().
     * @param string[] $securityPlugins Names of active security plugins.
     * @param string[] $paths           The paths AI apps use, as a firewall rule names them.
     * @param string   $server          SERVER_SOFTWARE, to pick the rewrite fix.
     */
    public function __construct(array $diagnosis, array $securityPlugins, bool $cloudflare, bool $tunnel, array $paths, string $server = '')
    {
        $this->diagnosis       = $diagnosis;
        $this->securityPlugins = $securityPlugins;
        $this->cloudflare      = $cloudflare;
        $this->tunnel          = $tunnel;
        $this->paths           = $paths;
        $this->server          = strtolower($server);
    }

    public function run(): array
    {
        $title = __('AI apps can reach your MCP URL', 'mcpmate');

        switch ($this->diagnosis['key']) {
            case LoopbackDiagnosis::OK:
                return self::result(
                    self::PASS,
                    $title,
                    __('Your MCP URL answers the way AI apps expect: it asks them to sign in.', 'mcpmate'),
                    '',
                    $this->firewallNotes()
                );

            case LoopbackDiagnosis::UNREACHABLE:
                return self::result(
                    self::WARN,
                    $title,
                    __('We couldn\'t test this from your server itself. Many hosts block that, so it may still work. Connecting an AI app tests it for real.', 'mcpmate'),
                    '',
                    $this->firewallNotes()
                );

            case LoopbackDiagnosis::REDIRECT:
                return self::result(
                    self::WARN,
                    $title,
                    $this->diagnosis['location'] !== ''
                        /* translators: %s: the address the site redirects to. */
                        ? sprintf(__('Your site redirects requests for the MCP URL to %s.', 'mcpmate'), $this->diagnosis['location'])
                        : __('Your site redirects requests for the MCP URL to another address.', 'mcpmate'),
                    __('AI apps don\'t follow redirects here, so set Settings → General → Site Address to the address your site really uses.', 'mcpmate')
                );

            case LoopbackDiagnosis::PASSWORD:
                return self::result(
                    self::FAIL,
                    $title,
                    __('Your site is password-protected, so AI apps can\'t get in.', 'mcpmate'),
                    __('Turn off the password (common on staging sites) or connect the live site instead.', 'mcpmate')
                );

            case LoopbackDiagnosis::CLOUDFLARE:
                return self::result(
                    self::FAIL,
                    $title,
                    __('Cloudflare is blocking these requests.', 'mcpmate'),
                    self::cloudflareFix(),
                    $this->pathDetails()
                );

            case LoopbackDiagnosis::PHP_OUTPUT:
                return self::result(
                    self::FAIL,
                    $title,
                    __('PHP warnings are being printed into your site\'s responses, which breaks AI apps.', 'mcpmate'),
                    __('Turn off WP_DEBUG_DISPLAY in wp-config.php:', 'mcpmate'),
                    [['text' => '', 'code' => "define( 'WP_DEBUG_DISPLAY', false );"]]
                );

            case LoopbackDiagnosis::NO_ROUTE:
                return self::result(
                    self::FAIL,
                    $title,
                    __('WordPress answered, but McpMate\'s MCP URL isn\'t loaded.', 'mcpmate'),
                    __('Another plugin may be turning it off. Deactivate plugins that change the REST API, then select Check again.', 'mcpmate')
                );

            case LoopbackDiagnosis::NOT_ROUTED:
                return self::result(
                    self::FAIL,
                    $title,
                    __('Your web server answered "not found" for the MCP URL without asking WordPress: WordPress\'s permalinks aren\'t working on this server.', 'mcpmate'),
                    $this->rewriteFix(),
                    $this->rewriteDetails()
                );

            case LoopbackDiagnosis::INSECURE:
                return self::result(
                    self::FAIL,
                    $title,
                    __('Your site refused the request because it didn\'t arrive over HTTPS.', 'mcpmate'),
                    $this->tunnel
                        ? __('A tunnel reaches your site over plain HTTP, which McpMate accepts only on local sites. See "Where this site runs" below.', 'mcpmate')
                        : __('If a proxy or load balancer handles HTTPS for your site, it must tell WordPress so.', 'mcpmate')
                            . ' ' . __('It sends the X-Forwarded-Proto header, and wp-config.php sets $_SERVER[\'HTTPS\'] from it.', 'mcpmate')
                            . ' ' . __('Your host can help.', 'mcpmate')
                );

            case LoopbackDiagnosis::BLOCKED:
                return self::result(
                    self::FAIL,
                    $title,
                    /* translators: %d: HTTP status code, such as 403. */
                    sprintf(__('Something refused the request before McpMate could answer it (HTTP %d).', 'mcpmate'), $this->diagnosis['http_status'])
                        . ' ' . __('It is usually a security plugin, a firewall, or a setting that turns off the REST API.', 'mcpmate'),
                    $this->allowFix(),
                    $this->pathDetails()
                );

            default:
                return self::result(
                    self::FAIL,
                    $title,
                    __('Something other than WordPress answered: a security plugin, firewall or cache, or the REST API is turned off.', 'mcpmate'),
                    $this->allowFix(),
                    $this->pathDetails()
                );
        }
    }

    /**
     * The Cloudflare skip rule for the paths AI apps use (listed after it).
     */
    public static function cloudflareFix(): string
    {
        return __('In Cloudflare: Security → WAF → Custom rules → Create rule. When URI Path starts with one of the addresses below, choose Skip', 'mcpmate')
            . ' ' . __('and tick All remaining custom rules, Super Bot Fight Mode and Managed rules.', 'mcpmate')
            . ' ' . __('Bot Fight Mode on the free plan can\'t be skipped by a rule; turn it off instead.', 'mcpmate');
    }

    /**
     * How to make the web server pass pretty addresses to WordPress, per server.
     */
    private function rewriteFix(): string
    {
        if (strpos($this->server, 'nginx') !== false) {
            return __('nginx doesn\'t read .htaccess. Ask your host to add WordPress\'s rule to this site\'s server block:', 'mcpmate');
        }

        $fix = __('Save Settings → Permalinks once, so WordPress writes its rules to the .htaccess file, then select Check again.', 'mcpmate');

        if (strpos($this->server, 'litespeed') !== false) {
            return $fix . ' '
                . __('On OpenLiteSpeed, .htaccess changes apply only after a restart (WebAdmin → Graceful Restart, or ask your host), and the site must be set to read .htaccess.', 'mcpmate');
        }

        return $fix . ' ' . __('If it still fails, ask your host to allow .htaccess files (AllowOverride All) and turn on mod_rewrite.', 'mcpmate');
    }

    /**
     * @return array<int, array{text: string, code?: string}>
     */
    private function rewriteDetails(): array
    {
        return strpos($this->server, 'nginx') !== false
            ? [['text' => '', 'code' => 'location / { try_files $uri $uri/ /index.php?$args; }']]
            : [];
    }

    private function allowFix(): string
    {
        if ($this->securityPlugins !== []) {
            /* translators: %s: comma-separated plugin names. */
            return sprintf(__('Allow these addresses in %s, or turn it off for a moment and select Check again:', 'mcpmate'), implode(', ', $this->securityPlugins));
        }

        return __('Check your security plugin, firewall or host for rules that block these addresses, and allow them:', 'mcpmate');
    }

    /**
     * @return array<int, array{text: string, code?: string}>
     */
    private function pathDetails(): array
    {
        return [['text' => '', 'code' => implode("\n", $this->paths)]];
    }

    /**
     * On a pass or an untested loopback: who could still block apps from
     * outside, since firewalls often let the site's own requests through.
     *
     * @return array<int, array{text: string, code?: string}>
     */
    private function firewallNotes(): array
    {
        $names = $this->securityPlugins;

        if ($this->cloudflare) {
            $names[] = 'Cloudflare';
        }

        if ($names === []) {
            return [];
        }

        return [
            [
                /* translators: %s: comma-separated names of security plugins or Cloudflare. */
                'text' => sprintf(__('%s can treat requests from AI apps\' servers differently from your site\'s own.', 'mcpmate'), implode(', ', $names))
                    . ' ' . __('If an app can\'t connect, allow these addresses there:', 'mcpmate'),
                'code' => implode("\n", $this->paths),
            ],
        ];
    }
}

<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI apps can find how to sign in (docs/mcp-in-plugin.md §4.2, §10.2,
 * check 3). The site fetches each discovery address and compares the
 * document with its own: `I/resource-metadata` (named by the MCP URL's 401,
 * so every app finds it), the path-appended AS metadata under `I`, and the
 * `/.well-known` addresses, which nginx rules, cPanel and some hosts keep
 * for themselves.
 *
 * Apps built on the official MCP SDK (Claude Code among them) look for the
 * server metadata only at `/.well-known/oauth-authorization-server/…`
 * (`server_inserted`): they never try the path-appended copy, and they reject
 * the OpenID documents, which lack the ID token fields OpenID requires
 * (docs/mcp-in-plugin.md §13.2). Without that address they can't connect.
 */
final class WellKnownCheck extends Check
{
    public const ID = 'well_known';

    /** The document is this site's. */
    public const OK = 'ok';

    /** Something answered with another document, an error or a page. */
    public const WRONG = 'wrong';

    /** The request could not be made (loopback blocked). */
    public const UNTESTED = 'untested';

    private const NGINX_RULE = 'location ^~ /.well-known/oauth- { try_files $uri /index.php?$args; }' . "\n"
        . 'location ^~ /.well-known/openid-configuration { try_files $uri /index.php?$args; }';

    private const APACHE_RULE = "RewriteEngine On\nRewriteRule ^\\.well-known/(oauth-|openid-configuration) index.php [L]";

    /** URL ids, in the order they are shown. */
    public const URLS = ['resource_metadata', 'server_appended', 'resource_inserted', 'resource_root', 'server_inserted', 'openid_inserted'];

    /** @var array<string, array{url: string, result: string}> */
    private array $results;

    private bool $permalinks;

    private string $homePath;

    private string $server;

    /**
     * @param array<string, array{url: string, result: string}> $results Per URL id (URLS): its address and OK, WRONG or UNTESTED.
     * @param string $homePath The site's path under its host ('' at the root).
     * @param string $server   SERVER_SOFTWARE, to pick the rule.
     */
    public function __construct(array $results, bool $permalinks, string $homePath, string $server)
    {
        $this->results    = $results;
        $this->permalinks = $permalinks;
        $this->homePath   = trim($homePath, '/');
        $this->server     = strtolower($server);
    }

    public function run(): array
    {
        $title = __('AI apps can find how to sign in', 'mcpmate');

        if (!$this->permalinks) {
            return self::result(self::INFO, $title, __('Checked once permalinks are fixed.', 'mcpmate'));
        }

        if ($this->all(self::UNTESTED)) {
            return self::result(self::WARN, $title, __('We couldn\'t test this from your server itself. Connecting an AI app tests it for real.', 'mcpmate'));
        }

        if (!$this->ok('resource_metadata')) {
            return self::result(
                self::FAIL,
                $title,
                __('The address AI apps are sent to for signing in doesn\'t answer with McpMate\'s details, so no app can connect.', 'mcpmate'),
                __('Save Settings → Permalinks once to refresh WordPress\'s addresses, then select Check again.', 'mcpmate')
                    . ' ' . __('If it still fails, a cache or security rule is answering for WordPress at:', 'mcpmate'),
                [['text' => '', 'code' => $this->results['resource_metadata']['url']]]
            );
        }

        $failing = array_values(array_filter(
            ['resource_inserted', 'resource_root', 'server_inserted', 'openid_inserted'],
            fn (string $id): bool => !$this->ok($id)
        ));

        $resourceFound = $this->ok('resource_inserted') || $this->ok('resource_root');

        if ($failing === [] || ($resourceFound && $this->ok('server_inserted'))) {
            return self::result(self::PASS, $title, __('Every address AI apps use to find how to sign in answers correctly.', 'mcpmate'));
        }

        $details   = [];
        $details[] = [
            'text' => __('These addresses don\'t answer with McpMate\'s details:', 'mcpmate'),
            'code' => implode("\n", array_map(fn (string $id): string => $this->results[$id]['url'], $failing)),
        ];

        if ($this->homePath !== '') {
            $details[] = [
                /* translators: %s: the folder WordPress is installed in, such as /blog. */
                'text' => sprintf(__('WordPress is in a folder (/%s), so these addresses belong to the website at the root of your domain. Add the rule there.', 'mcpmate'), $this->homePath),
            ];
        }

        $details[] = $this->isNginx()
            ? ['text' => __('nginx, in this site\'s server block:', 'mcpmate'), 'code' => self::NGINX_RULE]
            : ['text' => __('Apache, in .htaccess before the WordPress block:', 'mcpmate'), 'code' => self::APACHE_RULE];

        if ($this->ok('server_inserted')) {
            return self::result(
                self::WARN,
                $title,
                __('The main sign-in addresses work, so most AI apps can connect. Apps that skip them and look only in /.well-known/ may fail.', 'mcpmate'),
                __('Ask your host to pass these /.well-known/ addresses to WordPress, or add the rule yourself.', 'mcpmate'),
                $details
            );
        }

        return self::result(
            self::FAIL,
            $title,
            $this->ok('server_appended') || $this->ok('openid_inserted')
                ? __('Your web server keeps /.well-known/ for itself, so Claude Code and other apps built on the official MCP SDK can\'t find where to sign in. They look only there.', 'mcpmate')
                : __('AI apps can\'t find where to sign in: your web server keeps /.well-known/ for itself, and the fallback address doesn\'t answer either.', 'mcpmate'),
            __('Ask your host to pass these /.well-known/ addresses to WordPress, or add the rule yourself.', 'mcpmate'),
            $details
        );
    }

    private function ok(string $id): bool
    {
        return ($this->results[$id]['result'] ?? '') === self::OK;
    }

    private function all(string $result): bool
    {
        foreach (self::URLS as $id) {
            if (($this->results[$id]['result'] ?? self::UNTESTED) !== $result) {
                return false;
            }
        }

        return true;
    }

    private function isNginx(): bool
    {
        return strpos($this->server, 'nginx') !== false;
    }
}

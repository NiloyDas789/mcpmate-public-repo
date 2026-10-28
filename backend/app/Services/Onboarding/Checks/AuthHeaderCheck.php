<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\OAuth\Probe;

/**
 * The `Authorization` header reaches WordPress (docs/mcp-in-plugin.md §10.1,
 * check 2). Apache with PHP as CGI or FastCGI, and some proxies, drop it;
 * every AI app request then looks anonymous and the app keeps asking to
 * sign in. Judged from the site's own `POST I/probe` (Probe).
 */
final class AuthHeaderCheck extends Check
{
    public const ID = 'auth_header';

    /** @var array{error: string, status: int, headers: array<string, string>, body: string} */
    private array $response;

    private bool $permalinks;

    private string $server;

    /**
     * @param array{error: string, status: int, headers: array<string, string>, body: string} $response The probe's answer.
     * @param bool   $permalinks Whether pretty permalinks are on (the probe is a rewrite rule).
     * @param string $server     SERVER_SOFTWARE, to pick the fix.
     */
    public function __construct(array $response, bool $permalinks, string $server)
    {
        $this->response   = $response;
        $this->permalinks = $permalinks;
        $this->server     = strtolower($server);
    }

    public function run(): array
    {
        $title = __('Sign-in details reach WordPress', 'mcpmate');

        if (!$this->permalinks) {
            return self::result(self::INFO, $title, __('Checked once permalinks are fixed.', 'mcpmate'));
        }

        $answer = $this->response['error'] === '' && $this->response['status'] === 200
            ? json_decode($this->response['body'], true)
            : null;

        if (!is_array($answer) || !isset($answer['authorization'])) {
            return self::result(
                self::WARN,
                $title,
                __('We couldn\'t test this from your server itself. If an AI app keeps asking you to sign in again, your web server may be dropping the Authorization header.', 'mcpmate'),
                '',
                $this->fixDetails()
            );
        }

        if ($answer['authorization'] === Probe::RECEIVED) {
            return self::result(self::PASS, $title, __('Your web server passes the Authorization header on to WordPress.', 'mcpmate'));
        }

        return self::result(
            self::FAIL,
            $title,
            $answer['authorization'] === Probe::CHANGED
                ? __('Something in front of WordPress changes the Authorization header, so AI apps would look signed out on every request.', 'mcpmate')
                : __('Your web server removes the Authorization header before WordPress sees it, so AI apps would look signed out on every request.', 'mcpmate'),
            $this->fixText(),
            $this->fixDetails()
        );
    }

    private function fixText(): string
    {
        if ($this->isNginx()) {
            return __('Ask your host to pass the header to PHP. In the nginx configuration of this site, next to the other fastcgi_param lines:', 'mcpmate');
        }

        return __('Save Settings → Permalinks once (recent WordPress versions add the rule for you), then select Check again.', 'mcpmate')
            . ' ' . __('If it still fails, add these lines to the top of the .htaccess file in your WordPress folder:', 'mcpmate');
    }

    /**
     * @return array<int, array{text: string, code?: string}>
     */
    private function fixDetails(): array
    {
        if ($this->isNginx()) {
            return [['text' => '', 'code' => 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;']];
        }

        return [
            ['text' => '', 'code' => "RewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]"],
            ['text' => __('Or, without mod_rewrite:', 'mcpmate'), 'code' => 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1'],
        ];
    }

    private function isNginx(): bool
    {
        return strpos($this->server, 'nginx') !== false;
    }
}

<?php

namespace MCPMate;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Extensions\Features;
use MCPMate\Hooks\Hooks;
use MCPMate\HTTP\RequestType;
use MCPMate\Migration\MigrationHelper;
use MCPMate\Utils\Capabilities;
use MCPMate\HTTP\Middleware\AdminCheckerMiddleware;
use MCPMate\HTTP\Middleware\CapabilityMiddleware;
use MCPMate\HTTP\Middleware\NonceCheckerMiddleware;
use MCPMate\HTTP\Middleware\WpAdminMiddleware;
use MCPMate\Providers\HookProvider;
use MCPMate\Providers\InstallerProvider;
use MCPMate\Services\Connection\RestGuard;
use MCPMate\Services\Mcp\McpRoute;
use MCPMate\Services\Onboarding\ActivationRedirect;
use MCPMate\Services\Onboarding\SetupNotice;
use MCPMate\Views\HtmlTagModifier;
use MCPMate\Views\Layout;
use MCPMate\Views\PluginPageActions;

/**
 * Main plugin orchestrator.
 *
 * Follows the singleton pattern so WordPress only boots the plugin once.
 * All side-effects (hooks, providers, migrations) are registered through
 * dedicated provider classes to keep this file lean and readable.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $resolvedMiddleware = [];

    private function __construct()
    {
        $this->registerInstaller();

        // As early as possible: the current user can be resolved before
        // plugins_loaded, and an MCP call's bearer token (and Application
        // Passwords, refused on REST_NS) must be seen when it is.
        RestGuard::register();
        McpRoute::register();

        Hooks::addAction('plugins_loaded', [$this, 'loaded']);
        // MCPMate Pro registers its features on plugins_loaded (priority 11); nothing can after this.
        Hooks::addAction('plugins_loaded', [Features::class, 'seal'], 20);
    }

    // ──────────────────────────────────────────────
    //  Lifecycle
    // ──────────────────────────────────────────────

    /**
     * Fires on `plugins_loaded` — the earliest safe point to register
     * providers and check DB state.
     */
    public function loaded(): void
    {
        Hooks::doAction(Config::withPrefix('loaded'));

        Hooks::addAction('init', [$this, 'registerProviders'], 8);

        Hooks::addFilter(
            'plugin_action_links_' . Config::get('BASENAME'),
            [new PluginPageActions(), 'renderActionLinks']
        );

        $this->maybeMigrateDB();
    }

    /**
     * Register service providers based on request type.
     */
    public function registerProviders(): void
    {
        if (RequestType::is('admin')) {
            new Layout();
            new HtmlTagModifier();
            ActivationRedirect::register();
            SetupNotice::register();
        }

        new HookProvider();
    }

    // ──────────────────────────────────────────────
    //  Middleware
    // ──────────────────────────────────────────────

    /**
     * Middleware map — alias => class.
     *
     * @return array<string, class-string>
     */
    public function middlewares(): array
    {
        return [
            'nonce'   => NonceCheckerMiddleware::class,
            'isAdmin' => AdminCheckerMiddleware::class,
            'can'     => CapabilityMiddleware::class,
            'wpAdmin' => WpAdminMiddleware::class,
        ];
    }

    /**
     * @return object|false
     */
    public function getMiddleware(string $name)
    {
        if (isset($this->resolvedMiddleware[$name])) {
            return $this->resolvedMiddleware[$name];
        }

        $middlewares = $this->middlewares();

        if (
            isset($middlewares[$name])
            && class_exists($middlewares[$name])
            && method_exists($middlewares[$name], 'handle')
        ) {
            $this->resolvedMiddleware[$name] = new $middlewares[$name]();

            return $this->resolvedMiddleware[$name];
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  Database migration
    // ──────────────────────────────────────────────

    private function maybeMigrateDB(): void
    {
        if (!Capabilities::check('manage_options')) {
            return;
        }

        if (version_compare(Config::getOption('db_version'), Config::DB_VERSION, '<')) {
            MigrationHelper::migrate(InstallerProvider::migration());
        }
    }

    // ──────────────────────────────────────────────
    //  Installer
    // ──────────────────────────────────────────────

    private function registerInstaller(): void
    {
        (new InstallerProvider())->register();
    }

    // ──────────────────────────────────────────────
    //  Singleton access
    // ──────────────────────────────────────────────

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function load(): bool
    {
        if (self::$instance !== null) {
            return false;
        }

        self::$instance = new self();

        return true;
    }
}

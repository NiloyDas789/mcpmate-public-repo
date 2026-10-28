<?php

namespace McpMate\Services\Onboarding\Checks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check 4: who is setting this up. Always passes (the page needs
 * manage_options); it tells the admin that AI apps they approve act as them.
 */
final class AdminCheck extends Check
{
    public const ID = 'admin';

    private string $login;

    private string $role;

    public function __construct(string $login, string $role)
    {
        $this->login = $login;
        $this->role  = $role;
    }

    public function run(): array
    {
        return self::result(
            self::PASS,
            __('You\'re an administrator', 'mcpmate'),
            $this->role !== ''
                /* translators: 1: user login, 2: role name. */
                ? sprintf(__('You\'re signed in as %1$s (%2$s). AI apps you approve will act as you.', 'mcpmate'), $this->login, $this->role)
                /* translators: %s: user login. */
                : sprintf(__('You\'re signed in as %s. AI apps you approve will act as you.', 'mcpmate'), $this->login)
        );
    }
}

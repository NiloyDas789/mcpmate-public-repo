# MCPMate

**Let Claude, ChatGPT and Cursor work on your WordPress site over MCP. You approve each AI app, choose its tools, and every change is logged.**

MCPMate turns a WordPress site into an [MCP (Model Context Protocol)](https://modelcontextprotocol.io) server. The plugin is the MCP server and its own OAuth sign-in server: AI apps connect straight to the site's own MCP URL (`/wp-json/mcpmate/mcp`), a site administrator approves each one in wp-admin, and its tools run inside WordPress, with WordPress's own capability checks. Nothing runs on anyone else's servers. You don't need an account or an API key.

This repository is the source of the free MCPMate plugin.

## What it does

**You stay in control**

- **Every AI app is approved by hand.** An app that adds your site opens wp-admin and waits until an administrator approves it. Apps can be revoked at any time, and there is no "remember this app".
- **An AI app never has more rights than the user who approved it.** It acts as that WordPress user, through the same capability checks.
- **You choose the tools.** Each tool is switched on or off. Anything beyond reading starts off. Each app gets Full, Read-only or a custom set of tools.
- **Everything is recorded.** The audit log lists every call with its app, tool and result. Change history keeps a before-and-after copy of everything that changed, with a diff.
- **Pause AI access** stops every app at once. **Read mode** keeps them connected but lets them only read. Each app also has a limit on calls per minute.

**What AI apps can do, once you switch it on**

- Understand the site: versions, the active theme, page builders, SEO plugins, and the rendered HTML of public pages.
- List block types and check Gutenberg block markup.
- Read the database without SQL. Passwords and secrets are never returned.
- Run read-only WP-CLI-style commands as reviewed PHP handlers, never in a shell. Each command is enabled by name.
- List and run read-only abilities that other plugins register with the WordPress Abilities API.
- Use skills: a tested SEO audit playbook, plus saved conventions, which are also served as MCP prompts.
- Search stock photos on Unsplash with your own key (optional).

**For administrators**

- `wp mcpmate` WP-CLI commands: status, pause and resume, read mode, apps, tools and the audit log.
- A review screen before uninstalling, which lists what would be removed and exports the audit log and change history.

MCPMate Pro is a separate paid add-on, and its code isn't in this repository. It adds the tools that change a site, such as drafts, media, theme drafts and integrations. Everything here works without it.

## Requirements

- WordPress 6.0+ and PHP 7.4+
- HTTPS and pretty permalinks (a local development site may use plain HTTP)

## Building from source

You need Node 20+, pnpm 9+ and Composer 2.

```bash
composer install --no-dev        # the autoloader (no runtime packages)
pnpm install --frozen-lockfile
pnpm build                       # writes the admin app to assets/
```

Copy (or symlink) the folder into `wp-content/plugins/mcpmate`, or zip it and upload it under **Plugins → Add New → Upload Plugin**. Then activate **MCPMate** and open it from the admin sidebar. A setup wizard walks you through connecting your first AI app.

## Development

```bash
cp .env.example .env   # DEV = true makes WordPress load the Vite dev server
pnpm dev               # http://localhost:3000 with hot reload
```

| Command      | Purpose                                                          |
| ------------ | ---------------------------------------------------------------- |
| `pnpm build` | Type-check and build the admin app                               |
| `pnpm check` | Type-check, ESLint, Stylelint, Prettier and the Vitest suite     |
| `pnpm test`  | Vitest tests of the admin app                                    |
| `pnpm i18n`  | Regenerate translation files (needs `wp` and gettext's `msgfmt`) |

The PHP test suites, coding-standard and static-analysis configs are kept in the private development repository with MCPMate Pro, because they run against both plugins. This repository is synced from it, so pull requests are applied there and appear here with the next sync.

## Layout

```
mcpmate.php                Plugin header
backend/
  bootstrap.php            Composer autoload, .env, Plugin::load()
  app/                     PSR-4 namespace MCPMate\
    HTTP/                  Router, middleware, REST and admin-ajax controllers
    Services/Mcp/          The MCP endpoint, tools and policy
    Services/OAuth/        The OAuth authorization server
    Services/…             Audit log, approvals, change history, tools, settings…
    Console/               wp mcpmate commands
  hooks/                   REST (api.php) and admin-ajax (ajax.php) routes
  db/Migrations/           One class per migration
frontend/src/              React 18 + TypeScript admin app (Vite, Ant Design, Tailwind)
docs/                      MCP tool definitions and UI and onboarding specs
skills/                    Playbooks served to AI apps
```

## Security

Please don't open public issues for vulnerabilities. See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

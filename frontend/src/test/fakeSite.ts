/**
 * A stand-in for this plugin's backend (admin-ajax and REST), with state,
 * for page tests and for `pnpm test:a11y`, which serves it from the Vite dev
 * server. It answers only the routes the Dashboard, Connections, Tools,
 * Activity and Settings pages call, in the shapes `backend/` returns. Never
 * shipped: nothing in the app imports it.
 */
import type {
  AccessState,
  ChatSession,
  ChatSettingsView,
  ReadModeState,
  Approval,
  AuditLogEntry,
  BlockBatch,
  BlockBatchStatus,
  Design,
  DesignStarter,
  ChangeDetail,
  ChangeEntry,
  ChecksResponse,
  ClientsResponse,
  ConnectionSummary,
  Grant,
  LicenseStatus,
  Memory,
  SandboxPlugin,
  SavedSkill,
  SystemSkill,
  RegisteredClient,
  Preferences,
  SiteInstructions,
  StatsSummary,
  IntegrationPlugin,
  ToolGroup,
  ToolItem,
  ToolsResponse,
  ToolsUpdate,
} from "../types/api";

export type Scenario = "none" | "one" | "many" | "paused" | "errors";

export interface FakeSiteOptions {
  scenario?: Scenario;
  /**
   * Routes (`grants/list`, `stats`…) that answer with an error. A REST
   * write is named with its method: `POST tools`.
   */
  fail?: string[];
  /** Routes that never answer (loading states). */
  hang?: string[];
  /**
   * `preferences/update` refuses with these errors (key => reason), as the
   * server does for a value it does not accept.
   */
  settingsErrors?: Record<string, string>;
  /** Settings a filter sets in code: key => days. */
  filtered?: Record<string, number>;
  /** Saved settings other than the defaults. */
  preferences?: Partial<Preferences>;
  /** Saved AI instructions (MCPMate Pro); off and empty when not given. */
  siteInstructions?: Partial<SiteInstructions>;
  /** Memories (MCPMate Pro), in any order; none when not given. */
  memories?: Memory[];
  /** Saved skills (Tools → Skills); none when not given. */
  skills?: SavedSkill[];
  /** An approved request fails when it runs (the command was turned off meanwhile). */
  approvalFails?: boolean;
  /** Read mode is on (P21). */
  readMode?: boolean;
  /** MCPMate Pro's Chat settings (P22); no Chat when not given. */
  chat?: Partial<ChatSettingsView>;
}

const minutes = (n: number) => new Date(Date.now() - n * 60_000).toISOString();
const iso = (value: string) => value.replace(/\.\d{3}Z$/, "Z");
const utc = (n: number) => iso(minutes(n)).replace("T", " ").replace("Z", "");

const CLIENTS: [string, string | null][] = [
  ["Claude", "claude.ai"],
  ["ChatGPT", "chatgpt.com"],
  ["Cursor", null],
  ["Claude Code", "claude.ai"],
  ["Windsurf", null],
  ["Zed", "zed.dev"],
  ["Raycast", "raycast.com"],
  ["Goose", null],
  ["Le Chat", "chat.mistral.ai"],
  ["Copilot", "github.com"],
  ["Perplexity", "perplexity.ai"],
  ["Continue", null],
];

export function makeGrant(
  index: number,
  overrides: Partial<Grant> = {},
): Grant {
  const [name, host] = CLIENTS[index % CLIENTS.length];
  const id = `g_${String.fromCharCode(65 + (index % 26)).repeat(22)}`;

  return {
    grant_id: id,
    status: "active",
    client_name: name,
    client_id: `https://${host ?? "example.com"}/oauth/client.json`,
    vouched_by: host ? `cimd:${host}` : "dcr",
    redirect_host: host ?? "localhost",
    scopes: ["site:read", "site:write"],
    access: "full",
    tools: null,
    user: { id: 1, login: "admin", display_name: "Niloy" },
    created_at: iso(minutes(60 * 24 * (index + 2))),
    last_used_at: iso(minutes(5 + index * 37)),
    first_call_at: iso(minutes(60 * 24 * (index + 1))),
    revoked_at: null,
    revoke_reason: null,
    ...overrides,
  };
}

/** A 64-hex client key, as the server's SHA-256 of the client ID. */
const fakeKey = (index: number) => index.toString(16).padStart(64, "0");

/**
 * One registered client per client ID among the grants, plus one that
 * registered itself and was never approved.
 */
function clientsFixture(grants: Grant[]): RegisteredClient[] {
  if (grants.length === 0) {
    return [];
  }

  const byId = new Map<string, Grant[]>();
  grants.forEach((grant) =>
    byId.set(grant.client_id, [...(byId.get(grant.client_id) ?? []), grant]),
  );

  const clients = [...byId.entries()].map(([clientId, of], index) => {
    const live = of.filter((grant) => grant.status !== "revoked").length;
    const cimd = clientId.startsWith("https://");

    return {
      client_key: fakeKey(index + 1),
      client_id: cimd
        ? clientId
        : `mcpm_c_${String.fromCharCode(97 + index).repeat(22)}`,
      kind: cimd ? "cimd" : "dcr",
      client_name: of[0].client_name,
      verified_host: cimd ? new URL(clientId).host : null,
      redirect_hosts: [of[0].redirect_host],
      live_grants: live,
      status: live > 0 ? "connected" : "not_connected",
      created_at: of[of.length - 1].created_at,
      last_used_at: of[0].last_used_at,
      revoked_at: null,
    } satisfies RegisteredClient;
  });

  return [
    {
      client_key: fakeKey(999),
      client_id: "mcpm_c_unusedunusedunusedunu",
      kind: "dcr",
      client_name: "Unknown tool",
      verified_host: null,
      redirect_hosts: ["127.0.0.1"],
      live_grants: 0,
      status: "unused",
      created_at: iso(minutes(30)),
      last_used_at: null,
      revoked_at: null,
    },
    ...clients,
  ];
}

/** Twelve live apps (Full, Read-only, Custom, pending, a deleted user) and two revoked. */
function manyGrants(): Grant[] {
  const live = Array.from({ length: 12 }, (_, index) => {
    if (index === 1) {
      return makeGrant(index, {
        access: "read_only",
        scopes: ["site:read"],
        user: { id: 2, login: "editor", display_name: "Edie" },
      });
    }
    if (index === 2) {
      return makeGrant(index, {
        status: "pending",
        access: "custom",
        tools: ["site_info", "check_approval", "get_page_html", "query_db"],
        last_used_at: null,
        first_call_at: null,
        created_at: iso(minutes(30)),
      });
    }
    if (index === 4) {
      return makeGrant(index, {
        user: { id: 12, login: null, display_name: null },
      });
    }
    return makeGrant(index);
  });

  return [
    ...live,
    makeGrant(13, {
      status: "revoked",
      client_name: "Old Claude",
      revoked_at: iso(minutes(60 * 24 * 6)),
      revoke_reason: "admin",
    }),
    makeGrant(14, {
      status: "revoked",
      client_name: "Test app",
      vouched_by: "dcr",
      revoked_at: iso(minutes(60 * 3)),
      revoke_reason: "admin",
    }),
  ];
}

/** The site's MCP URL (the same in every scenario: nothing is registered). */
export function connectionFor(): ConnectionSummary {
  return {
    mcp_url: "https://example.com/wp-json/mcpmate/mcp",
    issuer: "https://example.com/mcpmate",
    home_url: "https://example.com",
    public_url: "",
    open_registration: true,
  };
}

function tool(
  name: string,
  label: string,
  kind: "read" | "write",
  enabled: boolean,
  extra: {
    approval?: boolean;
    always_on?: boolean;
    description?: string;
    items?: ToolItem[];
  } = {},
) {
  return {
    name,
    label,
    description: extra.description ?? "",
    kind,
    approval: Boolean(extra.approval),
    always_on: Boolean(extra.always_on),
    enabled,
    allowed: enabled,
    ...(extra.items && { items: extra.items }),
  };
}

function item(
  name: string,
  label: string,
  kind: "read" | "write",
  enabled: boolean,
  approval = false,
): ToolItem {
  return { name, label, kind, approval, enabled };
}

/** An ability of `run_ability`, listed under its source (its category's label). */
function ability(
  entry: ToolItem,
  section: string,
  description: string,
): ToolItem {
  return { ...entry, section, description };
}

/** A plugin integration operation, listed under its plugin's section. */
function operation(
  name: string,
  label: string,
  kind: "read" | "write",
  enabled: boolean,
  section: string,
  approval = false,
): ToolItem {
  return {
    ...item(name, label, kind, enabled, approval),
    description: "",
    section,
    integration: name.split(".")[0],
  };
}

function group(
  id: string,
  label: string,
  source: ToolGroup["source"],
  tools: ToolGroup["tools"],
  unavailable: ToolGroup["unavailable_reason"] = "",
): ToolGroup {
  return {
    id,
    label,
    source,
    available: unavailable === "",
    unavailable_reason: unavailable,
    options: id === "media" ? { svg: false } : {},
    tools,
  };
}

function integration(
  id: string,
  label: string,
  status: IntegrationPlugin["status"],
  category: IntegrationPlugin["category"],
  theme = false,
): IntegrationPlugin {
  return { id, label, status, theme, category };
}

export function toolsFixture(): ToolsResponse {
  const groups = [
    group("site_overview", "Site overview", "core", [
      tool("site_info", "Get a summary of this site", "read", true, {
        description: "Site name, versions, theme, plugins and settings.",
      }),
      tool("get_page_html", "Read a page's HTML", "read", true, {
        description: "The HTML a visitor gets for one page of this site.",
      }),
      tool("get_audit_log", "Read the audit log", "read", true),
      tool("check_approval", "Check on an approval request", "read", true, {
        always_on: true,
        description: "Whether you approved a request that asked you first.",
      }),
    ]),
    group("posts", "Posts and pages", "core", [
      tool("core_content", "Posts and pages", "write", false),
    ]),
    group("blocks", "Blocks", "core", [
      tool("list_block_types", "List block types", "read", true),
      tool("validate_blocks", "Check block markup", "read", true),
    ]),
    group("media", "Media", "core", [
      tool("upload_media", "Upload an image or PDF", "write", false, {
        description: "Add one image or PDF to the media library.",
      }),
    ]),
    group("theme_drafts", "Theme drafts", "core", [
      tool(
        "create_draft_theme",
        "Create a draft copy of the theme",
        "write",
        true,
      ),
      tool("list_theme_files", "List draft theme files", "read", true),
      tool("read_theme_file", "Read a draft theme file", "read", true),
      tool("write_theme_file", "Write a draft theme file", "write", false),
      tool("publish_draft_theme", "Publish a draft theme", "write", false, {
        approval: true,
      }),
    ]),
    group("database", "Database", "core", [
      tool("query_db", "Look up database rows", "read", true),
    ]),
    group("wp_cli", "WP-CLI commands", "core", [
      tool("run_wp_cli", "Run WP-CLI commands", "write", true, {
        approval: true,
        items: [
          item("core version", "Show the WordPress version", "read", true),
          item("plugin list", "List plugins", "read", true),
          item("post list", "List posts", "read", false),
          item("cache flush", "Empty the object cache", "write", true),
          item("option update", "Change one option", "write", false),
          item(
            "search-replace",
            "Search and replace in the database",
            "write",
            false,
            true,
          ),
        ],
      }),
    ]),
    group(
      "elementor",
      "Elementor",
      "integrations",
      [
        tool("elementor_get", "Read an Elementor page", "read", false),
        tool("elementor_widgets", "List Elementor widgets", "read", false),
        tool(
          "elementor_save",
          "Save an Elementor page as a draft",
          "write",
          false,
        ),
      ],
      "plugin_missing",
    ),
    {
      ...group("integrations", "Plugin integrations", "integrations", [
        tool(
          "list_integration_tools",
          "List the tools MCPMate has for your plugins",
          "read",
          true,
        ),
        tool(
          "run_integration_tool",
          "Run a tool MCPMate has for one of your plugins",
          "write",
          true,
          {
            items: [
              operation(
                "cf7.check_setup",
                "Check Contact Form 7's setup",
                "read",
                true,
                "Contact Form 7",
              ),
              operation(
                "cf7.list_forms",
                "List forms",
                "read",
                true,
                "Contact Form 7",
              ),
              operation(
                "cf7.update_form_fields",
                "Change a form's fields",
                "write",
                false,
                "Contact Form 7",
              ),
              operation(
                "cf7.update_notifications",
                "Change where a form's mail goes",
                "write",
                false,
                "Contact Form 7",
                true,
              ),
              operation(
                "yoast.check_setup",
                "Check Yoast SEO's setup",
                "read",
                false,
                "Yoast SEO (plugin not active)",
              ),
              operation(
                "yoast.get_post_seo",
                "Read a post's SEO",
                "read",
                false,
                "Yoast SEO (plugin not active)",
              ),
            ],
          },
        ),
      ]),
      options: {
        plugins: [
          integration("cf7", "Contact Form 7", "active", "forms"),
          integration("wpforms", "WPForms", "missing", "forms"),
          integration("yoast", "Yoast SEO", "installed", "seo"),
          integration("acf", "Advanced Custom Fields", "missing", "fields"),
          integration("woocommerce", "WooCommerce", "missing", "store"),
          integration("elementor", "Elementor", "missing", "builders"),
          integration("divi", "Divi", "missing", "builders", true),
          integration(
            "generatepress",
            "GeneratePress",
            "missing",
            "themes",
            true,
          ),
        ],
      },
    },
    group("abilities", "Abilities", "abilities", [
      tool("list_abilities", "List abilities", "read", true),
      tool("run_ability", "Run an ability", "write", true, {
        items: [
          ability(
            item("shop/export-orders", "Export orders", "write", false),
            "Shop",
            "Exports the shop's orders as a CSV file.",
          ),
          ability(
            item(
              "shop/delete-drafts",
              "Delete old drafts",
              "write",
              false,
              true,
            ),
            "Shop",
            "Deletes product drafts older than 30 days.",
          ),
          ability(
            item("forms/list-entries", "List form entries", "read", true),
            "Forms",
            "Lists the entries of one form, newest first.",
          ),
        ],
      }),
    ]),
    {
      ...group(
        "stock_photos",
        "Stock photos",
        "integrations",
        [
          tool(
            "search_stock_photos",
            "Search free stock photos",
            "read",
            false,
          ),
        ],
        "not_configured",
      ),
      options: { configured: false, app_name: "" },
    },
  ];

  return { groups, counts: countTools(groups) };
}

/** As ToolRegistry::counts(): items instead of their tool, available groups only. */
function countTools(groups: ToolGroup[]): ToolsResponse["counts"] {
  const states = groups
    .filter((entry) => entry.available)
    .flatMap((entry) => entry.tools)
    .flatMap((entry) =>
      entry.items
        ? entry.items.map((one) => entry.enabled && one.enabled)
        : [entry.enabled],
    );

  return {
    enabled: states.filter(Boolean).length,
    total: states.length,
  };
}

/**
 * POST /tools as ToolRegistry::apply() checks it: every name known and
 * every value a boolean, or nothing is saved.
 */
function applyToolsUpdate(
  current: ToolsResponse,
  body: ToolsUpdate,
): ToolsResponse | string {
  const all = current.groups.flatMap((entry) => entry.tools);
  const find = (name: string) => all.find((entry) => entry.name === name);

  for (const [name, on] of Object.entries(body.tools ?? {})) {
    const found = find(name);
    if (!found) {
      return `"${name}" is not a tool on this site.`;
    }
    if (typeof on !== "boolean") {
      return `The value for "${name}" must be true or false.`;
    }
    if (found.always_on && !on) {
      return `"${name}" is always on.`;
    }
  }
  for (const [name, states] of Object.entries(body.items ?? {})) {
    const found = find(name);
    if (!found?.items) {
      return `"${name}" has no items to turn on or off.`;
    }
    for (const key of Object.keys(states)) {
      if (!found.items.some((one) => one.name === key)) {
        return `"${key}" is not an item of "${name}" on this site.`;
      }
    }
  }

  const groups = current.groups.map((entry) => ({
    ...entry,
    options:
      entry.id === "media" && body.options?.svg !== undefined
        ? { svg: body.options.svg }
        : entry.options,
    tools: entry.tools.map((one) => {
      const enabled = body.tools?.[one.name] ?? one.enabled;
      const states = body.items?.[one.name] ?? {};

      return {
        ...one,
        enabled,
        allowed: enabled,
        ...(one.items && {
          items: one.items.map((each) => ({
            ...each,
            enabled: states[each.name] ?? each.enabled,
          })),
        }),
      };
    }),
  }));

  return { groups, counts: countTools(groups) };
}

function statsFixture(scenario: Scenario): StatsSummary {
  const busy = scenario === "one" || scenario === "many";

  return {
    calls_24h: busy
      ? { total: 42, reads: 30, writes: 12, ok: 38, error: 2, pending: 2 }
      : { total: 0, reads: 0, writes: 0, ok: 0, error: 0, pending: 0 },
    success_rate_24h: busy ? 0.95 : null,
    tools: { enabled: 12, total: 16 },
    changes_week: busy
      ? { total: 14, by_type: { post: 9, option: 5 } }
      : { total: 0, by_type: {} },
    log_reads: true,
    change_history: true,
    generated_at: iso(new Date().toISOString()),
  };
}

function auditFixture(grants: Grant[], scenario: Scenario): AuditLogEntry[] {
  const calls: [string, AuditLogEntry["result"]][] = [
    ["site_info", "ok"],
    ["elementor_save", "ok"],
    ["upload_media", "error"],
    ["run_wp_cli", "pending_approval"],
    ["query_db", "ok"],
    ["get_page_html", "ok"],
  ];
  // Twelve apps: 45 calls over two weeks, so there are pages and old entries.
  const many = scenario === "many";
  const count = many ? 45 : calls.length;

  return Array.from({ length: count }, (_, index) => {
    const [name, result] = calls[index % calls.length];
    const grant = grants[index % grants.length];
    const write = !(
      name.startsWith("get") ||
      name === "query_db" ||
      name === "site_info"
    );

    return {
      id: 100 - index,
      created_at: iso(minutes(5 + index * (many ? 460 : 40))),
      user_id: grant?.user.id ?? 1,
      user_login: grant?.user.login ?? "admin",
      request_id: `req-${index}`,
      tool: name,
      action: name === "elementor_save" ? "update" : "call",
      target: name === "elementor_save" ? "post:41" : "",
      args:
        name === "elementor_save"
          ? {
              post_id: 41,
              patches: ["update:a1b2", "insert:c3d4"],
              token: "[redacted]",
            }
          : name === "run_wp_cli"
            ? { command: "option update blogdescription 'New tagline'" }
            : {},
      result,
      message:
        result === "error"
          ? "The file is not an image or PDF."
          : name === "elementor_save"
            ? 'Saved 3 changes to "Home".'
            : "",
      grant_id: grant?.grant_id ?? "",
      client_name: grant?.client_name ?? null,
      auth: "oauth",
      kind: write ? "write" : "read",
      mcp_tool: name,
      duration_ms: 120 + ((index * 137) % 1300),
    };
  });
}

/** GET /audit-log as AuditLogQuery reads it: filters, sort, one page. */
function auditPage(rows: AuditLogEntry[], query: URLSearchParams) {
  const page = Math.max(1, Number(query.get("page") ?? 1));
  const perPage = Math.min(
    100,
    Math.max(1, Number(query.get("per_page") ?? 20)),
  );
  const from = query.get("from");
  const to = query.get("to");
  const search = (query.get("search") ?? "").toLowerCase();
  const day = (value: string, end: boolean) =>
    /^\d{4}-\d{2}-\d{2}$/.test(value)
      ? Date.parse(`${value}T${end ? "23:59:59" : "00:00:00"}Z`)
      : Date.parse(value);

  const matches = rows.filter(
    (row) =>
      (!query.get("id") || row.id === Number(query.get("id"))) &&
      (!query.get("tool") || row.tool === query.get("tool")) &&
      (!query.get("mcp_tool") || row.mcp_tool === query.get("mcp_tool")) &&
      (!query.get("result") || row.result === query.get("result")) &&
      (!query.get("grant_id") || row.grant_id === query.get("grant_id")) &&
      (!query.get("user_id") || row.user_id === Number(query.get("user_id"))) &&
      (!from || Date.parse(row.created_at) >= day(from, false)) &&
      (!to || Date.parse(row.created_at) <= day(to, true)) &&
      (!search ||
        [row.tool, row.mcp_tool, row.action, row.target, row.message].some(
          (text) => text.toLowerCase().includes(search),
        )),
  );
  const direction = query.get("order") === "asc" ? 1 : -1;
  const sorted = [...matches].sort((a, b) =>
    query.get("order_by") === "duration_ms"
      ? ((a.duration_ms ?? 0) - (b.duration_ms ?? 0)) * direction || b.id - a.id
      : (a.id - b.id) * direction,
  );

  return {
    items: sorted.slice((page - 1) * perPage, page * perPage),
    total: matches.length,
    page,
    per_page: perPage,
    total_pages: Math.ceil(matches.length / perPage),
  };
}

const CHANGE_TYPES = [
  "post",
  "post_meta",
  "term",
  "term_meta",
  "option",
  "user",
  "user_meta",
  "comment",
  "product",
  "order",
  "plugin_data",
];

function changesFixture(audit: AuditLogEntry[]): ChangeDetail[] {
  const save = audit.find((row) => row.mcp_tool === "elementor_save");
  const base = (
    id: number,
    overrides: Partial<ChangeEntry>,
  ): Omit<ChangeDetail, "before" | "after" | "diff"> => ({
    id,
    created_at: save?.created_at ?? iso(minutes(45)),
    audit_id: save?.id ?? 0,
    request_id: save?.request_id ?? "",
    user_id: save?.user_id ?? 1,
    user_login: save?.user_login ?? "admin",
    grant_id: save?.grant_id ?? "",
    client_name: save?.client_name ?? null,
    tool: "elementor",
    mcp_tool: "elementor_save",
    action: "update",
    object_type: "post",
    object_id: 41,
    object_key: "",
    object_label: 'page "Home"',
    truncated: false,
    bytes: 2048,
    ...overrides,
  });
  const content = (lines: string[]) => lines.join("\n");
  const unchangedIntro = Array.from(
    { length: 9 },
    (_, n) => `<p>Line ${n + 1}</p>`,
  );

  return [
    {
      ...base(12, {}),
      before: {
        title: "Home",
        status: "draft",
        content: content([
          "<h2>Welcome</h2>",
          ...unchangedIntro,
          "<p>Old intro.</p>",
        ]),
      },
      after: {
        title: "Home",
        status: "draft",
        content: content([
          "<h2>Welcome</h2>",
          ...unchangedIntro,
          "<p>New intro text.</p>",
          "<p>Second paragraph.</p>",
        ]),
      },
      diff: {
        fields: [
          { field: "title", status: "unchanged", value: "Home" },
          { field: "status", status: "unchanged", value: "draft" },
          {
            field: "content",
            status: "changed",
            ops: [
              { op: "equal", lines: ["<h2>Welcome</h2>", ...unchangedIntro] },
              { op: "remove", lines: ["<p>Old intro.</p>"] },
              {
                op: "add",
                lines: ["<p>New intro text.</p>", "<p>Second paragraph.</p>"],
              },
            ],
          },
        ],
        added: 2,
        removed: 1,
      },
    },
    {
      ...base(11, {
        object_type: "post_meta",
        object_key: "_elementor_page_settings",
        object_label: "_elementor_page_settings",
        action: "create",
      }),
      before: null,
      after: { value: { hide_title: "yes" } },
      diff: {
        fields: [
          {
            field: "value",
            status: "added",
            ops: [{ op: "add", lines: ["{", '    "hide_title": "yes"', "}"] }],
          },
        ],
        added: 3,
        removed: 0,
      },
    },
    {
      // Run after a site admin approved it: no AI app, the approver as user.
      ...base(10, {
        created_at: iso(minutes(60 * 30)),
        audit_id: 97,
        request_id: "req-3",
        grant_id: "",
        client_name: null,
        tool: "cli",
        mcp_tool: "run_wp_cli",
        object_type: "option",
        object_id: 0,
        object_key: "blogdescription",
        object_label: "blogdescription",
      }),
      before: { value: "Just another WordPress site" },
      after: { value: "New tagline" },
      diff: {
        fields: [
          {
            field: "value",
            status: "changed",
            ops: [
              { op: "remove", lines: ["Just another WordPress site"] },
              { op: "add", lines: ["New tagline"] },
            ],
          },
        ],
        added: 1,
        removed: 1,
      },
    },
    {
      ...base(9, {
        created_at: iso(minutes(60 * 50)),
        audit_id: 0,
        request_id: "",
        mcp_tool: "",
        tool: "proxy",
        action: "trash",
        object_id: 12,
        object_label: 'post "Old news"',
      }),
      before: { title: "Old news", status: "draft" },
      after: { title: "Old news", status: "trash" },
      diff: {
        fields: [
          { field: "title", status: "unchanged", value: "Old news" },
          {
            field: "status",
            status: "changed",
            ops: [
              { op: "remove", lines: ["draft"] },
              { op: "add", lines: ["trash"] },
            ],
          },
        ],
        added: 1,
        removed: 1,
      },
    },
  ];
}

/** changes/list as ChangesController reads it. */
function changesPage(rows: ChangeDetail[], body: Record<string, string>) {
  const page = Math.max(1, Number(body.page ?? 1));
  const perPage = Math.min(100, Math.max(1, Number(body.per_page ?? 20)));
  const search = (body.search ?? "").toLowerCase();
  const matches = rows.filter(
    (row) =>
      (!body.object_type || row.object_type === body.object_type) &&
      (!body.change_action || row.action === body.change_action) &&
      (!body.mcp_tool || row.mcp_tool === body.mcp_tool) &&
      (!body.grant_id || row.grant_id === body.grant_id) &&
      (!body.audit_id || row.audit_id === Number(body.audit_id)) &&
      (!body.from || Date.parse(row.created_at) >= Date.parse(body.from)) &&
      (!body.to || Date.parse(row.created_at) <= Date.parse(body.to)) &&
      (!search ||
        `${row.object_label} ${row.object_key}`.toLowerCase().includes(search)),
  );

  return {
    items: matches
      .slice((page - 1) * perPage, page * perPage)
      .map(({ before: _before, after: _after, diff: _diff, ...item }) => item),
    total: matches.length,
    page,
    per_page: perPage,
    total_pages: Math.ceil(matches.length / perPage),
  };
}

/** Requests that ask the admin first: one pending, one run, one rejected, one expired. */
/** MCPMate Pro's block editor queue: a batch waiting for the admin, one finalized. */
function blockBatchesFixture(scenario: Scenario): BlockBatch[] {
  if (scenario !== "one" && scenario !== "many") {
    return [];
  }

  const user = { id: 2, login: "editor", display_name: "Sam Editor" };
  const grant = { grant_id: "g_one", client_name: "Claude", status: "active" };
  const base = {
    note: "",
    trashed: false,
    created_at: "2026-11-09T08:00:00Z",
    updated_at: "2026-11-09T08:05:00Z",
    error: null,
    user,
    grant,
  };

  return [
    {
      ...base,
      id: 7,
      label: "Spring menu pages",
      note: "New tea list on the menu and the about page.",
      status: "ready",
      item_count: 2,
      requested_at: "2026-11-09T08:06:00Z",
      finalized_at: null,
      finalization_url:
        "https://example.com/wp-admin/admin.php?page=mcpmate&block_batch=7",
      finalized_by: null,
      items: [
        {
          id: 12,
          position: 0,
          post_id: 45,
          post_title: "Tea menu",
          post_status: "draft",
          mode: "blocks",
          payload_bytes: 3482,
          expected_version: "3f2a9c1d0b7e6a54",
          loss_accepted: false,
          status: "queued",
          editor: null,
          result: null,
          error: null,
        },
        {
          id: 13,
          position: 1,
          post_id: 46,
          post_title: "About",
          post_status: "publish",
          mode: "resave",
          payload_bytes: 0,
          expected_version: "3f2a9c1d0b7e6a55",
          loss_accepted: false,
          status: "queued",
          editor: null,
          result: null,
          error: null,
        },
      ],
    },
    {
      ...base,
      id: 5,
      label: "",
      status: "finalized",
      item_count: 1,
      updated_at: "2026-11-08T10:00:00Z",
      requested_at: "2026-11-08T09:55:00Z",
      finalized_at: "2026-11-08T10:00:00Z",
      finalization_url:
        "https://example.com/wp-admin/admin.php?page=mcpmate&block_batch=5",
      finalized_by: { id: 1, login: "admin", display_name: "Alex Admin" },
      items: [
        {
          id: 9,
          position: 0,
          post_id: 40,
          post_title: "Contact",
          post_status: "draft",
          mode: "content",
          payload_bytes: 900,
          expected_version: "1f2a9c1d0b7e6a54",
          loss_accepted: false,
          status: "saved",
          editor: { recovered: 1, js_only_blocks: [] },
          result: {
            id: 40,
            target_id: 40,
            copied: false,
            changed: true,
            status: "draft",
            version: "9b1e0c7d2a3f4e58",
            warning_count: 0,
            loss_accepted: false,
            edit_url:
              "https://example.com/wp-admin/post.php?post=40&action=edit",
            preview_url: "",
          },
          error: null,
        },
      ],
    },
  ];
}

/** MCPMate Pro's design system: an active design and a draft. */
export const FAKE_DESIGN_CONTENT = `---
name: Tidewater
description: Calm and trustworthy.
tokens:
  colors:
    primary: "#0e5a63"
    sand: "#f6f1e8"
  fonts:
    heading: "Lora, Georgia, serif"
  spacing:
    md: 20px
  radii:
    md: 12px
---
# Tidewater
`;

const FAKE_DESIGN_TOKENS = {
  colors: { primary: "#0e5a63", sand: "#f6f1e8" },
  fonts: { heading: "Lora, Georgia, serif" },
  spacing: { md: "20px" },
  radii: { md: "12px" },
};

/** The playbooks MCPMate and MCPMate Pro ship (skills/, pro/skills/), for the "many" scenario. */
const ALL_PLAYBOOKS: SystemSkill[] = [
  {
    name: "beaver-builder-build-page",
    description:
      "Build or change a Beaver Builder page through its own flat node layout, as a draft, using the site's global colours and typography; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Beaver Builder",
  },
  {
    name: "breakdance-build-page",
    description:
      "Build or change a Breakdance page through its own element tree, as a draft, using the site's global classes, variables and global settings; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool", "check_approval"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Breakdance",
  },
  {
    name: "bricks-build-page",
    description:
      "Build or change a Bricks page as a draft from its own element list, using the site's palette, variables and global classes, with a dry run first. Covers Bricksforge's elements too.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Bricks",
  },
  {
    name: "content-model-migration",
    description:
      "Move custom field data from one field plugin to another (ACF, Meta Box, Pods, JetEngine, ACPT, ASE): inventory, an approved mapping table, a dry run of every write, a pilot, batches, a check.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Moving custom fields between plugins",
  },
  {
    name: "content-model-schema",
    description:
      "Understand and change a site's content model (custom post types, taxonomies, field groups, options pages, relationships) and its field values, through ACF, Meta Box, Pods, JetEngine, ACPT or ASE.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Content model and custom fields",
  },
  {
    name: "design-system",
    description:
      "Build pages that follow the site's design system (a saved design's tokens, or a page builder's globals), check them against it, and write a new design when the user wants one.",
    tools: ["get_active_design", "check_design"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Design system",
  },
  {
    name: "divi-build-page",
    description:
      "Build or change a Divi 5 page through its own module tree, as a draft, using the site's global colours, fonts, variables and presets; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Divi 5",
  },
  {
    name: "elementor-convert-to-v4",
    description:
      "Convert an Elementor page from v3 widgets to Elementor 4's atomic elements on a draft copy, moving its styling into variables, global classes and local styles, each step dry-run first.",
    tools: [
      "elementor_get",
      "elementor_widgets",
      "elementor_save",
      "list_integration_tools",
      "run_integration_tool",
    ],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Convert an Elementor page to Elementor 4",
  },
  {
    name: "elementor-pages",
    description:
      "Build or change a page made with Elementor, saved as a draft through Elementor itself: widgets the site has, a checked tree, a dry run, then the user reviews.",
    tools: ["elementor_get", "elementor_widgets", "elementor_save"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Elementor pages",
  },
  {
    name: "etch-build-page",
    description:
      "Build or change a page with Etch, the HTML-first builder, as a draft: real elements and text blocks, classes styled by Etch style records and CSS variables; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Etch",
  },
  {
    name: "form-model-migration",
    description:
      "Move a form between form plugins (CF7, WPForms, Gravity Forms, Fluent Forms, Formidable, Ninja Forms): read it, approve a mapping, create an inactive copy, check, then switch.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Moving a form between plugins",
  },
  {
    name: "form-model-schema",
    description:
      "Read, build and change forms (fields, notifications, confirmations) in CF7, WPForms, Gravity Forms, Fluent Forms, Formidable or Ninja Forms in one shape, and read entries safely.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Forms in one shape",
  },
  {
    name: "gutenberg-pages",
    description:
      "Write block editor (Gutenberg) page content: blocks the site has, markup that survives the editor, checked with validate_blocks before anything is saved.",
    tools: ["list_block_types", "validate_blocks"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Gutenberg pages",
  },
  {
    name: "kirki-build-page",
    description:
      "Build or change a page with the Kirki site builder through its own node map, as a draft, styled with the site's variables and global classes; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Kirki",
  },
  {
    name: "mosaic-build-page",
    description:
      "Build or change a Mosaic page through its element tree, in a template not live yet, styled with the theme's Classes, Variants and variables; dry run first, and leave going live to a site admin.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Mosaic",
  },
  {
    name: "oxygen-build-page",
    description:
      "Build or change an Oxygen 6 page through its own element tree, as a draft, using the site's global classes and variables; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool", "check_approval"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with Oxygen",
  },
  {
    name: "php-compatibility-check",
    description:
      "Before or after a PHP upgrade, check that WordPress, the theme and the plugins fit the site's PHP version, and report what to update or test first; changes nothing.",
    tools: ["site_info", "run_wp_cli"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# PHP compatibility check",
  },
  {
    name: "safe-bulk-changes",
    description:
      "Change many posts, options or database values at once (search-replace, SEO fields, settings) with a backup, a dry run, a one-item pilot, batches that stop on surprises, and a report.",
    tools: ["run_wp_cli"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Safe bulk changes",
  },
  {
    name: "seo-audit",
    description:
      "Read-only SEO audit of a site's public pages (indexing, titles, descriptions, headings, links, images, structured data), reported as prioritised fixes; nothing changes until the user picks fixes.",
    tools: ["site_info", "get_page_html", "run_wp_cli"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# SEO audit",
  },
  {
    name: "seo-plugins",
    description:
      "A post's SEO (title, description, focus keyword, robots, canonical, schema type), term SEO, SEO settings and redirects through Yoast SEO, Rank Math, All in One SEO or SEOPress.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# SEO plugins",
  },
  {
    name: "site-integrity-check",
    description:
      "Check a site for signs of tampering or damage (changed core files, unknown administrators, odd scheduled tasks and options) and report what to look at; changes nothing.",
    tools: ["site_info", "run_wp_cli", "query_db"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Site integrity check",
  },
  {
    name: "skill-creator",
    description:
      "Turn something the user wants AI apps to remember (a house style, a layout, a way of working) into a short, clear skill saved with save_skill, and keep it up to date.",
    tools: ["list_skills", "save_skill"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Skill creator",
  },
  {
    name: "theme-building",
    description:
      "Build or restyle a classic PHP theme with Tailwind CSS in a draft copy of the active theme, preview it, and publish only when the user says so (approved by a site admin).",
    tools: [
      "create_draft_theme",
      "read_theme_file",
      "write_theme_file",
      "get_preview_url",
    ],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Theme building (classic PHP + Tailwind, draft first)",
  },
  {
    name: "woocommerce-catalog",
    description:
      "Build and maintain a WooCommerce catalog (products, variations, prices, stock) through WooCommerce's own code, as drafts, with approval for anything live; read settings and orders safely.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# WooCommerce catalog",
  },
  {
    name: "wpbakery-build-page",
    description:
      "Build or change a WPBakery Page Builder page through its own shortcode tree, as a draft, with the elements and params this site's WPBakery maps; check every change with a dry run first.",
    tools: ["list_integration_tools", "run_integration_tool"],
    updated_at: "2026-11-01T09:00:00Z",
    body: "# Build a page with WPBakery",
  },
];

/** Tools → Skills in the "many" scenario (page tests pass their own). */
function skillsFixture(scenario: Scenario): SavedSkill[] {
  if (scenario !== "many") {
    return [];
  }

  const skill = (
    id: number,
    name: string,
    description: string,
    extra: Partial<SavedSkill> = {},
  ): SavedSkill => ({
    id,
    user: { id: 1, login: "admin", name: "Site Admin" },
    name,
    description,
    this_site_only: false,
    bytes: 640,
    updated_at: `2026-11-${String(10 - id).padStart(2, "0")}T09:30:00Z`,
    trashed_at: null,
    body: `# ${name}\n\n${description}`,
    ...extra,
  });

  return [
    skill(
      1,
      "brand-style",
      "Navy headings, sand backgrounds, no stock photos of people.",
      {
        bytes: 1840,
      },
    ),
    skill(
      2,
      "shop-layout",
      "Product pages: gallery left, price and cart right, reviews last.",
      {
        this_site_only: true,
        bytes: 2210,
      },
    ),
    skill(
      3,
      "blog-voice",
      "Short paragraphs, second person, one link per section.",
      {
        user: { id: 2, login: "editor", name: "Rita Editor" },
        bytes: 980,
      },
    ),
    skill(4, "old-hero", "The hero banner before the autumn refresh.", {
      trashed_at: "2026-11-08T12:00:00Z",
    }),
  ];
}

function designsFixture(scenario: Scenario): Design[] {
  if (scenario !== "one" && scenario !== "many") {
    return [];
  }

  const base = {
    description: "Calm and trustworthy.",
    revision: null,
    content: FAKE_DESIGN_CONTENT,
    tokens: FAKE_DESIGN_TOKENS,
    warnings: [],
    colors: FAKE_DESIGN_TOKENS.colors,
  };

  return [
    {
      ...base,
      id: 12,
      name: "Tidewater",
      status: "active",
      active: true,
      updated_at: "2026-11-09T08:00:00Z",
      revisions: [
        {
          id: 40,
          at: "2026-11-08T10:00:00Z",
          by: { id: 1, name: "Alex Admin" },
        },
      ],
    },
    {
      ...base,
      id: 15,
      name: "Broadsheet",
      description: "Editorial and confident.",
      status: "draft",
      active: false,
      updated_at: "2026-11-08T09:00:00Z",
      revisions: [],
    },
  ];
}

const FAKE_STARTERS: DesignStarter[] = [
  {
    slug: "tidewater",
    name: "Tidewater",
    description: "Calm and trustworthy.",
    colors: FAKE_DESIGN_TOKENS.colors,
  },
  {
    slug: "nocturne",
    name: "Nocturne",
    description: "Dark and atmospheric.",
    colors: { night: "#0f1115", gold: "#e2b85c" },
  },
];

/** MCPMate Pro: Tools → Sandbox. One plugin running, one stopped after a fatal error. */
function sandboxFixture(scenario: Scenario): SandboxPlugin[] {
  if (scenario !== "one" && scenario !== "many") {
    return [];
  }

  return [
    {
      slug: "booking-tweaks",
      name: "Booking tweaks",
      description: "Small fixes for the booking form.",
      status: "enabled",
      main: "booking-tweaks.php",
      created_at: "2026-11-08T09:00:00Z",
      enabled_at: "2026-11-08T10:00:00Z",
      disabled_at: null,
      crash: null,
    },
    {
      slug: "seo-meta",
      name: "SEO meta",
      description: "",
      status: "crashed",
      main: "seo-meta.php",
      created_at: "2026-11-09T09:00:00Z",
      enabled_at: "2026-11-09T10:00:00Z",
      disabled_at: null,
      crash: {
        message: "Uncaught Error: Call to undefined function seo_meta_x()",
        file: "seo-meta/seo-meta.php",
        line: 12,
        at: "2026-11-09T10:05:00Z",
      },
    },
  ];
}

const FAKE_SANDBOX_CODE =
  "<?php\n/** Plugin Name: Booking tweaks */\ndefined('ABSPATH') || exit;\n\nadd_filter('the_title', 'trim');\n";

function approvalsFixture(scenario: Scenario): Approval[] {
  if (scenario !== "one" && scenario !== "many") {
    return [];
  }
  const admin = { id: 1, login: "admin", display_name: "Niloy" };
  const base = {
    tool: "cli",
    action: "search-replace",
    target: "db:search-replace",
    request_id: "req_4f1c9a2e",
    created_by: admin,
    decided_at: null,
    decided_by: null,
    executed_at: null,
    result: null,
  };
  const searchReplace = {
    command: "search-replace 'http://old.example' 'https://example.com'",
  };

  return [
    {
      ...base,
      id: 12,
      status: "pending",
      args: searchReplace,
      preview: { tables: 3, rows: 214, dry_run: true },
      created_at: iso(minutes(3)),
      expires_at: iso(minutes(-12)),
    },
    {
      ...base,
      id: 11,
      status: "executed",
      action: "plugin.deactivate",
      target: "plugin:akismet",
      args: { command: "plugin deactivate akismet" },
      preview: { plugins: ["akismet"] },
      created_at: iso(minutes(60 * 26)),
      expires_at: iso(minutes(60 * 26 - 15)),
      decided_at: iso(minutes(60 * 26 - 2)),
      decided_by: admin,
      executed_at: iso(minutes(60 * 26 - 2)),
      result: { ok: true, data: { deactivated: ["akismet"] }, error: null },
    },
    {
      ...base,
      id: 10,
      status: "rejected",
      tool: "abilities",
      action: "shop:delete-drafts",
      target: "ability:shop/delete-drafts",
      args: { name: "shop/delete-drafts", input: {} },
      preview: { ability: "shop/delete-drafts" },
      created_at: iso(minutes(60 * 30)),
      expires_at: iso(minutes(60 * 30 - 15)),
      decided_at: iso(minutes(60 * 30 - 1)),
      decided_by: admin,
    },
    {
      ...base,
      id: 9,
      status: "expired",
      args: searchReplace,
      preview: { tables: 3, rows: 214, dry_run: true },
      created_at: iso(minutes(60 * 50)),
      expires_at: iso(minutes(60 * 50 - 15)),
    },
  ];
}

const DEFAULT_PREFERENCES: Preferences = {
  log_reads: true,
  audit_retention_days: 90,
  read_retention_days: 30,
  change_history: true,
  change_history_types: CHANGE_TYPES,
  change_retention_days: 30,
  approve_capability: "manage_options",
  access_token_minutes: 15,
  refresh_token_days: 30,
  open_registration: true,
  rate_limit: 120,
  delete_data_on_uninstall: true,
};

function checksFixture(): ChecksResponse {
  const check = (
    id: ChecksResponse["checks"][number]["id"],
    title: string,
    status: ChecksResponse["checks"][number]["status"],
    message: string,
    fix = "",
  ) => ({ id, title, status, message, fix, details: [] });

  return {
    checks: [
      check("https", "Your site uses HTTPS", "pass", "Your site uses HTTPS."),
      check(
        "permalinks",
        "Your site has readable addresses (permalinks)",
        "pass",
        "AI apps can find the addresses they sign in with.",
      ),
      check(
        "mcp_endpoint",
        "AI apps can reach your MCP URL",
        "warn",
        "We couldn't test this from your server itself.",
      ),
      {
        ...check(
          "auth_header",
          "Sign-in details reach WordPress",
          "fail",
          "Your web server removes the Authorization header before WordPress sees it.",
          "Add these lines to the top of the .htaccess file in your WordPress folder:",
        ),
        details: [
          {
            text: "",
            code: "RewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]",
          },
        ],
      },
      check(
        "well_known",
        "AI apps can find how to sign in",
        "pass",
        "Every address AI apps use to find how to sign in answers correctly.",
      ),
      {
        ...check(
          "caching",
          "Caches don't store sign-in answers",
          "pass",
          "No cache answered for WordPress.",
        ),
        details: [
          {
            text: "WP Rocket is active. Keep these addresses on its list of pages never to cache:",
            code: "/wp-json/mcpmate/mcp\n/mcpmate/",
          },
        ],
      },
      check(
        "execution_time",
        "Requests have enough time to finish",
        "pass",
        "PHP has no time limit on this site.",
      ),
      check(
        "versions",
        "WordPress and PHP are up to date enough",
        "pass",
        "Yes.",
      ),
      check("admin", "You're an administrator", "pass", "You are."),
      check("environment", "Where this site runs", "info", "A local site."),
    ],
    summary: { pass: 7, warn: 1, fail: 1 },
  };
}

export interface FakeResponse {
  status: number;
  body: unknown;
  /** Never answers (a loading state). */
  hang?: boolean;
  /** A text/event-stream body instead of JSON (Chat's streamed steps). */
  sse?: string;
}

export function createFakeSite(options: FakeSiteOptions = {}) {
  const scenario = options.scenario ?? "one";
  const fail = new Set(
    scenario === "errors"
      ? [
          "grants/list",
          "clients/list",
          "stats",
          "audit-log",
          "connection/status",
          "tools",
          "changes/list",
          "preferences",
          "approvals",
          "approvals/show",
        ]
      : (options.fail ?? []),
  );
  const hang = new Set(options.hang ?? []);
  let grants: Grant[] =
    scenario === "many"
      ? manyGrants()
      : scenario === "one" || scenario === "paused"
        ? [makeGrant(0)]
        : [];
  let access: AccessState =
    scenario === "paused"
      ? { paused: true, at: iso(minutes(20)), by: { id: 1, name: "Niloy" } }
      : { paused: false, at: null, by: null };
  let chat: ChatSettingsView | undefined = options.chat
    ? {
        enabled: true,
        has_key: true,
        ready: true,
        model: "claude-opus-5-5",
        models: [
          { id: "claude-opus-5-5", label: "Claude Opus 5.5" },
          { id: "claude-sonnet-5-5", label: "Claude Sonnet 5.5" },
        ],
        updated_at: null,
        consent: true,
        connected: true,
        ...options.chat,
      }
    : undefined;
  // Conversations by ID; a new chat gets the next one.
  const chatSessions = new Map<number, ChatSession>();
  let nextChatId = 1;
  const sse = (events: [string, unknown][]) =>
    events
      .map(
        ([event, data]) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`,
      )
      .join("");
  const chatSummary = () =>
    [...chatSessions.values()]
      .sort((a, b) => b.id - a.id)
      .map(({ id, title, model, status, created_at, updated_at }) => ({
        id,
        title,
        model,
        status,
        created_at,
        updated_at,
      }));
  let readMode: ReadModeState = options.readMode
    ? { on: true, at: iso(minutes(5)), by: { id: 1, name: "Niloy" } }
    : { on: false, at: null, by: null };
  const connection = connectionFor();
  let tools = toolsFixture();
  let audit =
    scenario === "one" || scenario === "many"
      ? auditFixture(grants, scenario)
      : [];
  const changes =
    scenario === "one" || scenario === "many" ? changesFixture(audit) : [];
  let clients = clientsFixture(grants);
  let approvals = approvalsFixture(scenario);
  let blockBatches = blockBatchesFixture(scenario);
  // Entries older than the retention window, for Remove old entries now.
  let expired = scenario === "many" ? 1240 : 0;
  let preferences: Preferences = {
    ...DEFAULT_PREFERENCES,
    ...options.preferences,
  };
  const filtered = options.filtered ?? {};
  // MCPMate Pro: Settings → AI instructions, capped as the server caps them.
  let siteInstructions: SiteInstructions = {
    enabled: false,
    content: "",
    max_bytes: 8192,
    updated_at: null,
    updated_by: null,
    ...options.siteInstructions,
  };
  const instructionsBlock = (enabled: boolean, content: string) =>
    enabled && content.trim() !== ""
      ? `The text between the <site-owner-instructions> tags was written by this site's administrator for AI apps.\n<site-owner-instructions>\n${content.trim()}\n</site-owner-instructions>`
      : "";
  const serverInstructions =
    "Manage one WordPress site that runs the MCPMate plugin. Calls are limited per minute for each app.";
  // Tools → Skills: saved skills (deleted for good only from the trash) and
  // two playbooks, or every shipped one in the "many" scenario.
  let skills: SavedSkill[] = options.skills ?? skillsFixture(scenario);
  const playbooks: SystemSkill[] =
    scenario === "many"
      ? ALL_PLAYBOOKS
      : [
          {
            name: "seo-audit",
            description: "Read-only SEO audit of a site's public pages.",
            tools: ["site_info", "get_page_html", "run_wp_cli"],
            updated_at: "2026-11-01T09:00:00Z",
            body: "# SEO audit\n\nUse this when the user asks for an SEO review.",
          },
          {
            name: "skill-creator",
            description: "Turn a convention into a skill.",
            tools: ["list_skills", "save_skill"],
            updated_at: "2026-11-01T09:00:00Z",
            body: "# Skill creator",
          },
        ];
  const skillError = (code: string, message: string, errors?: object) => ({
    status: 200,
    body: { success: false, data: { code, message, errors } },
  });
  // MCPMate Pro: Tools → Memory. Trashed ones are removed only by memories/purge.
  let memories: Memory[] = options.memories ?? [];
  let designs: Design[] = designsFixture(scenario);
  let sandbox: SandboxPlugin[] = sandboxFixture(scenario);
  const sandboxPlugin = (slug: unknown) =>
    sandbox.find((plugin) => plugin.slug === String(slug));
  const designView = (design: Design): Design => ({
    ...design,
    active: designs.find((d) => d.active)?.id === design.id,
  });
  const designSummary = (design: Design) => ({
    id: design.id,
    name: design.name,
    description: design.description,
    status: design.status,
    active: design.active,
    updated_at: design.updated_at,
    colors: design.colors,
  });
  const designError = (
    code: string,
    message: string,
    errors?: string[],
  ): FakeResponse => ({
    status: 200,
    body: {
      success: false,
      data: { code, message, ...(errors ? { errors } : {}) },
    },
  });
  /** The fake's design parser: a list line is the one problem it knows. */
  const fakeParse = (content: string) =>
    /\n\s+- /.test(content)
      ? {
          tokens: {},
          errors: ["Line 4: Lists are not supported."],
          warnings: [],
        }
      : {
          tokens: content.includes("tokens:") ? FAKE_DESIGN_TOKENS : {},
          errors: [],
          warnings: content.includes("tokens:") ? [] : ["No front matter."],
        };
  const memoryView = (memory: Memory) => {
    const { content, ...rest } = memory;
    return {
      ...rest,
      excerpt: (content ?? "").replace(/\s+/g, " ").slice(0, 200),
    };
  };
  const memoryLimits = {
    title_chars: 200,
    content_bytes: 16384,
    tags: 10,
    tag_chars: 40,
  };
  // MCPMate Pro: "VALID-PRO-KEY" activates, any other key is refused.
  let license: LicenseStatus = {
    has_key: false,
    license_key: "",
    valid: false,
    update_entitled: false,
    update_expires_at: null,
    reason: null,
    last_verified_at: null,
    domain: "localhost",
  };

  const ok = (data: unknown): FakeResponse => ({
    status: 200,
    body: { success: true, data },
  });
  const ajaxError = (message: string): FakeResponse => ({
    status: 200,
    body: { success: false, data: { code: "mcpmate_test", message } },
  });
  const rest = (data: unknown): FakeResponse => ({
    status: 200,
    body: { ok: true, data, error: null, request_id: "test", truncated: false },
  });

  const preferencesView = () => ({
    preferences,
    fixed: {
      approve_capability: { reason: "locked", value: "manage_options" },
      ...Object.fromEntries(
        Object.entries(filtered).map(([key, value]) => [
          key,
          { reason: "filtered", value },
        ]),
      ),
    },
    change_types: CHANGE_TYPES,
  });

  const replace = (updated: Grant) => {
    grants = grants.map((item) =>
      item.grant_id === updated.grant_id ? updated : item,
    );
  };

  function ajax(route: string, body: Record<string, string>): FakeResponse {
    switch (route) {
      case "grants/list":
        return ok({ grants, limit: 25 });
      case "grants/revoke": {
        const found = grants.find((item) => item.grant_id === body.grant_id);
        if (!found) {
          return ajaxError("That AI app was not found.");
        }
        const updated: Grant = {
          ...found,
          status: "revoked",
          revoked_at: iso(new Date().toISOString()),
          revoke_reason: "admin",
        };
        replace(updated);
        return ok({ grant: updated });
      }
      case "grants/revoke-all": {
        const live = grants.filter((item) => item.status !== "revoked");
        live.forEach((item) =>
          replace({
            ...item,
            status: "revoked",
            revoked_at: iso(new Date().toISOString()),
            revoke_reason: "admin",
          }),
        );
        return ok({ revoked: live.length });
      }
      case "clients/list":
        return ok({
          clients,
          dcr_count: clients.filter((client) => client.kind === "dcr").length,
          dcr_limit: 200,
        } satisfies ClientsResponse);
      case "clients/revoke":
      case "clients/restore": {
        const found = clients.find(
          (client) => client.client_key === body.client_key,
        );
        if (!found) {
          return ajaxError("That AI app was not found.");
        }
        const revoking = route === "clients/revoke";
        const signedOut = revoking
          ? grants.filter(
              (grant) =>
                grant.status !== "revoked" &&
                grant.client_id === found.client_id,
            )
          : [];
        signedOut.forEach((grant) =>
          replace({
            ...grant,
            status: "revoked",
            revoked_at: iso(new Date().toISOString()),
            revoke_reason: "client_revoked",
          }),
        );
        const updated: RegisteredClient = revoking
          ? {
              ...found,
              status: "revoked",
              live_grants: 0,
              revoked_at: iso(new Date().toISOString()),
            }
          : { ...found, status: "not_connected", revoked_at: null };
        clients = clients.map((client) =>
          client.client_key === found.client_key ? updated : client,
        );
        return ok(
          revoking
            ? { client: updated, revoked_grants: signedOut.length }
            : { client: updated },
        );
      }
      case "grants/update": {
        const found = grants.find((item) => item.grant_id === body.grant_id);
        if (!found) {
          return ajaxError("That AI app was not found.");
        }
        const level = body.access as Grant["access"];
        const list =
          level === "custom" ? (JSON.parse(body.tools) as string[]) : null;
        const updated: Grant = {
          ...found,
          access: level,
          tools: list,
          scopes:
            level === "read_only" ? ["site:read"] : ["site:read", "site:write"],
        };
        replace(updated);
        return ok({ grant: updated, message: "Access saved." });
      }
      case "access":
        return ok({ access });
      case "access/pause":
        access = {
          paused: true,
          at: iso(new Date().toISOString()),
          by: { id: 1, name: "Niloy" },
        };
        return ok({ access });
      case "access/resume":
        access = { paused: false, at: null, by: null };
        return ok({ access });
      case "chat/settings":
        return ok(chat);
      case "chat/settings/update": {
        if (!chat) {
          return ajaxError("No Chat.");
        }
        if (body.api_key && !String(body.api_key).startsWith("sk-ant-")) {
          return ajaxError(
            "That does not look like an Anthropic API key (it starts with sk-ant-).",
          );
        }
        const hasKey =
          body.api_key === undefined ? chat.has_key : body.api_key !== "";
        const enabled =
          body.enabled === undefined
            ? chat.enabled && hasKey
            : body.enabled === "true";
        chat = {
          ...chat,
          has_key: hasKey,
          enabled,
          ready: enabled && hasKey,
          model: body.model ?? chat.model,
        };
        return ok({ message: "Chat settings saved.", ...chat });
      }
      case "chat/consent":
        chat = chat && { ...chat, consent: true, connected: true };
        return ok(chat);
      case "chat/sessions":
        return ok({ sessions: chatSummary() });
      case "chat/session": {
        const found = chatSessions.get(Number(body.id));
        return found
          ? ok({ session: found })
          : ajaxError("That conversation was not found.");
      }
      case "chat/delete":
        chatSessions.delete(Number(body.id));
        return ok({ sessions: chatSummary() });
      case "chat/send": {
        const text = String(body.text ?? "");
        // "Refuse …" is refused before the message is saved; "Drop …" after.
        if (/^(refuse|drop)/i.test(text)) {
          return {
            status: 200,
            body: null,
            sse: sse([
              [
                "error",
                {
                  code: "rate_limited",
                  message: "Too many tool calls.",
                  kept: /^drop/i.test(text),
                },
              ],
            ]),
          };
        }
        const wantsChange = /change|update|add/i.test(text);
        const now = iso(new Date().toISOString());
        const existing = chatSessions.get(Number(body.session_id ?? 0));
        const id = existing?.id ?? nextChatId++;
        const items: ChatSession["items"] = [
          ...(existing?.items ?? []),
          { kind: "user", text, images: [] },
          {
            kind: "assistant",
            text: wantsChange
              ? "I'll update the draft."
              : "Your site runs WordPress 6.9.",
          },
          ...(wantsChange
            ? [
                {
                  kind: "tool" as const,
                  id: `toolu_${id}`,
                  name: "update_post",
                  input: { id: 7, title: "About us" },
                  status: "pending" as const,
                  summary: "",
                  approval_id: null,
                  approval_url: null,
                },
              ]
            : []),
        ];
        const saved: ChatSession = {
          id,
          title: existing?.title ?? text.slice(0, 60),
          model: "claude-opus-5-5",
          status: wantsChange ? "tools" : "idle",
          created_at: existing?.created_at ?? now,
          updated_at: now,
          state: wantsChange ? "plan" : null,
          items,
          plan: wantsChange
            ? [
                {
                  id: `toolu_${id}`,
                  name: "update_post",
                  kind: "write",
                  invalid: false,
                },
              ]
            : null,
        };
        chatSessions.set(id, saved);
        return {
          status: 200,
          body: null,
          sse: sse([
            ...(existing ? [] : ([["session", { id }]] as [string, unknown][])),
            ["text", { delta: wantsChange ? "I'll update " : "Your site " }],
            [
              "text",
              { delta: wantsChange ? "the draft." : "runs WordPress 6.9." },
            ],
            ["done", { session: saved }],
          ]),
        };
      }
      case "chat/run": {
        const found = chatSessions.get(Number(body.session_id));
        if (!found) {
          return ajaxError("That conversation was not found.");
        }
        const approve = body.approve === "true";
        const saved: ChatSession = {
          ...found,
          status: "idle",
          state: null,
          plan: null,
          items: [
            ...found.items.map((item) =>
              item.kind === "tool" && item.status === "pending"
                ? {
                    ...item,
                    status: approve ? ("ok" as const) : ("declined" as const),
                    summary: approve
                      ? "Draft 7 updated."
                      : "Not run: you skipped it.",
                  }
                : item,
            ),
            {
              kind: "assistant",
              text: approve
                ? "Done: the draft has its new title."
                : "OK, I left it as it was.",
            },
          ],
        };
        chatSessions.set(found.id, saved);
        return {
          status: 200,
          body: null,
          sse: sse([
            [
              "tool_result",
              {
                id: `toolu_${found.id}`,
                name: "update_post",
                status: approve ? "ok" : "declined",
                summary: "",
              },
            ],
            ["done", { session: saved }],
          ]),
        };
      }
      case "read-mode":
        return ok({ read_mode: readMode });
      case "read-mode/on":
        readMode = {
          on: true,
          at: iso(new Date().toISOString()),
          by: { id: 1, name: "Niloy" },
        };
        return ok({ read_mode: readMode });
      case "read-mode/off":
        readMode = { on: false, at: null, by: null };
        return ok({ read_mode: readMode });
      case "uninstall/summary":
        return ok({
          delete_data: preferences.delete_data_on_uninstall,
          multisite: false,
          tables: [
            { name: "wp_mcpmate_audit_log", rows: audit.length },
            { name: "wp_mcpmate_changes", rows: changes.length },
            { name: "wp_mcpmate_grants", rows: grants.length },
          ],
          options: ["MCPMATE_preferences", "MCPMATE_tools"],
          keeps: ["MCPMATE_theme_draft_records"],
          sandbox: { plugins: 1, files: 3, more: false },
          theme_backups: { count: 2, bytes: 4_500_000 },
          theme_drafts: 1,
          designs: 0,
        });
      case "uninstall/export": {
        const rows = (body.kind === "changes" ? changes : audit) as {
          id: number;
        }[];
        const perPage = body.kind === "changes" ? 10 : 100;
        const maxId = Math.max(0, ...rows.map((row) => row.id));
        const until = Number(body.until ?? 0) || maxId;
        const after = Number(body.until ?? 0) ? Number(body.after ?? 0) : 0;
        const inExport = rows
          .filter((row) => row.id <= until)
          .sort((a, b) => a.id - b.id);
        const items = inExport
          .filter((row) => row.id > after)
          .slice(0, perPage);
        const nextAfter = items.length ? items[items.length - 1].id : after;
        return ok({
          kind: body.kind,
          per_page: perPage,
          until,
          total: inExport.length,
          next_after: nextAfter,
          done: items.length < perPage || nextAfter >= until,
          items,
        });
      }
      case "connection/status":
        return ok({ connection });
      case "onboarding/state":
        return ok({
          onboarding: {
            v: 1,
            status: "in_progress",
            step: "welcome",
            source: "activation",
            started_at: utc(5),
            updated_at: utc(5),
            completed_at: null,
            features_defaulted: false,
            connect_since: null,
            grant_id: "",
          },
          connection,
          grant: null,
          first_call: null,
          features: {
            abilities_supported: true,
            abilities_available: 3,
            elementor_available: false,
            can_edit_themes: true,
            pro_features: true,
          },
        });
      case "onboarding/checks":
        return ok(checksFixture());
      case "onboarding/update":
        return ok({ onboarding: {} });
      case "audit-log/purge-preview":
        return ok({
          count: expired,
          days: preferences.audit_retention_days,
          older_than: iso(minutes(60 * 24 * preferences.audit_retention_days)),
        });
      case "audit-log/purge": {
        const removed = expired;
        expired = 0;
        return ok({
          removed,
          days: preferences.audit_retention_days,
          older_than: iso(minutes(60 * 24 * preferences.audit_retention_days)),
        });
      }
      case "changes/list":
        return ok(changesPage(changes, body));
      case "changes/show": {
        const found = changes.find((row) => row.id === Number(body.id));
        return found
          ? ok({ change: found })
          : ajaxError("That change was not found.");
      }
      case "approvals": {
        const rows = approvals
          .filter((row) => !body.status || row.status === body.status)
          .map(({ preview: _preview, result: _result, ...row }) => row);
        return ok({ items: rows, total: rows.length, page: 1, per_page: 20 });
      }
      case "approvals/show": {
        const found = approvals.find((row) => row.id === Number(body.id));
        return found
          ? ok({ approval: found })
          : ajaxError("That approval request was not found.");
      }
      case "approvals/approve":
      case "approvals/reject": {
        const found = approvals.find((row) => row.id === Number(body.id));
        if (!found || found.status !== "pending") {
          return ajaxError("This request was already decided or has expired.");
        }
        const now = iso(new Date().toISOString());
        const approved = route === "approvals/approve";
        const updated: Approval = {
          ...found,
          status: approved ? "executed" : "rejected",
          decided_at: now,
          decided_by: { id: 1, login: "admin", display_name: "Niloy" },
          executed_at: approved ? now : null,
          result: !approved
            ? null
            : options.approvalFails
              ? {
                  ok: false,
                  data: null,
                  error: {
                    code: "mcpmate_cli_command_disabled",
                    message: '"search-replace" is not enabled on this site.',
                    hint: "",
                  },
                }
              : { ok: true, data: { replaced: 214 }, error: null },
        };
        approvals = approvals.map((row) =>
          row.id === updated.id ? updated : row,
        );
        return ok({ approval: updated });
      }
      case "preferences":
        return ok(preferencesView());
      case "preferences/update": {
        const requested = JSON.parse(
          body.changes ?? "{}",
        ) as Partial<Preferences>;
        const refused = options.settingsErrors ?? {};
        if (Object.keys(refused).length > 0) {
          return {
            status: 200,
            body: {
              success: false,
              data: {
                code: "mcpmate_invalid_setting",
                message: "Some settings are invalid.",
                errors: refused,
              },
            },
          };
        }
        preferences = { ...preferences, ...requested };
        return ok({ message: "Settings saved.", ...preferencesView() });
      }
      case "skills/list": {
        const trash = body.status === "trash";
        return ok({
          skills: skills
            .filter((skill) => Boolean(skill.trashed_at) === trash)
            // Newest first, as SkillStore::all() orders them.
            .sort((a, b) =>
              ((trash ? b.trashed_at : null) ?? b.updated_at).localeCompare(
                (trash ? a.trashed_at : null) ?? a.updated_at,
              ),
            )
            .map(({ body: _body, ...skill }) => skill),
          counts: {
            live: skills.filter((skill) => !skill.trashed_at).length,
            trash: skills.filter((skill) => skill.trashed_at).length,
          },
          system: playbooks.map(({ body: _body, ...skill }) => skill),
          trash_days: 30,
          limits: { description_chars: 200, body_bytes: 16000 },
        });
      }
      case "skills/show": {
        if (body.name) {
          const playbook = playbooks.find((skill) => skill.name === body.name);
          return playbook
            ? ok({ skill: { ...playbook, owner: "system" } })
            : ajaxError("Skill not found.");
        }
        const found = skills.find((skill) => skill.id === Number(body.id));
        return found
          ? ok({ skill: { ...found, owner: "user" } })
          : ajaxError("Skill not found.");
      }
      case "skills/update": {
        const found = skills.find((skill) => skill.id === Number(body.id));
        if (!found) {
          return ajaxError("Skill not found.");
        }
        if ((body.description ?? "").includes("\n")) {
          const message =
            "Give a description of one line, up to 200 characters.";
          return skillError("mcpmate_invalid_skill", message, {
            description: message,
          });
        }
        const updated: SavedSkill = {
          ...found,
          description: (body.description ?? "").trim(),
          body: body.body ?? "",
          bytes: new TextEncoder().encode(body.body ?? "").length,
          updated_at: iso(new Date().toISOString()),
        };
        skills = skills.map((skill) =>
          skill.id === updated.id ? updated : skill,
        );
        return ok({ message: "Skill saved.", skill: updated });
      }
      case "skills/trash":
      case "skills/restore": {
        const found = skills.find((skill) => skill.id === Number(body.id));
        const toTrash = route === "skills/trash";
        if (!found || Boolean(found.trashed_at) === toTrash) {
          return ajaxError("Skill not found.");
        }
        const updated: SavedSkill = {
          ...found,
          trashed_at: toTrash ? iso(new Date().toISOString()) : null,
        };
        skills = skills.map((skill) =>
          skill.id === updated.id ? updated : skill,
        );
        return ok({ skill: updated });
      }
      case "skills/delete": {
        const found = skills.find((skill) => skill.id === Number(body.id));
        if (!found?.trashed_at) {
          return skillError(
            "mcpmate_skill_not_trashed",
            "Move the skill to the trash before you delete it for good.",
          );
        }
        skills = skills.filter((skill) => skill.id !== found.id);
        return ok({ message: "Skill deleted for good." });
      }
      case "designs/list": {
        const trash = body.status === "trash";
        const rows = designs.filter(
          (design) => (design.status === "trash") === trash,
        );
        const active = designs.find((design) => design.active);
        return ok({
          items: rows.map(designSummary),
          total: rows.length,
          page: 1,
          per_page: 20,
          total_pages: rows.length > 0 ? 1 : 0,
          active_id: active?.id ?? 0,
          authority: active
            ? {
                level: "design",
                source: "mcpmate",
                note: `The saved design "${active.name}" applies to every page.`,
                builders: [],
                design: { id: active.id, name: active.name },
              }
            : {
                level: "none",
                source: "",
                note: "No design system is set for this site.",
                builders: [],
                design: null,
              },
          counts: {
            saved: designs.filter((design) => design.status !== "trash").length,
            trash: designs.filter((design) => design.status === "trash").length,
          },
          limits: { designs: 50, content_bytes: 65536, name_chars: 100 },
        });
      }
      case "designs/show": {
        const found = designs.find((design) => design.id === Number(body.id));
        return found
          ? ok({ design: designView(found) })
          : designError("mcpmate_design_not_found", "There is no design.");
      }
      case "designs/preview": {
        const parsed = fakeParse(body.content ?? "");
        return ok({ name: "", description: "", ...parsed });
      }
      case "designs/save":
      case "designs/import": {
        const starter = FAKE_STARTERS.find((item) => item.slug === body.slug);
        const content = starter ? FAKE_DESIGN_CONTENT : (body.content ?? "");
        const parsed = fakeParse(content);
        if (parsed.errors.length > 0) {
          return designError(
            "mcpmate_invalid_design",
            `Nothing was saved: the design has 1 problems. ${parsed.errors[0]}`,
            parsed.errors,
          );
        }
        const id = Number(body.id ?? 0);
        const existing = designs.find((design) => design.id === id);
        const saved: Design = {
          ...(existing ?? designs[0] ?? designsFixture("one")[0]),
          id: existing
            ? id
            : Math.max(0, ...designs.map((design) => design.id)) + 1,
          name: starter?.name ?? (body.name || "Untitled design"),
          content,
          tokens: parsed.tokens,
          warnings: parsed.warnings,
          status: existing?.status === "active" ? "active" : "draft",
          active: existing?.active ?? false,
          updated_at: "2026-11-09T09:00:00Z",
          revision_id: existing ? 41 : null,
          created: !existing,
        };
        designs = existing
          ? designs.map((design) => (design.id === id ? saved : design))
          : [saved, ...designs];
        return ok({ message: "Design saved.", design: saved });
      }
      case "designs/activate": {
        const id = Number(body.id);
        designs = designs.map((design) => ({
          ...design,
          active: design.id === id,
          status:
            design.status === "trash"
              ? "trash"
              : design.id === id
                ? "active"
                : "draft",
        }));
        const found = designs.find((design) => design.id === id);
        return found
          ? ok({ message: "Active.", design: found })
          : designError("mcpmate_design_not_found", "There is no design.");
      }
      case "designs/deactivate":
        designs = designs.map((design) => ({
          ...design,
          active: false,
          status: design.status === "trash" ? "trash" : "draft",
        }));
        return ok({ message: "No design is active now." });
      case "designs/trash":
      case "designs/restore": {
        const id = Number(body.id);
        const found = designs.find((design) => design.id === id);
        if (!found) {
          return designError("mcpmate_design_not_found", "There is no design.");
        }
        if (route === "designs/trash" && found.active) {
          return designError(
            "mcpmate_design_active",
            "This is the active design.",
          );
        }
        designs = designs.map((design) =>
          design.id === id
            ? {
                ...design,
                status: route === "designs/trash" ? "trash" : "draft",
              }
            : design,
        );
        return ok({
          message: "Done.",
          design: designs.find((design) => design.id === id),
        });
      }
      case "designs/revisions/restore": {
        const found = designs.find((design) => design.id === Number(body.id));
        return found
          ? ok({ message: "Revision restored.", design: designView(found) })
          : designError("mcpmate_design_not_found", "There is no design.");
      }
      case "sandbox/list":
        return ok({
          plugins: sandbox,
          available: true,
          loader: sandbox.some((plugin) => plugin.status !== "disabled")
            ? "current"
            : "missing",
          limits: {
            plugins: 10,
            files: 50,
            file_bytes: 524288,
            plugin_bytes: 1048576,
            extensions: ["php", "css", "js", "json", "txt", "md"],
          },
        });
      case "sandbox/show": {
        const found = sandboxPlugin(body.slug);
        if (!found) {
          return ajaxError("There is no sandbox plugin.");
        }
        const entries = [
          {
            path: found.main,
            size: FAKE_SANDBOX_CODE.length,
            modified: "2026-11-08T09:00:00Z",
          },
          {
            path: "inc/form.php",
            size: 1830,
            modified: "2026-11-08T09:10:00Z",
          },
        ];
        return ok({
          plugin: found,
          entries,
          count: entries.length,
          bytes: entries.reduce((sum, entry) => sum + entry.size, 0),
          file: body.path
            ? {
                path: String(body.path),
                size: FAKE_SANDBOX_CODE.length,
                sha256: "b".repeat(64),
                content: FAKE_SANDBOX_CODE,
                clipped: false,
              }
            : null,
        });
      }
      case "sandbox/disable":
      case "sandbox/retry": {
        const found = sandboxPlugin(body.slug);
        if (!found) {
          return ajaxError("There is no sandbox plugin.");
        }
        const updated: SandboxPlugin =
          route === "sandbox/disable"
            ? {
                ...found,
                status: "disabled",
                disabled_at: iso(new Date().toISOString()),
              }
            : { ...found, status: "enabled", crash: null };
        sandbox = sandbox.map((plugin) =>
          plugin.slug === updated.slug ? updated : plugin,
        );
        return ok({ message: "Done.", plugin: updated });
      }
      case "designs/library":
        return ok({ starters: FAKE_STARTERS });
      case "memories/list": {
        const trash = body.status === "trash";
        const search = (body.search ?? "").toLowerCase();
        const rows = memories
          .filter((memory) => Boolean(memory.trashed_at) === trash)
          .filter(
            (memory) =>
              !body.user_id || memory.user_id === Number(body.user_id),
          )
          .filter((memory) => !body.tag || memory.tags.includes(body.tag))
          .filter(
            (memory) =>
              !search ||
              `${memory.title} ${memory.content ?? ""}`
                .toLowerCase()
                .includes(search),
          )
          .sort((a, b) => b.updated_at.localeCompare(a.updated_at));
        const page = Number(body.page ?? 1);
        const users = new Map(
          memories.map((memory) => [memory.user_id, memory.user]),
        );
        return ok({
          items: rows.slice((page - 1) * 20, page * 20).map(memoryView),
          total: rows.length,
          page,
          per_page: 20,
          counts: {
            live: memories.filter((memory) => !memory.trashed_at).length,
            trash: memories.filter((memory) => memory.trashed_at).length,
          },
          users: [...users.values()],
          tags: [...new Set(memories.flatMap((memory) => memory.tags))].sort(),
          trash_days: 30,
          limits: memoryLimits,
        });
      }
      case "memories/show": {
        const found = memories.find((memory) => memory.id === Number(body.id));
        return found
          ? ok({ memory: found })
          : ajaxError("That memory was not found.");
      }
      case "memories/update": {
        const found = memories.find((memory) => memory.id === Number(body.id));
        if (!found) {
          return ajaxError("That memory was not found.");
        }
        if (/password\s*[:=]\s*\S{8,}/i.test(body.content ?? "")) {
          const message =
            "This looks like it holds a password or secret value. Memories never store secrets: remove it and save again.";
          return {
            status: 200,
            body: {
              success: false,
              data: {
                code: "mcpmate_invalid_memory",
                message,
                errors: { content: message },
              },
            },
          };
        }
        const content = body.content ?? "";
        const updated: Memory = {
          ...found,
          title: (body.title ?? "").trim(),
          content,
          tags: (JSON.parse(body.tags ?? "[]") as string[]).map((tag) =>
            tag.toLowerCase(),
          ),
          bytes: new TextEncoder().encode(content).length,
          updated_at: iso(new Date().toISOString()),
        };
        memories = memories.map((memory) =>
          memory.id === updated.id ? updated : memory,
        );
        return ok({ message: "Memory saved.", memory: updated });
      }
      case "memories/trash":
      case "memories/restore": {
        const found = memories.find((memory) => memory.id === Number(body.id));
        const toTrash = route === "memories/trash";
        if (!found || Boolean(found.trashed_at) === toTrash) {
          return ajaxError("That memory was not found.");
        }
        const updated: Memory = {
          ...found,
          trashed_at: toTrash ? iso(new Date().toISOString()) : null,
        };
        memories = memories.map((memory) =>
          memory.id === updated.id ? updated : memory,
        );
        return ok({ memory: updated });
      }
      case "memories/purge": {
        const ids =
          body.all === "true"
            ? null
            : (JSON.parse(body.ids ?? "[]") as number[]);
        const before = memories.length;
        memories = memories.filter(
          (memory) =>
            !memory.trashed_at || (ids !== null && !ids.includes(memory.id)),
        );
        return ok({ removed: before - memories.length });
      }
      case "site-instructions":
        return ok(siteInstructions);
      case "site-instructions/update":
      case "site-instructions/preview": {
        const enabled =
          (body.enabled ?? String(siteInstructions.enabled)) === "true";
        const content = (body.content ?? siteInstructions.content)
          .replace(/\r\n?/g, "\n")
          .trim();
        const bytes = new TextEncoder().encode(content).length;
        if (bytes > siteInstructions.max_bytes) {
          const message = `The instructions are ${bytes} bytes. Keep them to 8192 bytes (8 KB) or less.`;
          return {
            status: 200,
            body: {
              success: false,
              data: {
                code: "mcpmate_invalid_setting",
                message,
                errors: { content: message },
              },
            },
          };
        }
        if (route === "site-instructions/preview") {
          const block = instructionsBlock(enabled, content);
          const instructions = block
            ? `${block}\n\n${serverInstructions}`
            : serverInstructions;
          return ok({
            instructions,
            bytes: instructions.length,
            site_bytes: block.length,
          });
        }
        siteInstructions = {
          ...siteInstructions,
          enabled,
          content,
          updated_at: iso(new Date().toISOString()),
          updated_by: { id: 1, name: "Niloy" },
        };
        return ok({ message: "AI instructions saved.", ...siteInstructions });
      }
      case "license/status":
        return ok({ status: license });
      case "license/activate": {
        if (body.license_key !== "VALID-PRO-KEY") {
          return ajaxError("This license key is not valid or not active.");
        }
        license = {
          ...license,
          has_key: true,
          license_key: "*********-KEY",
          valid: true,
          update_entitled: true,
          update_expires_at: "2027-11-02T00:00:00Z",
          last_verified_at: iso(minutes(0)),
        };
        return ok({ message: "License activated.", status: license });
      }
      case "license/deactivate":
        license = {
          ...license,
          has_key: false,
          license_key: "",
          valid: false,
          update_entitled: false,
          update_expires_at: null,
          last_verified_at: null,
        };
        return ok({
          message: "License removed from this site.",
          status: license,
        });
      case "stock-photos/update": {
        const configured = (body.access_key ?? "") !== "";
        return ok({
          message: "Stock photo settings saved.",
          configured,
          app_name: configured ? (body.app_name ?? "") : "",
        });
      }
      default:
        return ajaxError(`No fake for ${route}`);
    }
  }

  function restRoute(
    path: string,
    method: string,
    body: string | null,
    query: URLSearchParams,
  ): FakeResponse {
    if (path === "stats") {
      return rest(statsFixture(scenario));
    }
    if (path === "tools" && method === "POST") {
      const saved = applyToolsUpdate(
        tools,
        JSON.parse(body ?? "{}") as ToolsUpdate,
      );
      if (typeof saved === "string") {
        return {
          status: 400,
          body: {
            ok: false,
            data: null,
            error: {
              code: "mcpmate_unknown_tool",
              message: saved,
              hint: null,
            },
            request_id: "test",
            truncated: false,
          },
        };
      }
      tools = saved;
      return rest(tools);
    }
    if (path === "tools") {
      return rest(tools);
    }
    if (path === "audit-log") {
      return rest(auditPage(audit, query));
    }
    if (path === "block-queue") {
      const status = query.get("status") as BlockBatchStatus | null;
      const shown = blockBatches.filter(
        (batch) => !batch.trashed && (!status || batch.status === status),
      );
      const counts = { open: 0, ready: 0, running: 0, finalized: 0, failed: 0 };
      blockBatches
        .filter((batch) => !batch.trashed)
        .forEach((batch) => counts[batch.status]++);

      return rest({
        items: shown.map(({ items: _items, ...batch }) => batch),
        total: shown.length,
        page: 1,
        per_page: 20,
        total_pages: shown.length > 0 ? 1 : 0,
        counts,
      });
    }
    const batchPath = /^block-queue\/(\d+)(\/trash)?$/.exec(path);
    if (batchPath) {
      const found = blockBatches.find(
        (batch) => batch.id === Number(batchPath[1]),
      );
      if (!found) {
        return {
          status: 404,
          body: {
            ok: false,
            data: null,
            error: {
              code: "mcpmate_block_batch_not_found",
              message: "There is no batch.",
              hint: null,
            },
            request_id: "test",
            truncated: false,
          },
        };
      }
      if (batchPath[2] && method === "POST") {
        blockBatches = blockBatches.map((batch) =>
          batch.id === found.id ? { ...batch, trashed: true } : batch,
        );
      }

      return rest(blockBatches.find((batch) => batch.id === found.id));
    }

    return { status: 404, body: { ok: false, data: null } };
  }

  /** Answer one request: admin-ajax (`action` in the form) or REST. */
  function handle(
    url: string,
    form: Record<string, string> | null,
    request: { method?: string; body?: string | null } = {},
  ): FakeResponse {
    if (form) {
      const route = String(form.action ?? "").replace(/^MCPMATE_/, "");
      if (hang.has(route)) {
        return { status: 200, body: null, hang: true };
      }
      return fail.has(route)
        ? ajaxError("The fake site was told to fail.")
        : ajax(route, form);
    }

    const parsed = new URL(url, "http://localhost");
    const path = parsed.pathname
      .replace(/^.*\/mcpmate\/v1\//, "")
      .replace(/\/$/, "");
    const method = (request.method ?? "GET").toUpperCase();
    const named = method === "GET" ? path : `${method} ${path}`;
    if (hang.has(named)) {
      return { status: 200, body: null, hang: true };
    }

    return fail.has(named)
      ? { status: 500, body: { ok: false, data: null } }
      : restRoute(path, method, request.body ?? null, parsed.searchParams);
  }

  return {
    handle,
    tools: () => tools,
    /** Start or stop failing a route (a Retry that then works). */
    setFail: (route: string, failing: boolean) =>
      failing ? fail.add(route) : fail.delete(route),
    grants: () => grants,
    audit: () => audit,
    /** Add audit rows (a call that just happened). */
    setAudit: (rows: AuditLogEntry[]) => {
      audit = rows;
    },
    preferences: () => preferences,
    access: () => access,
    readMode: () => readMode,
    chat: () => chat,
    connection: () => connection,
    approvals: () => approvals,
    blockBatches: () => blockBatches,
    designs: () => designs,
  };
}

export type FakeSite = ReturnType<typeof createFakeSite>;

/** A `fetch` that answers from the fake site (Vitest). */
export function fakeFetch(site: FakeSite) {
  return async (url: string, init: RequestInit = {}) => {
    let form: Record<string, string> | null = null;

    if (init.body instanceof FormData) {
      form = {};
      init.body.forEach((value, key) => {
        (form as Record<string, string>)[key] = String(value);
      });
    }

    const answer = site.handle(String(url), form, {
      method: init.method,
      body: typeof init.body === "string" ? init.body : null,
    });
    if (answer.hang) {
      return new Promise<Response>(() => undefined);
    }

    if (answer.sse !== undefined) {
      return new Response(answer.sse, {
        status: 200,
        headers: { "content-type": "text/event-stream; charset=utf-8" },
      });
    }

    return new Response(JSON.stringify(answer.body), {
      status: answer.status,
      headers: { "content-type": "application/json" },
    });
  };
}

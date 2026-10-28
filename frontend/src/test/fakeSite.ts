/**
 * A stand-in for this plugin's backend (admin-ajax and REST), with state,
 * for page tests and for `pnpm test:a11y`, which serves it from the Vite dev
 * server. It answers only the routes the Dashboard, Connections, Tools,
 * Activity and Settings pages call, in the shapes `backend/` returns. Never
 * shipped: nothing in the app imports it.
 */
import type {
  AccessState,
  Approval,
  AuditLogEntry,
  ChangeDetail,
  ChangeEntry,
  ChecksResponse,
  ClientsResponse,
  ConnectionSummary,
  Grant,
  RegisteredClient,
  Preferences,
  StatsSummary,
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
  /** An approved request fails when it runs (the command was turned off meanwhile). */
  approvalFails?: boolean;
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
    group("abilities", "Abilities", "abilities", [
      tool("list_abilities", "List abilities", "read", true),
      tool("run_ability", "Run an ability", "write", true, {
        items: [
          item("shop/export-orders", "Export orders", "write", false),
          item("shop/delete-drafts", "Delete old drafts", "write", false, true),
          item("forms/list-entries", "List form entries", "read", true),
        ],
      }),
    ]),
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
  // Entries older than the retention window, for Remove old entries now.
  let expired = scenario === "many" ? 1240 : 0;
  let preferences: Preferences = {
    ...DEFAULT_PREFERENCES,
    ...options.preferences,
  };
  const filtered = options.filtered ?? {};

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
    connection: () => connection,
    approvals: () => approvals,
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

    return new Response(JSON.stringify(answer.body), {
      status: answer.status,
      headers: { "content-type": "application/json" },
    });
  };
}

/**
 * Shared type definitions for the MCPMate frontend.
 */

// ─────────────────────────────────────────────────────────────
// API Response Types
// ─────────────────────────────────────────────────────────────

export interface ApiResponse<T = unknown> {
  success: boolean;
  data: T;
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// REST envelope (every response under mcpmate/v1)
// ─────────────────────────────────────────────────────────────

export interface RestEnvelope<T> {
  ok: boolean;
  data: T | null;
  error: { code: string; message: string; hint: string | null } | null;
  request_id: string;
  truncated: boolean;
}

// ─────────────────────────────────────────────────────────────
// How AI apps reach this site (docs/mcp-in-plugin.md §4.1)
// ─────────────────────────────────────────────────────────────

/** admin-ajax `connection/status`: public addresses only. */
export interface ConnectionSummary {
  /** The MCP URL AI apps connect to: `…/wp-json/mcpmate/mcp`. */
  mcp_url: string;
  /** The OAuth issuer: `…/mcpmate`. */
  issuer: string;
  home_url: string;
  /** MCPMATE_PUBLIC_URL (a tunnel for a local site), or ''. */
  public_url: string;
  /** Whether AI apps may register themselves (Settings). */
  open_registration: boolean;
}

export interface ConnectionStatusResponse {
  connection: ConnectionSummary;
}

/**
 * Connected now; was connected (every grant revoked or expired); never
 * approved (removed after a day); or revoked by a site admin.
 */
export type ClientStatus = "connected" | "not_connected" | "unused" | "revoked";

/** One OAuth client of this site (admin-ajax `clients/list`). */
export interface RegisteredClient {
  /** SHA-256 of the client ID: what revoke and restore take. */
  client_key: string;
  client_id: string;
  /** `dcr`: registered itself (not verified); `cimd`: a metadata document. */
  kind: "dcr" | "cimd";
  client_name: string;
  /** The metadata document's host for `cimd`, else null. */
  verified_host: string | null;
  redirect_hosts: string[];
  /** Pending or active grants. */
  live_grants: number;
  status: ClientStatus;
  /** UTC, ISO 8601. */
  created_at: string;
  last_used_at: string | null;
  revoked_at: string | null;
}

export interface ClientsResponse {
  clients: RegisteredClient[];
  /** Self-registered clients on record, and how many the site accepts. */
  dcr_count: number;
  dcr_limit: number;
}

export interface ClientRevokeResponse {
  client: RegisteredClient;
  revoked_grants: number;
}

export type Scope = "site:read" | "site:write";

export interface ConnectRequestDetails {
  ok: true;
  /** The authorization request of the plugin's own OAuth server. */
  request: string;
  client: {
    name: string;
    uri: string;
    /** `cimd:<host>` (vouched for by that host) or `dcr` (not verified). */
    vouched_by: string;
    redirect_host: string;
    localhost_redirect: boolean;
  };
  scopes: Scope[];
  /** UTC, ISO 8601. */
  expires_at: string;
  site: string;
  user: { login: string; display_name: string; roles: string[] };
  /** The origin of the redirect URI the browser goes back to. */
  redirect_origin: string;
}

export interface ConnectRequestError {
  ok: false;
  request: "";
  code: string;
  message: string;
}

export type ConnectRequest = ConnectRequestDetails | ConnectRequestError;

export type GrantStatus = "pending" | "active" | "revoked";

/** Full (every tool that is on), Read-only, or Custom (a tool list). */
export type GrantAccess = "full" | "read_only" | "custom";

export type RevokeReason =
  | "admin"
  | "user_deleted"
  | "refresh_reuse"
  | "code_reuse"
  /** A site admin revoked its registered client. */
  | "client_revoked";

export interface Grant {
  grant_id: string;
  status: GrantStatus;
  client_name: string;
  client_id: string;
  vouched_by: string;
  redirect_host: string;
  scopes: Scope[];
  access: GrantAccess;
  /** Catalog tool names for Custom access; null for Full and Read-only. */
  tools: string[] | null;
  user: { id: number; login: string | null; display_name: string | null };
  /** UTC, ISO 8601. */
  created_at: string;
  last_used_at: string | null;
  /** UTC, ISO 8601, of the first call that reached the site (setup's last step). */
  first_call_at: string | null;
  revoked_at: string | null;
  revoke_reason: RevokeReason | null;
}

export interface GrantsResponse {
  grants: Grant[];
  limit: number;
}

/** Pause AI access (admin-ajax `access`, `access/pause`, `access/resume`). */
export interface AccessState {
  paused: boolean;
  /** ISO 8601 UTC, while paused. */
  at: string | null;
  by: { id: number; name: string | null } | null;
}

/**
 * Read mode (admin-ajax `read-mode`, `read-mode/on`, `read-mode/off`): every
 * AI app may read the site only.
 */
export interface ReadModeState {
  on: boolean;
  /** ISO 8601 UTC, while on. */
  at: string | null;
  by: { id: number; name: string | null } | null;
}

/** Before you uninstall (admin-ajax `uninstall/summary`). */
export interface UninstallSummary {
  /** Settings → Advanced, Remove all data on uninstall. */
  delete_data: boolean;
  multisite: boolean;
  /** Tables the uninstall drops, with their rows. */
  tables: { name: string; rows: number }[];
  /** Options the uninstall deletes. */
  options: string[];
  /** MCPMate's options that stay (theme draft and backup records). */
  keeps: string[];
  /** Sandbox plugins' folders, which stay. `more`: past 5,000 files. */
  sandbox: { plugins: number; files: number; more: boolean };
  theme_backups: { count: number; bytes: number };
  theme_drafts: number;
  designs: number;
}

export type UninstallExportKind = "audit_log" | "changes";

/**
 * One page of an export (admin-ajax `uninstall/export {kind, after, until}`):
 * rows by ID, after `after` and up to `until`, which the first page fixes.
 */
export interface UninstallExportPage {
  kind: UninstallExportKind;
  per_page: number;
  /** The highest ID in this export; pass it back on every later page. */
  until: number;
  /** Rows up to `until`. */
  total: number;
  /** Pass as `after` for the next page. */
  next_after: number;
  done: boolean;
  items: Record<string, unknown>[];
}

// ─────────────────────────────────────────────────────────────
// Tools (GET /tools) and the Dashboard numbers (GET /stats)
// ─────────────────────────────────────────────────────────────

export type ToolKind = "read" | "write";

export interface ToolItem {
  name: string;
  label: string;
  kind: ToolKind;
  approval: boolean;
  enabled: boolean;
  /** What it does (integration operations, abilities). */
  description?: string;
  /**
   * The section it is listed under: an integration operation's plugin, e.g.
   * "Contact Form 7", or an ability's source (its category's label, else its namespace).
   */
  section?: string;
  /** An integration operation's plugin ID, e.g. `cf7` (MCPMate Pro). */
  integration?: string;
}

/** A plugin MCPMate Pro has an integration for, installed or not (the Tools page's tiles). */
export interface IntegrationPlugin {
  /** The prefix of its operation names, e.g. `cf7`. */
  id: string;
  label: string;
  status: "missing" | "installed" | "active";
  /** A theme (Bricks, Divi), not a plugin. */
  theme: boolean;
  category: "forms" | "seo" | "fields" | "store" | "builders" | "themes";
}

export interface Tool {
  /** The MCP tool name, e.g. `site_info`. */
  name: string;
  label: string;
  description: string;
  kind: ToolKind;
  /** Asks a site admin first. */
  approval: boolean;
  always_on: boolean;
  /** Turned on site-wide. */
  enabled: boolean;
  /** On and available. */
  allowed: boolean;
  /** CLI commands of `run_wp_cli`, abilities of `run_ability`. */
  items?: ToolItem[];
}

export type ToolUnavailableReason =
  | ""
  | "plugin_missing"
  | "plugin_inactive"
  | "wordpress_too_old"
  | "file_edit_disallowed"
  | "not_configured"
  | "constant_missing"
  | "cli_unavailable";

export interface ToolGroup {
  id: string;
  label: string;
  source: "core" | "integrations" | "abilities";
  available: boolean;
  unavailable_reason: ToolUnavailableReason;
  /**
   * media: `svg`; stock_photos: whether an Unsplash key is set (never the key)
   * and its app name; integrations: every supported plugin.
   */
  options: {
    svg?: boolean;
    configured?: boolean;
    app_name?: string;
    plugins?: IntegrationPlugin[];
    /** Opt-in hatches: the wp-config.php constant the group still needs. */
    constant?: string;
  };
  tools: Tool[];
}

export interface ToolsResponse {
  groups: ToolGroup[];
  counts: { enabled: number; total: number };
}

/** POST /tools: only what changed. */
export interface ToolsUpdate {
  /** Tool name => on. */
  tools: Record<string, boolean>;
  /** `run_wp_cli` / `run_ability` => item name => on. */
  items: Record<string, Record<string, boolean>>;
  options: { svg?: boolean };
}

export interface StatsSummary {
  calls_24h: {
    total: number;
    reads: number;
    writes: number;
    ok: number;
    error: number;
    pending: number;
  };
  /** ok / (ok + error), 0–1; null with no decided calls. */
  success_rate_24h: number | null;
  tools: { enabled: number; total: number };
  changes_week: { total: number; by_type: Record<string, number> };
  log_reads: boolean;
  change_history: boolean;
  generated_at: string;
}

export interface CoreProxyResponse {
  enabled: boolean;
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// Audit Log Types
// ─────────────────────────────────────────────────────────────

export type AuditResult = "ok" | "error" | "pending_approval";

export interface AuditLogEntry {
  id: number;
  /** UTC, ISO 8601 (`2026-10-24T09:30:00Z`). */
  created_at: string;
  user_id: number;
  user_login: string | null;
  request_id: string;
  tool: string;
  action: string;
  target: string;
  /** Request arguments with secrets redacted. */
  args: unknown;
  result: AuditResult;
  message: string;
  /** The AI app (grant) an MCP call acted for; '' otherwise. */
  grant_id: string;
  /** That AI app's name; null without a grant, or for a grant that is gone. */
  client_name?: string | null;
  /** `oauth` (an MCP call), `cookie` (wp-admin), or '' (internal). */
  auth: string;
  /** `read` or `write`. */
  kind: string;
  /** The MCP tool the route belongs to (`site_info`); '' for admin actions. */
  mcp_tool: string;
  duration_ms: number | null;
}

export interface AuditLogPage {
  items: AuditLogEntry[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

export interface AuditLogFilters {
  /** One row. */
  id?: string;
  /** The internal tool (`connection` for setup and connection rows). */
  tool?: string;
  /** The MCP tool (`elementor_save`). */
  mcp_tool?: string;
  result?: AuditResult;
  /** One AI app's rows (`g_…`). */
  grant_id?: string;
  user_id?: string;
  /** Part of the tool, action, target or message. */
  search?: string;
  /** UTC, `YYYY-MM-DD` or RFC 3339, inclusive. */
  from?: string;
  /** UTC, `YYYY-MM-DD` (whole day) or RFC 3339, inclusive. */
  to?: string;
  order_by?: "created_at" | "duration_ms";
  order?: "asc" | "desc";
}

/** admin-ajax `audit-log/purge-preview` and `audit-log/purge` (B9). */
export interface AuditPurgeWindow {
  /** Below 1: every entry is kept. */
  days: number;
  /** UTC, ISO 8601; null when every entry is kept. */
  older_than: string | null;
}

// ─────────────────────────────────────────────────────────────
// Change history (admin-ajax `changes/list`, `changes/show`)
// ─────────────────────────────────────────────────────────────

export type ChangeAction = "create" | "update" | "trash" | "delete";

export interface ChangeEntry {
  id: number;
  /** UTC, ISO 8601. */
  created_at: string;
  /** The audit row of the call that made the change; 0 when there was none. */
  audit_id: number;
  request_id: string;
  user_id: number;
  user_login: string | null;
  grant_id: string;
  client_name: string | null;
  tool: string;
  mcp_tool: string;
  action: ChangeAction;
  /** post, post_meta, term, term_meta, option, user, user_meta, comment, product, order. */
  object_type: string;
  object_id: number;
  object_key: string;
  object_label: string;
  truncated: boolean;
  bytes: number;
}

export interface ChangesPage {
  items: ChangeEntry[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

export interface ChangesFilters {
  object_type?: string;
  change_action?: ChangeAction;
  mcp_tool?: string;
  grant_id?: string;
  audit_id?: string;
  search?: string;
  from?: string;
  to?: string;
}

export interface ChangeDiffField {
  field: string;
  status: "unchanged" | "added" | "removed" | "changed";
  /** For `unchanged`. */
  value?: unknown;
  ops?: { op: "equal" | "add" | "remove"; lines: string[] }[];
}

export interface ChangeDetail extends ChangeEntry {
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  diff: { fields: ChangeDiffField[]; added: number; removed: number };
}

// ─────────────────────────────────────────────────────────────
// Settings (admin-ajax `preferences`, `preferences/update`; B8)
// ─────────────────────────────────────────────────────────────

export interface Preferences {
  log_reads: boolean;
  audit_retention_days: number;
  read_retention_days: number;
  change_history: boolean;
  change_history_types: string[];
  change_retention_days: number;
  approve_capability: "manage_options" | "edit_others_posts" | "publish_posts";
  /** Minutes an OAuth access token works. */
  access_token_minutes: number;
  /** Days an approval's refresh tokens work, counted from the approval. */
  refresh_token_days: number;
  /** Whether AI apps may register themselves (Dynamic Client Registration). */
  open_registration: boolean;
  /** Requests per minute for each AI app on the MCP endpoint. */
  rate_limit: number;
  delete_data_on_uninstall: boolean;
}

export type PreferenceKey = keyof Preferences;

export interface PreferencesResponse {
  preferences: Preferences;
  /** Keys a site admin can't change here: `locked` (D5) or set by a filter in code. */
  fixed: Partial<
    Record<PreferenceKey, { reason: "locked" | "filtered"; value?: unknown }>
  >;
  /** Every type change history can record. */
  change_types: string[];
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// CLI command settings (POST /cli allowlist)
// ─────────────────────────────────────────────────────────────

export type CliRisk = "read" | "write" | "destructive";

export interface CliCommand {
  name: string;
  summary: string;
  risk: CliRisk;
  capability: string;
  supports_dry_run: boolean;
  dry_run_default: boolean;
  /** A real run becomes a background job (get_cli_job follows it). */
  background?: boolean;
  /** Synopsis, e.g. `option get <key>`. */
  usage: string;
  enabled: boolean;
}

export interface CliCommandsResponse {
  commands: CliCommand[];
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// Ability settings (POST /abilities/run allowlist)
// ─────────────────────────────────────────────────────────────

export interface Ability {
  /** Namespaced name, e.g. `my-plugin/get-report`. */
  name: string;
  label: string;
  description: string;
  category: string;
  risk: CliRisk;
  enabled: boolean;
  annotations: {
    readonly: boolean | null;
    destructive: boolean | null;
    idempotent: boolean | null;
  };
}

export interface AbilitiesResponse {
  /** False on WordPress < 6.9 (no Abilities API). */
  supported: boolean;
  abilities: Ability[];
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// Database inspection setting (GET /db/* routes)
// ─────────────────────────────────────────────────────────────

export interface DbInspectResponse {
  enabled: boolean;
  message?: string;
}

export interface MediaUploadResponse {
  enabled: boolean;
  /** SVG files are accepted (sanitized, administrators only). Off by default. */
  svg_enabled: boolean;
  message?: string;
}

export interface ElementorSettingResponse {
  enabled: boolean;
  /** Whether Elementor is active (the /elementor routes exist only then). */
  available: boolean;
  message?: string;
}

export interface ThemeDraftsResponse {
  enabled: boolean;
  message?: string;
}

// ─────────────────────────────────────────────────────────────
// Approvals (destructive requests waiting for a site admin)
// ─────────────────────────────────────────────────────────────

export type ApprovalStatus =
  | "pending"
  | "approved"
  | "rejected"
  | "expired"
  | "executed";

export interface ApprovalUser {
  id: number;
  login: string | null;
  display_name: string | null;
}

/** Stored outcome of an approved request, shaped like the REST envelope. */
export interface ApprovalResult {
  ok: boolean;
  data: unknown;
  error: { code: string; message: string; hint: string } | null;
}

export interface Approval {
  id: number;
  status: ApprovalStatus;
  /** Audit-log tool, e.g. `cli`. */
  tool: string;
  /** e.g. `plugin.activate`. */
  action: string;
  /** e.g. `plugin:akismet`. */
  target: string;
  /** Exactly what will run; for `cli`, `{ command }`. */
  args: Record<string, unknown>;
  /** Dry-run result captured when the request was made. */
  preview: unknown;
  result: ApprovalResult | null;
  request_id: string;
  /** UTC, ISO 8601. */
  created_at: string;
  created_by: ApprovalUser;
  expires_at: string;
  decided_at: string | null;
  decided_by: ApprovalUser | null;
  executed_at: string | null;
}

export type ApprovalListItem = Omit<Approval, "preview" | "result">;

export interface ApprovalsPage {
  items: ApprovalListItem[];
  total: number;
  page: number;
  per_page: number;
}

/** admin-ajax error payload (`wp_send_json_error`). */
export interface AjaxError {
  code: string;
  message: string;
  /** `preferences/update`: why each refused setting was refused. */
  errors?: Record<string, string>;
}

// ─────────────────────────────────────────────────────────────
// First-run setup wizard (#/setup)
// ─────────────────────────────────────────────────────────────

export type OnboardingStatus =
  | "not_started"
  | "in_progress"
  | "skipped"
  | "completed";

export type OnboardingStep = "welcome" | "safety" | "add_ai" | "first_call";

export interface OnboardingState {
  v: 1;
  status: OnboardingStatus;
  step: OnboardingStep;
  source: "activation" | "existing" | "rerun";
  /** UTC, `Y-m-d H:i:s`. */
  started_at: string | null;
  updated_at: string | null;
  completed_at: string | null;
  /** Step 4 offered its defaults once. */
  features_defaulted: boolean;
  /** UTC, `Y-m-d H:i:s`: the Add it to your AI step's baseline for "a new AI app". */
  connect_since: string | null;
  /** The AI app found by the Add it to your AI step. */
  grant_id: string;
}

export type CheckStatus = "pass" | "warn" | "fail" | "info" | "later";

export interface CheckDetail {
  text: string;
  /** Shown with a Copy button. */
  code?: string;
}

export interface EnvironmentCheck {
  id:
    | "https"
    | "permalinks"
    | "mcp_endpoint"
    | "auth_header"
    | "well_known"
    | "caching"
    | "execution_time"
    | "versions"
    | "admin"
    | "environment";
  status: CheckStatus;
  title: string;
  message: string;
  fix: string;
  details: CheckDetail[];
}

export interface ChecksResponse {
  checks: EnvironmentCheck[];
  summary: { pass: number; warn: number; fail: number };
}

export interface FirstCall {
  method: string;
  /** Route under mcpmate/v1, e.g. `site-info`. */
  route: string;
  result: AuditResult;
  message: string;
  /** UTC, ISO 8601. */
  at: string;
  audit_id: number;
}

export interface OnboardingStateResponse {
  onboarding: OnboardingState;
  connection: ConnectionSummary;
  grant: Grant | null;
  first_call: FirstCall | null;
  features: {
    abilities_supported: boolean;
    abilities_available: number;
    elementor_available: boolean;
    can_edit_themes: boolean;
    /** MCPMate Pro's features are registered: its switches (posts, commands, media, theme drafts, Elementor, abilities) exist. */
    pro_features: boolean;
  };
}

/** MCPMate Pro's license (admin-ajax `license/*`, from the pro add-on). */
/** admin-ajax `ghost-mode` (MCPMate Pro, rule 10). */
export interface GhostModeView {
  available: boolean;
  constant: string;
  /** User IDs MCPMate is hidden from. */
  users: number[];
  at: string | null;
  by: { id: number; name: string } | null;
  admins: {
    id: number;
    name: string;
    login: string;
    self: boolean;
    hideable: boolean;
  }[];
}

export interface LicenseStatus {
  has_key: boolean;
  /** Masked: every character but the last four is `*`. */
  license_key: string;
  valid: boolean;
  update_entitled: boolean;
  /** When updates stop, as the license server wrote it; null without a date. */
  update_expires_at: string | null;
  /** The license server's reason, e.g. `domain_limit_exceeded`. */
  reason: string | null;
  /** UTC, ISO 8601. */
  last_verified_at: string | null;
  /** The domain the license is activated for. */
  domain: string;
}

/** Settings → AI instructions (admin-ajax `site-instructions*`, from MCPMate Pro). */
export interface SiteInstructions {
  enabled: boolean;
  /** As stored: line ends as `\n`, control characters removed. */
  content: string;
  /** The cap in bytes (8 KiB). */
  max_bytes: number;
  /** UTC, ISO 8601; null until the first save. */
  updated_at: string | null;
  updated_by: { id: number; name: string } | null;
  message?: string;
}

/** admin-ajax `site-instructions/preview`: the server instructions an AI app gets. */
export interface SiteInstructionsPreview {
  instructions: string;
  bytes: number;
  /** Bytes of the site owner's part (0 while off or empty). */
  site_bytes: number;
}

/** One memory (MCPMate Pro, admin-ajax `memories/*`): a note AI apps keep for one WordPress user. */
export interface Memory {
  id: number;
  user_id: number;
  user: { id: number; name: string | null };
  /** The grant that saved it first ('' when a site admin's or unknown). */
  grant_id: string;
  title: string;
  tags: string[];
  /** Bytes of the content. */
  bytes: number;
  /** UTC, ISO 8601. */
  created_at: string;
  updated_at: string;
  trashed_at: string | null;
  /** In `memories/show` and after a save; the list has `excerpt`. */
  content?: string;
  excerpt?: string;
}

export type MemoryStatus = "live" | "trash";

export interface MemoriesFilters {
  status?: MemoryStatus;
  user_id?: number;
  tag?: string;
  search?: string;
}

/** admin-ajax `memories/list`. */
export interface MemoriesPage {
  items: Memory[];
  total: number;
  page: number;
  per_page: number;
  counts: { live: number; trash: number };
  users: { id: number; name: string | null }[];
  tags: string[];
  /** Days a memory stays in the trash before it is deleted for good. */
  trash_days: number;
  limits: {
    title_chars: number;
    content_bytes: number;
    tags: number;
    tag_chars: number;
  };
}

/** A skill a WordPress user saved (admin-ajax `skills/*`, Tools → Skills). */
export interface SavedSkill {
  id: number;
  user: { id: number; login: string | null; name: string | null };
  name: string;
  description: string;
  this_site_only: boolean;
  bytes: number;
  /** UTC, ISO 8601. */
  updated_at: string;
  trashed_at: string | null;
  /** In `skills/show` and after a save. */
  body?: string;
  owner?: "user";
}

/** One of MCPMate's playbooks (read-only). */
export interface SystemSkill {
  name: string;
  description: string;
  /** The tools its MCP prompt needs. */
  tools: string[];
  updated_at: string;
  body?: string;
  owner?: "system";
}

export type SkillStatus = "live" | "trash";

/** admin-ajax `skills/list`. */
export interface SkillsPage {
  skills: SavedSkill[];
  counts: { live: number; trash: number };
  system: SystemSkill[];
  /** Days a skill stays in the trash before it is deleted for good. */
  trash_days: number;
  limits: { description_chars: number; body_bytes: number };
}

// ─────────────────────────────────────────────────────────────
// Block editor queue (MCPMate Pro; REST `block-queue/*`, wpAdmin)
// ─────────────────────────────────────────────────────────────

/** open: takes changes; ready: waits for a site admin; running: a browser finalizes it. */
export type BlockBatchStatus =
  | "open"
  | "ready"
  | "running"
  | "finalized"
  | "failed";

/** The whole post as markup, as a block tree, or the post's own blocks re-saved. */
export type BlockChangeMode = "content" | "blocks" | "resave";

export type BlockChangeStatus = "queued" | "prepared" | "saved" | "failed";

export interface BlockQueueError {
  code: string;
  message: string;
  hint: string;
  /** What the browser reported, when it failed there. */
  reason?: string;
  item_id?: number;
  post_id?: number;
  /** Changes saved before the batch stopped. */
  saved?: number;
}

export interface BlockChangeResult {
  id: number;
  target_id: number;
  copied: boolean;
  changed: boolean;
  status: string;
  version: string;
  warning_count: number;
  loss_accepted: boolean;
  edit_url: string;
  preview_url: string;
}

export interface BlockChange {
  id: number;
  position: number;
  post_id: number;
  post_title: string;
  post_status: string;
  mode: BlockChangeMode;
  payload_bytes: number;
  expected_version: string;
  loss_accepted: boolean;
  status: BlockChangeStatus;
  editor: { recovered: number; js_only_blocks: string[] } | null;
  result: BlockChangeResult | null;
  error: BlockQueueError | null;
}

export interface BlockBatch {
  id: number;
  label: string;
  note: string;
  status: BlockBatchStatus;
  item_count: number;
  trashed: boolean;
  created_at: string;
  updated_at: string;
  requested_at: string | null;
  finalized_at: string | null;
  finalization_url: string;
  error: BlockQueueError | null;
  user: ApprovalUser;
  grant: { grant_id: string; client_name: string; status: string } | null;
  finalized_by: ApprovalUser | null;
  /** Only in one batch's answer. */
  items?: BlockChange[];
}

export interface BlockQueuePage {
  items: BlockBatch[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  counts: Record<BlockBatchStatus, number>;
}

/** One change as the finalizer gets it: no payload (that comes in pieces). */
export interface BlockQueueJob {
  id: number;
  position: number;
  post_id: number;
  post_title: string;
  mode: BlockChangeMode;
  /** `post.php?post=…&action=edit&mcpmate_finalizer=1` on this site. */
  editor_url: string;
}

export interface BlockQueueClaim {
  /** Proves this browser holds the batch; never shown or logged. */
  lease: string;
  lease_expires_at: string;
  batch: BlockBatch;
  items: BlockQueueJob[];
}

export interface BlockPayloadPiece {
  piece: string;
  offset: number;
  next_offset: number;
  total_bytes: number;
  mode: BlockChangeMode;
}

// ─────────────────────────────────────────────────────────────
// Design system (MCPMate Pro; admin-ajax `designs/*`)
// ─────────────────────────────────────────────────────────────

export type DesignTokenGroup =
  | "colors"
  | "fonts"
  | "type_scale"
  | "spacing"
  | "radii"
  | "shadows"
  | "components";

export type DesignTokens = Partial<
  Record<DesignTokenGroup, Record<string, string>>
>;

export interface DesignSummary {
  id: number;
  name: string;
  description: string;
  status: "active" | "draft" | "trash";
  active: boolean;
  updated_at: string;
  colors: Record<string, string>;
}

export interface DesignRevision {
  id: number;
  at: string;
  by: { id: number; name: string | null };
}

export interface Design extends DesignSummary {
  /** Set when this view is one of the design's revisions. */
  revision: number | null;
  content: string;
  tokens: DesignTokens;
  warnings: string[];
  revisions: DesignRevision[];
  created?: boolean;
  /** The revision that holds the text a save replaced. */
  revision_id?: number | null;
}

/** Who owns the design of the pages AI apps build. */
export interface DesignAuthority {
  level: "design" | "builder" | "none";
  source: string;
  note: string;
  builders: {
    source: string;
    label: string;
    scope: string;
    note: string;
    tokens: { colors: Record<string, string>; fonts: Record<string, string> };
  }[];
  design: { id: number; name: string } | null;
}

export interface DesignsPage {
  items: DesignSummary[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  active_id: number;
  authority: DesignAuthority;
  counts: { saved: number; trash: number };
  limits: { designs: number; content_bytes: number; name_chars: number };
}

export interface DesignPreview {
  name: string;
  description: string;
  tokens: DesignTokens;
  errors: string[];
  warnings: string[];
}

export interface DesignStarter {
  slug: string;
  name: string;
  description: string;
  colors: Record<string, string>;
}

// ── MCPMate Pro: sandbox plugins (Tools → Sandbox, Approvals) ──

/** disabled; enabled (runs); crashed or changed (enabled, but the loader skips it). */
export type SandboxStatus = "disabled" | "enabled" | "crashed" | "changed";

export interface SandboxCrash {
  message: string;
  /** Relative to wp-content/mcpmate-sandbox (or to WordPress). */
  file: string;
  line: number;
  at: string;
}

export interface SandboxPlugin {
  slug: string;
  name: string;
  description: string;
  status: SandboxStatus;
  main: string;
  created_at: string | null;
  enabled_at: string | null;
  disabled_at: string | null;
  /** The current crash, or for a disabled plugin the last one. */
  crash: SandboxCrash | null;
}

export type SandboxLoaderStatus =
  | "missing"
  | "current"
  | "outdated"
  | "foreign";

export interface SandboxListing {
  plugins: SandboxPlugin[];
  available: boolean;
  loader: SandboxLoaderStatus;
  limits: {
    plugins: number;
    files: number;
    file_bytes: number;
    plugin_bytes: number;
    extensions: string[];
  };
}

export interface SandboxFileEntry {
  path: string;
  size: number;
  modified: string;
}

export interface SandboxShow {
  plugin: SandboxPlugin;
  entries: SandboxFileEntry[];
  count: number;
  bytes: number;
  file: {
    path: string;
    size: number;
    sha256: string;
    content: string;
    /** The file is longer than the part shown. */
    clipped: boolean;
  } | null;
}

/** An enable_sandbox_plugin approval's preview: every file, with its code. */
export interface SandboxApprovalPreview {
  plugin: { slug: string; name: string; description: string };
  main: string;
  file_count: number;
  total_bytes: number;
  loader: SandboxLoaderStatus;
  note: string;
  files: { path: string; bytes: number; sha256: string; content: string }[];
}

// ─────────────────────────────────────────────────────────────
// Chat in wp-admin (MCPMate Pro; admin-ajax `chat/*`)
// ─────────────────────────────────────────────────────────────

/** `chat/settings` and `SERVER_VARIABLES.chat`: never the key. */
export interface ChatSettingsView {
  enabled: boolean;
  has_key: boolean;
  /** Enabled with a key: the page and the top-bar link exist. */
  ready: boolean;
  model: string;
  models: { id: string; label: string }[];
  updated_at: string | null;
  /** This admin accepted the first-use notice. */
  consent: boolean;
  /** This admin's chat grant is live (Connections can revoke it). */
  connected: boolean;
}

export interface ChatSessionSummary {
  id: number;
  title: string;
  model: string;
  status: "idle" | "tools" | "running";
  created_at: string;
  updated_at: string;
}

export type ChatToolKind = "read" | "write" | "destructive";

export type ChatToolStatus =
  | "pending"
  | "ok"
  | "error"
  | "declined"
  | "approval"
  | "not_run";

export type ChatItem =
  | {
      kind: "user";
      text: string;
      images: { media_type: string; bytes: number }[];
    }
  | { kind: "assistant"; text: string }
  | { kind: "thinking"; text: string }
  | {
      kind: "tool";
      id: string;
      name: string;
      input: Record<string, unknown>;
      status: ChatToolStatus;
      summary: string;
      approval_id: number | null;
      approval_url: string | null;
    }
  | { kind: "notice"; tone: "info" | "warning" | "danger"; text: string };

export interface ChatPlanCall {
  id: string;
  name: string;
  kind: ChatToolKind;
  invalid: boolean;
}

export interface ChatSession extends ChatSessionSummary {
  /** `plan`: writes wait for Run; `auto`: only reads, run without asking. */
  state: "plan" | "auto" | null;
  items: ChatItem[];
  plan: ChatPlanCall[] | null;
}

/** One event of a streamed step (`chat/send`, `chat/run`, `chat/retry`). */
export type ChatStreamEvent =
  | { type: "session"; data: { id: number } }
  | { type: "model"; data: { model: string } }
  | { type: "text"; data: { delta: string } }
  | { type: "thinking"; data: { delta: string } }
  | { type: "tool"; data: { id: string; name: string } }
  | { type: "fallback"; data: { from: string; to: string } }
  | {
      type: "tool_result";
      data: {
        id: string;
        name: string;
        status: ChatToolStatus;
        summary: string;
      };
    }
  | { type: "done"; data: { session: ChatSession } }
  | {
      type: "error";
      /** `kept`: the admin's message of this step was saved before it failed. */
      data: { code: string; message: string; hint?: string; kept?: boolean };
    };

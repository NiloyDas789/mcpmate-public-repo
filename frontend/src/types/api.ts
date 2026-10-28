/**
 * Shared type definitions for the McpMate frontend.
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
  /** UTC, ISO 8601, of the first call that reached the site (setup step 6). */
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
  | "not_configured";

export interface ToolGroup {
  id: string;
  label: string;
  source: "core" | "integrations" | "abilities";
  available: boolean;
  unavailable_reason: ToolUnavailableReason;
  /** media: `svg`; stock_photos: whether an Unsplash key is set (never the key) and its app name. */
  options: { svg?: boolean; configured?: boolean; app_name?: string };
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

export type OnboardingStep =
  | "welcome"
  | "check"
  | "safety"
  | "add_ai"
  | "first_call";

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
  /** UTC, `Y-m-d H:i:s`: step 5's baseline for "a new AI app". */
  connect_since: string | null;
  /** The AI app step 5 found. */
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
  };
}

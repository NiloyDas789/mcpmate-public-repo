import type {
  AccessState,
  AjaxError,
  Approval,
  ApprovalStatus,
  ApprovalsPage,
  AuditLogEntry,
  AuditLogFilters,
  AuditLogPage,
  AuditPurgeWindow,
  ChangeDetail,
  ChangesFilters,
  ChangesPage,
  ChecksResponse,
  ClientRevokeResponse,
  ClientsResponse,
  ConnectRequestDetails,
  ConnectionStatusResponse,
  Grant,
  GrantAccess,
  GrantsResponse,
  OnboardingState,
  OnboardingStateResponse,
  OnboardingStatus,
  OnboardingStep,
  Preferences,
  PreferencesResponse,
  RegisteredClient,
  RestEnvelope,
  StatsSummary,
  ToolsResponse,
  ToolsUpdate,
} from "../types/api";

/**
 * Lightweight AJAX helper that sends requests to the WordPress admin-ajax endpoint.
 *
 * The global `SERVER_VARIABLES` object (injected by wp_localize_script) provides
 * the ajax URL, nonce, and route prefix. Its type is declared in types/global.d.ts.
 */

interface ApiResponse<T = unknown> {
  success: boolean;
  data: T;
}

type HttpMethod = "GET" | "POST" | "PUT" | "DELETE";

/**
 * Why an admin-ajax call failed:
 * - `session`: logged out or a stale nonce (admin-ajax answers -1, 0 or 403);
 * - `network`: the site could not be reached (fetch threw);
 * - `server`: a 5xx or a body that is not JSON;
 * - `refused`: the route answered with an error payload (`code`, `message`).
 */
export type AjaxErrorKind = "session" | "network" | "server" | "refused";

export class AjaxRequestError extends Error {
  readonly kind: AjaxErrorKind;

  /** HTTP status, 0 when there was no response. */
  readonly status: number;

  /** The error payload's `code`, when there was one. */
  readonly code: string;

  /** The error payload as sent (`errors` per key for `preferences/update`). */
  readonly details: AjaxError | null;

  constructor(
    message: string,
    kind: AjaxErrorKind,
    status: number,
    details: AjaxError | null = null,
  ) {
    super(message);
    this.name = "AjaxRequestError";
    this.kind = kind;
    this.status = status;
    this.code = details?.code ?? "";
    this.details = details;
  }
}

const SESSION_CODES = ["mcpmate_invalid_nonce", "mcpmate_unauthorized"];

/**
 * Send an AJAX request to the plugin's backend.
 *
 * Note: WordPress admin-ajax.php always uses POST to handle form data,
 * We use the X-HTTP-Method-Override header or rely on the router
 * matching POST requests. For simplicity, all AJAX routes should use POST.
 */
export async function ajaxRequest<T = unknown>(
  route: string,
  method: HttpMethod = "POST",
  body?: Record<string, unknown>,
): Promise<ApiResponse<T>> {
  const formData = new FormData();
  formData.append("action", SERVER_VARIABLES.routePrefix + route);
  formData.append("_ajax_nonce", SERVER_VARIABLES.nonce);
  formData.append("_method", method); // Method override for router

  if (body) {
    Object.entries(body).forEach(([key, value]) => {
      formData.append(
        key,
        typeof value === "object" ? JSON.stringify(value) : String(value),
      );
    });
  }

  let response: Response;
  try {
    response = await fetch(SERVER_VARIABLES.ajaxURL, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
  } catch (error) {
    throw new AjaxRequestError(
      error instanceof Error ? error.message : String(error),
      "network",
      0,
    );
  }

  const contentType = response.headers.get("content-type") || "";
  let payload: ApiResponse<T> | null = null;
  let text = "";

  if (contentType.includes("application/json")) {
    try {
      payload = (await response.json()) as ApiResponse<T>;
    } catch {
      payload = null;
    }
  } else {
    text = (await response.text().catch(() => "")).trim();
  }

  if (!response.ok) {
    if (payload && typeof payload.success === "boolean") {
      return payload;
    }

    throw new AjaxRequestError(
      `Request failed: ${response.status} ${response.statusText}`,
      response.status === 403 || text === "-1" || text === "0"
        ? "session"
        : "server",
      response.status,
    );
  }

  if (!payload) {
    // admin-ajax prints -1 (bad nonce) or 0 (no such action: logged out).
    throw new AjaxRequestError(
      "Request failed: invalid JSON response",
      text === "-1" || text === "0" ? "session" : "server",
      response.status,
    );
  }

  return payload;
}

/**
 * Send a REST API request to the plugin's namespace.
 */
export async function restRequest<T = unknown>(
  endpoint: string,
  method: HttpMethod = "GET",
  body?: Record<string, unknown>,
): Promise<T> {
  const url = `${SERVER_VARIABLES.apiURL.base}/${endpoint}`;

  const headers: HeadersInit = {
    "X-WP-Nonce": SERVER_VARIABLES.restNonce,
    "Content-Type": "application/json",
  };

  const response = await fetch(url, {
    method,
    headers,
    credentials: "same-origin",
    ...(body && { body: JSON.stringify(body) }),
  });

  if (!response.ok) {
    throw new Error(
      `REST request failed: ${response.status} ${response.statusText}`,
    );
  }

  return response.json();
}

/**
 * A REST GET under the plugin's namespace, unwrapped from its envelope;
 * throws the envelope's message on failure.
 */
async function restData<T>(endpoint: string, failure: string): Promise<T> {
  const envelope = await restRequest<RestEnvelope<T>>(endpoint);

  if (!envelope.ok || !envelope.data) {
    throw new Error(envelope.error?.message ?? failure);
  }

  return envelope.data;
}

/**
 * GET /stats: the Dashboard's numbers (cached 5 minutes by the server).
 */
export function fetchStats(): Promise<StatsSummary> {
  return restData<StatsSummary>("stats", "Failed to load the numbers");
}

/**
 * GET /tools: every tool group, with what is turned on site-wide.
 */
export function fetchTools(): Promise<ToolsResponse> {
  return restData<ToolsResponse>("tools", "Failed to load the tools");
}

/**
 * POST /tools: save one change from the Tools page (only what changed).
 * Answers with the whole catalog as saved. Throws an AjaxRequestError, so
 * `requestErrorText()` can say what went wrong: `refused` carries the
 * server's own message (400 `mcpmate_unknown_tool`…).
 */
export async function saveTools(change: ToolsUpdate): Promise<ToolsResponse> {
  let response: Response;
  try {
    response = await fetch(`${SERVER_VARIABLES.apiURL.base}/tools`, {
      method: "POST",
      headers: {
        "X-WP-Nonce": SERVER_VARIABLES.restNonce,
        "Content-Type": "application/json",
      },
      credentials: "same-origin",
      body: JSON.stringify(change),
    });
  } catch {
    throw new AjaxRequestError("Request failed: network error", "network", 0);
  }

  let envelope: RestEnvelope<ToolsResponse> | null = null;
  try {
    envelope = (await response.json()) as RestEnvelope<ToolsResponse>;
  } catch {
    // Not JSON: a PHP fatal or a proxy page.
  }

  if (envelope?.ok && envelope.data) {
    return envelope.data;
  }

  const code = envelope?.error?.code ?? "";
  // A stale REST nonce or a logged-out user (cookie auth).
  const session =
    response.status === 401 || code === "rest_cookie_invalid_nonce";
  const kind = session
    ? "session"
    : !envelope?.error || response.status >= 500
      ? "server"
      : "refused";

  throw new AjaxRequestError(
    envelope?.error?.message ?? "Failed to save the tools",
    kind,
    response.status,
    envelope?.error ? { code, message: envelope.error.message } : null,
  );
}

/**
 * GET /audit-log: one page of the audit log, newest first.
 */
export async function fetchAuditLog(
  page: number,
  perPage: number,
  filters: AuditLogFilters = {},
): Promise<AuditLogPage> {
  const query = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });

  Object.entries(filters).forEach(([key, value]) => {
    if (value) {
      query.set(key, value);
    }
  });

  // `?` with pretty permalinks, `&` when the base is `?rest_route=…`.
  const envelope = await restRequest<RestEnvelope<AuditLogPage>>(
    `audit-log${SERVER_VARIABLES.apiURL.separator}${query.toString()}`,
  );

  if (!envelope.ok || !envelope.data) {
    throw new Error(envelope.error?.message ?? "Failed to load the audit log");
  }

  return envelope.data;
}

/**
 * GET /audit-log?id=: one entry (the drawer, opened from a link); null when
 * it is gone.
 */
export async function fetchAuditEntry(
  id: number,
): Promise<AuditLogEntry | null> {
  return (await fetchAuditLog(1, 1, { id: String(id) })).items[0] ?? null;
}

/**
 * admin-ajax `audit-log/purge-preview`: how many entries the retention
 * purge would remove now (B9).
 */
export function previewAuditPurge(): Promise<
  AuditPurgeWindow & { count: number }
> {
  return ajaxOrThrow("audit-log/purge-preview");
}

/**
 * admin-ajax `audit-log/purge`: run the retention purge now (by age only).
 */
export function purgeAuditLog(): Promise<
  AuditPurgeWindow & { removed: number }
> {
  return ajaxOrThrow("audit-log/purge");
}

/**
 * admin-ajax `changes/list`: one page of change history, newest first.
 */
export function fetchChanges(
  page: number,
  perPage: number,
  filters: ChangesFilters = {},
): Promise<ChangesPage> {
  return ajaxOrThrow<ChangesPage>("changes/list", {
    page,
    per_page: perPage,
    ...Object.fromEntries(
      Object.entries(filters).filter(([, value]) => Boolean(value)),
    ),
  });
}

/**
 * admin-ajax `changes/show`: one change with both snapshots and the diff.
 */
export async function fetchChange(id: number): Promise<ChangeDetail> {
  return (await ajaxOrThrow<{ change: ChangeDetail }>("changes/show", { id }))
    .change;
}

/**
 * admin-ajax `stock-photos/update`: the site owner's Unsplash access key
 * (write-only) and app name; an empty key removes it.
 */
export async function updateStockPhotos(accessKey: string, appName: string) {
  return ajaxOrThrow<{ configured: boolean; app_name: string }>(
    "stock-photos/update",
    { access_key: accessKey, app_name: appName },
  );
}

/**
 * admin-ajax `preferences`: the Settings page's values and what is fixed.
 */
export function fetchPreferences(): Promise<PreferencesResponse> {
  return ajaxOrThrow<PreferencesResponse>("preferences");
}

/**
 * admin-ajax `preferences/update`: save some settings. A refusal carries
 * `details.errors` (key => reason), and nothing was saved.
 */
export function savePreferences(
  changes: Partial<Preferences>,
): Promise<PreferencesResponse> {
  return ajaxOrThrow<PreferencesResponse>("preferences/update", { changes });
}

/**
 * admin-ajax `approvals`: one page of approval requests, newest first.
 * An empty status lists every request.
 */
export async function fetchApprovals(
  status: ApprovalStatus | "",
  page: number,
): Promise<ApprovalsPage> {
  const response = await ajaxRequest<ApprovalsPage | AjaxError>(
    "approvals",
    "POST",
    { status, page },
  );

  if (!response.success) {
    throw new Error((response.data as AjaxError)?.message ?? "Request failed");
  }

  return response.data as ApprovalsPage;
}

/**
 * admin-ajax `approvals/show`: one request with its preview and result.
 */
export async function fetchApproval(id: number): Promise<Approval> {
  const response = await ajaxRequest<{ approval: Approval } | AjaxError>(
    "approvals/show",
    "POST",
    { id },
  );

  if (!response.success) {
    throw new Error((response.data as AjaxError)?.message ?? "Request failed");
  }

  return (response.data as { approval: Approval }).approval;
}

/**
 * admin-ajax `approvals/approve` (runs the operation once) or `approvals/reject`.
 */
export async function decideApproval(
  id: number,
  decision: "approve" | "reject",
): Promise<Approval> {
  const response = await ajaxRequest<{ approval: Approval } | AjaxError>(
    `approvals/${decision}`,
    "POST",
    { id },
  );

  if (!response.success) {
    throw new Error((response.data as AjaxError)?.message ?? "Request failed");
  }

  return (response.data as { approval: Approval }).approval;
}

/**
 * admin-ajax call that throws the server's message on failure, as an
 * AjaxRequestError carrying its code and details.
 */
export async function ajaxOrThrow<T>(
  route: string,
  body?: Record<string, unknown>,
): Promise<T> {
  const response = await ajaxRequest<T | AjaxError>(route, "POST", body);

  if (!response.success) {
    const details = (response.data as AjaxError | null) ?? null;

    throw new AjaxRequestError(
      details?.message ?? "Request failed",
      SESSION_CODES.includes(details?.code ?? "") ? "session" : "refused",
      0,
      details,
    );
  }

  return response.data as T;
}

/**
 * admin-ajax `onboarding/state`: setup progress and what each step waits for.
 */
export function fetchOnboardingState(): Promise<OnboardingStateResponse> {
  return ajaxOrThrow<OnboardingStateResponse>("onboarding/state");
}

/**
 * admin-ajax `onboarding/update`: save the step, status or found AI app.
 */
export async function updateOnboarding(changes: {
  step?: OnboardingStep;
  status?: Exclude<OnboardingStatus, "not_started">;
  grant_id?: string;
}): Promise<OnboardingState> {
  return (
    await ajaxOrThrow<{ onboarding: OnboardingState }>(
      "onboarding/update",
      changes,
    )
  ).onboarding;
}

/**
 * admin-ajax `onboarding/checks`: run every site check now.
 */
export function runChecks(): Promise<ChecksResponse> {
  return ajaxOrThrow<ChecksResponse>("onboarding/checks");
}

/**
 * admin-ajax `connection/status`: the MCP URL and OAuth issuer.
 */
export function fetchConnection(): Promise<ConnectionStatusResponse> {
  return ajaxOrThrow<ConnectionStatusResponse>("connection/status");
}

/**
 * admin-ajax `connection/approve` or `connection/reject` for an AI app's
 * authorization request. Returns the app's redirect URI (with the code, or
 * `error=access_denied`) to send the browser to.
 */
export function decideConnection(
  request: Pick<ConnectRequestDetails, "request">,
  decision: "approve" | "reject",
  readOnly = false,
): Promise<{ redirect_url: string; grant?: Grant }> {
  const id = { request: request.request };

  return ajaxOrThrow<{ redirect_url: string; grant?: Grant }>(
    `connection/${decision}`,
    decision === "approve" ? { ...id, read_only: readOnly } : id,
  );
}

/**
 * admin-ajax `grants/list`: the Connected AI apps.
 */
export function fetchGrants(): Promise<GrantsResponse> {
  return ajaxOrThrow<GrantsResponse>("grants/list");
}

/**
 * admin-ajax `grants/revoke`: revoke one AI app (denied at once).
 */
export async function revokeGrant(grantId: string): Promise<Grant> {
  return (
    await ajaxOrThrow<{ grant: Grant }>("grants/revoke", { grant_id: grantId })
  ).grant;
}

/**
 * admin-ajax `grants/revoke-all`: revoke every AI app.
 */
export async function revokeAllGrants(): Promise<number> {
  return (await ajaxOrThrow<{ revoked: number }>("grants/revoke-all")).revoked;
}

/**
 * admin-ajax `grants/update`: change an AI app's access level. Custom
 * access takes one or more catalog tool names.
 */
export async function updateGrantAccess(
  grantId: string,
  access: GrantAccess,
  tools: string[] = [],
): Promise<Grant> {
  return (
    await ajaxOrThrow<{ grant: Grant }>("grants/update", {
      grant_id: grantId,
      access,
      tools,
    })
  ).grant;
}

/**
 * admin-ajax `clients/list`: the OAuth clients registered with this site.
 */
export function fetchClients(): Promise<ClientsResponse> {
  return ajaxOrThrow<ClientsResponse>("clients/list");
}

/**
 * admin-ajax `clients/revoke`: refuse a client from now on and revoke every
 * AI app it connected.
 */
export function revokeClient(clientKey: string): Promise<ClientRevokeResponse> {
  return ajaxOrThrow<ClientRevokeResponse>("clients/revoke", {
    client_key: clientKey,
  });
}

/**
 * admin-ajax `clients/restore`: let a revoked client connect again (its
 * AI apps are approved again like new ones).
 */
export async function restoreClient(
  clientKey: string,
): Promise<RegisteredClient> {
  return (
    await ajaxOrThrow<{ client: RegisteredClient }>("clients/restore", {
      client_key: clientKey,
    })
  ).client;
}

/**
 * admin-ajax `access/pause` or `access/resume`: stop every AI app at once,
 * or let them back in. Nothing is revoked.
 */
export async function setAccessPaused(paused: boolean): Promise<AccessState> {
  return (
    await ajaxOrThrow<{ access: AccessState }>(
      paused ? "access/pause" : "access/resume",
    )
  ).access;
}

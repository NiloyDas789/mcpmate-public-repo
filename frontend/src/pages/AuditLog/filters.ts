import { __, sprintf } from "@wordpress/i18n";
import type { StatusTone } from "@components/ui/StatusDot";
import type { FilterValues } from "@components/ui/FilterBar";
import type { TableSort } from "@components/ui/Table";
import type {
  AuditLogEntry,
  AuditLogFilters,
  AuditResult,
} from "../../types/api";
import {
  idFromParam,
  isDay,
  pageFromParam,
  periodFromParam,
  periodRange,
  shortDateTime,
  withParams,
  type Period,
} from "../Activity/logic";

/**
 * The Audit log's state in the hash query (`#/activity?q=&tool=&mcp_tool=&
 * user=&grant_id=&result=&period=&from=&to=&sort=&order=&page=&entry=`), so
 * a filtered view can be shared or reloaded. Older links (`#/audit-log?
 * tool=connection&grant_id=g_…` from the setup wizard) keep working.
 * Invalid values are ignored.
 */

export const DEFAULT_PERIOD: Period = "7d";

export const DEFAULT_SORT: TableSort = { key: "date", order: "descending" };

/** The Tool select's value for a filter on the internal tool (`connection`). */
const INTERNAL = "internal:";

export const RESULTS: AuditResult[] = ["ok", "error", "pending_approval"];

export interface AuditView {
  search: string;
  /** Internal tool (`connection`, or `cli` from an old link). */
  tool: string;
  mcpTool: string;
  userId: string;
  grantId: string;
  result: AuditResult | "";
  period: Period;
  from: string;
  to: string;
  sort: TableSort;
  page: number;
  entry: number | null;
}

const pick = (value: string | null, pattern: RegExp) =>
  value && pattern.test(value) ? value : "";

export function auditViewFromQuery(query: string): AuditView {
  const params = new URLSearchParams(query);
  const result = params.get("result") ?? "";
  const sortKey = params.get("sort") === "duration" ? "duration" : "date";

  return {
    search: (params.get("q") ?? "").slice(0, 100),
    tool: pick(params.get("tool"), /^[A-Za-z0-9_.:-]{1,100}$/),
    mcpTool: pick(params.get("mcp_tool"), /^[a-z0-9_]{1,60}$/),
    userId: pick(params.get("user"), /^[1-9]\d{0,11}$/),
    grantId: pick(params.get("grant_id"), /^g_[A-Za-z0-9_-]{22}$/),
    result: (RESULTS as string[]).includes(result)
      ? (result as AuditResult)
      : "",
    period: periodFromParam(params.get("period"), DEFAULT_PERIOD),
    from: isDay(params.get("from") ?? "") ? (params.get("from") as string) : "",
    to: isDay(params.get("to") ?? "") ? (params.get("to") as string) : "",
    sort: {
      key: sortKey,
      order: params.get("order") === "asc" ? "ascending" : "descending",
    },
    page: pageFromParam(params.get("page")),
    entry: idFromParam(params.get("entry")),
  };
}

/** What GET /audit-log gets for this view, with the period resolved now. */
export function auditApiFilters(
  view: AuditView,
  now: number = Date.now(),
): AuditLogFilters {
  return {
    tool: view.tool || undefined,
    mcp_tool: view.mcpTool || undefined,
    user_id: view.userId || undefined,
    grant_id: view.grantId || undefined,
    result: view.result || undefined,
    search: view.search.trim() || undefined,
    ...periodRange(view.period, { from: view.from, to: view.to }, now),
    order_by: view.sort.key === "duration" ? "duration_ms" : "created_at",
    order: view.sort.order === "ascending" ? "asc" : "desc",
  };
}

/** The FilterBar's values: the default period is '' (no filter). */
export function filterValues(view: AuditView): FilterValues {
  return {
    q: view.search,
    tool: view.mcpTool || (view.tool ? `${INTERNAL}${view.tool}` : ""),
    user: view.userId,
    grant_id: view.grantId,
    result: view.result,
    period: view.period === DEFAULT_PERIOD ? "" : view.period,
    from: view.from,
    to: view.to,
  };
}

/**
 * The hash query after a FilterBar change: filters written, back to page
 * one, sort and an open entry kept.
 */
export function queryForFilters(query: string, values: FilterValues): string {
  const tool = values.tool ?? "";
  const custom = values.period === "custom";

  return withParams(query, {
    q: values.q?.trim() ? values.q : null,
    tool: tool.startsWith(INTERNAL) ? tool.slice(INTERNAL.length) : null,
    mcp_tool: tool && !tool.startsWith(INTERNAL) ? tool : null,
    user: values.user,
    grant_id: values.grant_id,
    result: values.result,
    period: values.period,
    from: custom ? values.from : null,
    to: custom ? values.to : null,
    page: null,
  });
}

export function queryForSort(query: string, sort: TableSort): string {
  const isDefault =
    sort.key === DEFAULT_SORT.key && sort.order === DEFAULT_SORT.order;

  return withParams(query, {
    sort: isDefault || sort.key === "date" ? null : sort.key,
    order: isDefault ? null : sort.order === "ascending" ? "asc" : "desc",
    page: null,
  });
}

/** Filters that are on, besides the default period. */
export function hasFilters(view: AuditView): boolean {
  return Boolean(
    view.search.trim() ||
    view.tool ||
    view.mcpTool ||
    view.userId ||
    view.grantId ||
    view.result ||
    view.period !== DEFAULT_PERIOD,
  );
}

/** The Tool select: "Setup and connection", and the internal tool of an old link. */
export function toolOptions(
  catalog: string[],
  view: AuditView,
): { value: string; label: string }[] {
  const options = [
    {
      value: `${INTERNAL}connection`,
      label: __("Setup and connection", "mcpmate"),
    },
    ...catalog.map((name) => ({ value: name, label: name })),
  ];
  if (view.mcpTool && !catalog.includes(view.mcpTool)) {
    options.push({ value: view.mcpTool, label: view.mcpTool });
  }
  if (view.tool && view.tool !== "connection") {
    options.push({ value: `${INTERNAL}${view.tool}`, label: view.tool });
  }

  return options;
}

// ── Rows ────────────────────────────────────────

export function resultLabel(result: AuditResult): string {
  switch (result) {
    case "ok":
      return __("Succeeded", "mcpmate");
    case "error":
      return __("Failed", "mcpmate");
    case "pending_approval":
      return __("Waiting for approval", "mcpmate");
  }
}

export function resultTone(result: AuditResult): StatusTone {
  return result === "ok"
    ? "success"
    : result === "error"
      ? "danger"
      : "warning";
}

/** The tool a row names: the MCP tool, else the internal one (`connection`). */
export function entryTool(entry: AuditLogEntry): string {
  return entry.mcp_tool || entry.tool;
}

/** "elementor_save · 26 Oct 10:42": the drawer's title, and its View button's name. */
export function entryTitle(entry: AuditLogEntry): string {
  return sprintf(
    /* translators: 1: tool name, 2: date and time. */
    __("%1$s · %2$s", "mcpmate"),
    entryTool(entry),
    shortDateTime(entry.created_at),
  );
}

/**
 * Who called: the AI app's name, "wp-admin" for a site admin's own
 * request, "System" for the plugin itself (cron, internal).
 */
export function entryApp(entry: AuditLogEntry): string {
  if (entry.grant_id) {
    return entry.client_name ?? __("Removed AI app", "mcpmate");
  }

  return entry.auth === "cookie"
    ? __("wp-admin", "mcpmate")
    : __("System", "mcpmate");
}

export function entryUser(entry: AuditLogEntry): string {
  if (entry.user_login) {
    return entry.user_login;
  }

  return entry.user_id > 0
    ? sprintf(
        /* translators: %d: a user ID that no longer exists. */
        __("Deleted user #%d", "mcpmate"),
        entry.user_id,
      )
    : "—";
}

const SUMMARY_LENGTH = 60;

function shortValue(value: unknown): string {
  if (value === null || value === undefined) {
    return String(value);
  }
  if (typeof value === "string") {
    return value;
  }
  if (Array.isArray(value)) {
    return sprintf(
      /* translators: %d: number of items in a list. */
      __("%d items", "mcpmate"),
      value.length,
    );
  }
  if (typeof value === "object") {
    return "{…}";
  }

  return String(value);
}

/**
 * "post_id 41, patches 3 items": the first two key and value pairs of the
 * (already redacted) arguments, one line, at most 60 characters.
 */
export function argsSummary(args: unknown): string {
  if (!args || typeof args !== "object" || Array.isArray(args)) {
    return "—";
  }
  const pairs = Object.entries(args as Record<string, unknown>).slice(0, 2);
  if (pairs.length === 0) {
    return "—";
  }
  const text = pairs
    .map(([key, value]) => `${key} ${shortValue(value)}`.replace(/\s+/g, " "))
    .join(", ");

  return text.length > SUMMARY_LENGTH
    ? `${text.slice(0, SUMMARY_LENGTH - 1)}…`
    : text;
}

/** The post a target names (`post:41`), for Open in editor. */
export function targetPostId(target: string): number | null {
  const match = /^(?:post|page):(\d+)$/.exec(target);

  return match ? Number(match[1]) : null;
}

import { __, _n, sprintf } from "@wordpress/i18n";
import type { StatusTone } from "@components/ui/StatusDot";
import { isLiveGrant } from "@services/queries";
import { formatDate } from "@utils/format";
import type {
  AuditLogEntry,
  AuditResult,
  EnvironmentCheck,
  Grant,
} from "../../types/api";

/** The Dashboard's decisions (docs/ui.md §5.1), kept pure for tests. */

export const RECENT_QUERY_KEY = ["audit-log", "recent"];

export const DASHBOARD_CHECKS_QUERY_KEY = ["dashboard-checks"];

/** How many calls Recent activity lists, and how many log rows it reads to find them. */
export const RECENT_COUNT = 5;
export const RECENT_SCAN = 50;

/** The prompt the empty Recent activity suggests. */
export const FIRST_PROMPT = () =>
  __("Give me a health summary of my WordPress site.", "mcpmate");

/** The live app used most recently, with when. */
export function lastUsed(grants: Grant[]): { grant: Grant; at: string } | null {
  let latest: { grant: Grant; at: string } | null = null;

  for (const grant of grants.filter(isLiveGrant)) {
    if (
      grant.last_used_at &&
      (!latest || Date.parse(grant.last_used_at) > Date.parse(latest.at))
    ) {
      latest = { grant, at: grant.last_used_at };
    }
  }

  return latest;
}

/** Calls AI apps made (an MCP call, `auth` oauth, that reached a tool), newest first. */
export function recentCalls(
  items: AuditLogEntry[],
  count = RECENT_COUNT,
): AuditLogEntry[] {
  return items
    .filter((item) => item.auth === "oauth" && item.mcp_tool !== "")
    .slice(0, count);
}

export function resultStatus(result: AuditResult): {
  tone: StatusTone;
  text: string;
} {
  switch (result) {
    case "ok":
      return { tone: "success", text: __("Succeeded", "mcpmate") };
    case "error":
      return { tone: "danger", text: __("Failed", "mcpmate") };
    default:
      return {
        tone: "warning",
        text: __("Waiting for approval", "mcpmate"),
      };
  }
}

/** "30 reads, 12 changes". */
export function callsSplit(reads: number, writes: number): string {
  return sprintf(
    /* translators: 1: "30 reads", 2: "12 changes". */
    __("%1$s, %2$s", "mcpmate"),
    sprintf(
      /* translators: %d: number of read calls. */
      _n("%d read", "%d reads", reads, "mcpmate"),
      reads,
    ),
    sprintf(
      /* translators: %d: number of calls that changed something. */
      _n("%d change", "%d changes", writes, "mcpmate"),
      writes,
    ),
  );
}

/**
 * "X of Y passed": Y counts checks that pass, warn or fail; `info` and
 * `later` are listed but not counted.
 */
export function checksTally(checks: EnvironmentCheck[]): {
  passed: number;
  counted: number;
  attention: number;
} {
  const counted = checks.filter((check) =>
    ["pass", "warn", "fail"].includes(check.status),
  );

  return {
    passed: counted.filter((check) => check.status === "pass").length,
    counted: counted.length,
    attention: counted.filter((check) => check.status !== "pass").length,
  };
}

export function checksSummaryText(checks: EnvironmentCheck[]): string {
  const { passed, counted } = checksTally(checks);

  return sprintf(
    /* translators: 1: checks passed, 2: checks counted. */
    __("%1$d of %2$d passed", "mcpmate"),
    passed,
    counted,
  );
}

export function checkStatus(status: EnvironmentCheck["status"]): {
  tone: StatusTone;
  text: string;
} {
  switch (status) {
    case "pass":
      return { tone: "success", text: __("Passed", "mcpmate") };
    case "warn":
      return { tone: "warning", text: __("Warning", "mcpmate") };
    case "fail":
      return { tone: "danger", text: __("Failed check", "mcpmate") };
    case "later":
      return { tone: "neutral", text: __("Checked later", "mcpmate") };
    default:
      return { tone: "neutral", text: __("Information", "mcpmate") };
  }
}

/** "10:42" today, "24 Oct, 10:42" before, in the site's timezone. */
export function formatMoment(iso: string, now: Date = new Date()): string {
  const sameDay = new Date(iso).toDateString() === now.toDateString();

  return formatDate(
    iso,
    sameDay
      ? { hour: "numeric", minute: "2-digit" }
      : { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" },
  );
}

import { __, _n, sprintf } from "@wordpress/i18n";
import { formatDate, formatNumber } from "@utils/format";

/**
 * What the Audit log and Change history tabs share (docs/ui.md §5.4):
 * the Period filter in the site's timezone, dates, durations, and the
 * hash-query helpers that keep every filter in the URL.
 */

// ── Period ──────────────────────────────────────

export type Period = "24h" | "7d" | "30d" | "90d" | "all" | "custom";

const HOURS: Partial<Record<Period, number>> = {
  "24h": 24,
  "7d": 24 * 7,
  "30d": 24 * 30,
  "90d": 24 * 90,
};

export function periodLabel(period: Period): string {
  switch (period) {
    case "24h":
      return __("Last 24 hours", "mcpmate");
    case "7d":
      return __("Last 7 days", "mcpmate");
    case "30d":
      return __("Last 30 days", "mcpmate");
    case "90d":
      return __("Last 90 days", "mcpmate");
    case "all":
      return __("All time", "mcpmate");
    case "custom":
      return __("Custom dates", "mcpmate");
  }
}

/**
 * The Period select: the page's default is the empty value (so it never
 * counts as a filter), the rest in order.
 */
export function periodOptions(
  defaultPeriod: Period,
): { value: string; label: string }[] {
  return (["24h", "7d", "30d", "90d", "all", "custom"] as Period[])
    .filter((period) => period !== defaultPeriod)
    .map((period) => ({ value: period, label: periodLabel(period) }));
}

export function periodFromParam(
  value: string | null,
  fallback: Period,
): Period {
  return value && value in HOURS
    ? (value as Period)
    : value === "all" || value === "custom"
      ? value
      : fallback;
}

/** `YYYY-MM-DD` that is a real date. */
export function isDay(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) {
    return false;
  }
  const date = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3]));

  return (
    date.getUTCDate() === +match[3] && date.getUTCMonth() === +match[2] - 1
  );
}

const isoSeconds = (ms: number) =>
  new Date(ms).toISOString().replace(/\.\d{3}Z$/, "Z");

/** Minutes the zone is ahead of UTC at `ms`. */
function zoneOffset(ms: number, timeZone: string): number {
  const offset = /^([+-])(\d{2}):?(\d{2})$/.exec(timeZone);
  if (offset) {
    return (offset[1] === "-" ? -1 : 1) * (+offset[2] * 60 + +offset[3]);
  }

  try {
    const parts = Object.fromEntries(
      new Intl.DateTimeFormat("en-US", {
        timeZone,
        hourCycle: "h23",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
      })
        .formatToParts(new Date(ms))
        .map((part) => [part.type, part.value]),
    );
    const wall = Date.UTC(
      +parts.year,
      +parts.month - 1,
      +parts.day,
      +parts.hour,
      +parts.minute,
      +parts.second,
    );

    return Math.round((wall - Math.floor(ms / 1000) * 1000) / 60000);
  } catch {
    // Not a zone Intl knows: treat the dates as UTC.
    return 0;
  }
}

/**
 * The start (or last second) of a day in the site's timezone, as an RFC
 * 3339 UTC time the server compares with its UTC rows.
 */
export function siteDayBoundary(
  day: string,
  endOfDay: boolean,
  timeZone: string = SERVER_VARIABLES?.timeZone || "UTC",
): string {
  const [year, month, date] = day.split("-").map(Number);
  const wall = endOfDay
    ? Date.UTC(year, month - 1, date, 23, 59, 59)
    : Date.UTC(year, month - 1, date);
  // Twice, so a day that starts on a DST change lands on the right side.
  let utc = wall - zoneOffset(wall, timeZone) * 60000;
  utc = wall - zoneOffset(utc, timeZone) * 60000;

  return isoSeconds(utc);
}

/**
 * The `from` / `to` a period means now. A custom range uses whichever of
 * its two days is a real date.
 */
export function periodRange(
  period: Period,
  custom: { from: string; to: string },
  now: number = Date.now(),
): { from?: string; to?: string } {
  const hours = HOURS[period];
  if (hours) {
    return { from: isoSeconds(now - hours * 3600_000) };
  }
  if (period !== "custom") {
    return {};
  }

  return {
    ...(isDay(custom.from) && { from: siteDayBoundary(custom.from, false) }),
    ...(isDay(custom.to) && { to: siteDayBoundary(custom.to, true) }),
  };
}

/** Both days given, and "to" before "from". */
export function customRangeError(from: string, to: string): string {
  return isDay(from) && isDay(to) && from > to
    ? __("“To” must be on or after “From”.", "mcpmate")
    : "";
}

// ── Dates, durations, names ─────────────────────

/** The site's timezone, for captions: "Times in Asia/Dhaka." */
export function siteTimeZone(): string {
  return SERVER_VARIABLES?.timeZone || "UTC";
}

/** "26 Oct 10:42", for a list. */
export function shortDateTime(iso: string): string {
  return formatDate(iso, {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/** "26 Oct 2026, 10:42:07 (Asia/Dhaka)", for a drawer. */
export function fullDateTime(iso: string): string {
  return sprintf(
    /* translators: 1: date and time, 2: the site's timezone, e.g. Asia/Dhaka. */
    __("%1$s (%2$s)", "mcpmate"),
    formatDate(iso, {
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    }),
    siteTimeZone(),
  );
}

/** "640 ms", "1.2 s"; "—" when unknown. */
export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) {
    return "—";
  }
  if (ms < 1000) {
    return sprintf(
      /* translators: %s: milliseconds. */
      __("%s ms", "mcpmate"),
      formatNumber(ms),
    );
  }

  return sprintf(
    /* translators: %s: seconds, e.g. 1.2. */
    __("%s s", "mcpmate"),
    formatNumber(ms / 1000, { maximumFractionDigits: 1 }),
  );
}

/** "24 entries match." / "1 entry matches." */
export function matchesText(
  count: number,
  noun: "entries" | "changes",
): string {
  return noun === "entries"
    ? sprintf(
        /* translators: %s: number of audit log entries. */
        _n("%s entry matches.", "%s entries match.", count, "mcpmate"),
        formatNumber(count),
      )
    : sprintf(
        /* translators: %s: number of changes. */
        _n("%s change matches.", "%s changes match.", count, "mcpmate"),
        formatNumber(count),
      );
}

// ── Hash query ──────────────────────────────────

/**
 * A copy of `query` with `changes` applied: '' or null removes a key.
 * Returns the `?…` string ('' when empty).
 */
export function withParams(
  query: string,
  changes: Record<string, string | null | undefined>,
): string {
  const next = new URLSearchParams(query);
  for (const [key, value] of Object.entries(changes)) {
    if (value) {
      next.set(key, value);
    } else {
      next.delete(key);
    }
  }
  const encoded = next.toString();

  return encoded ? `?${encoded}` : "";
}

/** A positive page number from the query; 1 otherwise. */
export function pageFromParam(value: string | null): number {
  const page = Number(value);

  return Number.isInteger(page) && page > 1 ? page : 1;
}

/** A row ID from the query (`?entry=`, `?change=`); null otherwise. */
export function idFromParam(value: string | null): number | null {
  const id = Number(value);

  return value && /^\d{1,12}$/.test(value) && id > 0 ? id : null;
}

/** The edit screen of a post, from an audit target or a change (`post:41`). */
export function postEditUrl(id: number): string {
  return SERVER_VARIABLES.ajaxURL.replace(
    /admin-ajax\.php$/,
    `post.php?post=${id}&action=edit`,
  );
}

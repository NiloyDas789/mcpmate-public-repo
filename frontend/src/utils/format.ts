/**
 * Numbers, dates and directions for the admin app, from WordPress's locale
 * (`SERVER_VARIABLES.lang`, `rtl`) through `Intl` (docs/ui.md §4, rules 6–7).
 */

/** `en_US` → `en-US`; falls back to the browser's language. */
export function intlLocale(): string {
  const lang = (SERVER_VARIABLES?.lang ?? "").replace(/_/g, "-");

  try {
    return Intl.getCanonicalLocales(lang)[0] ?? "en-US";
  } catch {
    return "en-US";
  }
}

export function isRtl(): boolean {
  return Boolean(SERVER_VARIABLES?.rtl);
}

export function formatNumber(
  value: number,
  options?: Intl.NumberFormatOptions,
): string {
  return new Intl.NumberFormat(intlLocale(), options).format(value);
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ["year", 365 * 24 * 3600],
  ["month", 30 * 24 * 3600],
  ["week", 7 * 24 * 3600],
  ["day", 24 * 3600],
  ["hour", 3600],
  ["minute", 60],
];

/**
 * "5 min ago", "in 9 min", "now": the largest unit that fits, rounded
 * towards zero so "59 s" stays "now" rather than jumping to "1 min".
 */
export function formatRelative(date: Date, now: Date = new Date()): string {
  const seconds = (date.getTime() - now.getTime()) / 1000;
  const format = new Intl.RelativeTimeFormat(intlLocale(), {
    numeric: "auto",
    style: "short",
  });

  for (const [unit, size] of UNITS) {
    if (Math.abs(seconds) >= size) {
      return format.format(Math.trunc(seconds / size), unit);
    }
  }

  return format.format(0, "second");
}

/**
 * A date in the admin's language and the site's timezone
 * (`SERVER_VARIABLES.timeZone`); '' for an invalid date. A timezone that is
 * an offset ("+05:30") rather than a zone name falls back to the browser's.
 */
export function formatDate(
  value: string | Date,
  options: Intl.DateTimeFormatOptions,
): string {
  const date = typeof value === "string" ? new Date(value) : value;

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  try {
    return new Intl.DateTimeFormat(intlLocale(), {
      ...options,
      timeZone: SERVER_VARIABLES?.timeZone || undefined,
    }).format(date);
  } catch {
    return new Intl.DateTimeFormat(intlLocale(), options).format(date);
  }
}

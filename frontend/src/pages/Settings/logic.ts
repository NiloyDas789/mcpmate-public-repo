import { __, _n, sprintf } from "@wordpress/i18n";
import { formatNumber } from "@utils/format";
import type {
  PreferenceKey,
  Preferences,
  PreferencesResponse,
} from "../../types/api";

/**
 * The Settings page's sections, what each one saves, and the checks it
 * runs before it sends: the same as `Preferences::update()` on the server
 * (backend/app/Services/Settings/Preferences.php), with the same words, so
 * a value the page accepts is one the server accepts.
 */

export const daysText = (days: number) =>
  sprintf(
    /* translators: %s: number of days. */
    _n("%s day", "%s days", days, "mcpmate"),
    formatNumber(days),
  );

export const minutesText = (minutes: number) =>
  sprintf(
    /* translators: %s: number of minutes. */
    _n("%s minute", "%s minutes", minutes, "mcpmate"),
    formatNumber(minutes),
  );

export const SECTIONS = ["access", "logging", "advanced"] as const;

export type SectionId = (typeof SECTIONS)[number];

/**
 * The keys each section's Save changes sends. In Access and safety, who
 * may approve AI apps is locked to administrators (D5), and the per-app
 * rate limit is saved (docs/mcp-in-plugin.md §4.6). Token lifetimes and
 * client registration are saved on Connections (CONNECTION_KEYS), so one
 * value has one place (docs/ui.md D10).
 */
export const SECTION_KEYS: Record<SectionId, PreferenceKey[]> = {
  access: ["rate_limit"],
  logging: [
    "log_reads",
    "audit_retention_days",
    "read_retention_days",
    "change_history",
    "change_history_types",
    "change_retention_days",
  ],
  advanced: ["delete_data_on_uninstall"],
};

/** Connections → Token lifetimes and client registration (§4.3, §4.5). */
export const CONNECTION_KEYS: PreferenceKey[] = [
  "open_registration",
  "access_token_minutes",
  "refresh_token_days",
];

/** The allowed days per retention setting (Preferences::SCHEMA). */
export const RETENTION_CHOICES = {
  audit_retention_days: [30, 60, 90, 180, 365],
  read_retention_days: [7, 14, 30, 90],
  change_retention_days: [7, 14, 30, 90],
} as const;

export type RetentionKey = keyof typeof RETENTION_CHOICES;

/** OAuth token lifetimes: minutes per access token, days per approval. */
export const LIFETIME_CHOICES = {
  access_token_minutes: [5, 15, 30, 60],
  refresh_token_days: [7, 30, 60, 90],
} as const;

export type LifetimeKey = keyof typeof LIFETIME_CHOICES;

/** Requests per minute for each AI app (Preferences::SCHEMA). */
export const RATE_LIMIT_CHOICES = [30, 60, 120, 300, 600, 1000] as const;

const BOOLEANS: PreferenceKey[] = [
  "log_reads",
  "change_history",
  "open_registration",
  "delete_data_on_uninstall",
];

const CHOICES: Partial<Record<PreferenceKey, readonly number[]>> = {
  ...RETENTION_CHOICES,
  ...LIFETIME_CHOICES,
  rate_limit: RATE_LIMIT_CHOICES,
};

export function sectionFromParam(value: string | null): SectionId | null {
  return (SECTIONS as readonly string[]).includes(value ?? "")
    ? (value as SectionId)
    : null;
}

function same(a: unknown, b: unknown): boolean {
  if (Array.isArray(a) && Array.isArray(b)) {
    return (
      a.length === b.length &&
      [...a].sort().join("\n") === [...b].sort().join("\n")
    );
  }

  return a === b;
}

/** The keys of `keys` whose draft differs from what is saved. */
export function changedKeys(
  saved: Preferences,
  draft: Preferences,
  keys: PreferenceKey[],
): PreferenceKey[] {
  return keys.filter((key) => !same(saved[key], draft[key]));
}

/** Only what changed, as `preferences/update {changes}` takes it. */
export function changesFor(
  saved: Preferences,
  draft: Preferences,
  keys: PreferenceKey[],
): Partial<Preferences> {
  return Object.fromEntries(
    changedKeys(saved, draft, keys).map((key) => [key, draft[key]]),
  ) as Partial<Preferences>;
}

/** The window a filter sets in code, else the draft's. */
function effectiveDays(
  draft: Preferences,
  key: RetentionKey,
  fixed: PreferencesResponse["fixed"],
): number {
  const set = fixed[key];

  return set?.reason === "filtered" ? Number(set.value) : draft[key];
}

/**
 * Why each changed value would be refused, keyed by setting. A rule
 * between two values is reported on the one that changed, as the server
 * does, so a pair that was saved before never blocks another setting.
 */
export function validate(
  draft: Preferences,
  changed: PreferenceKey[],
  fixed: PreferencesResponse["fixed"],
  types: string[],
): Partial<Record<PreferenceKey, string>> {
  const errors: Partial<Record<PreferenceKey, string>> = {};

  for (const key of changed) {
    const value = draft[key];

    if (BOOLEANS.includes(key) && typeof value !== "boolean") {
      errors[key] = __("Must be true or false.", "mcpmate");
    } else if (CHOICES[key]) {
      const choices = CHOICES[key];
      if (!choices.includes(value as number)) {
        errors[key] = sprintf(
          /* translators: %s: comma-separated list of allowed numbers. */
          __("Must be one of: %s.", "mcpmate"),
          choices.join(", "),
        );
      }
    } else if (key === "change_history_types") {
      const list = value as string[];
      if (!Array.isArray(list) || list.some((type) => !types.includes(type))) {
        errors[key] = sprintf(
          /* translators: %s: comma-separated list of allowed values. */
          __("Each item must be one of: %s.", "mcpmate"),
          types.join(", "),
        );
      }
    }
  }

  const changes = (key: PreferenceKey) =>
    changed.includes(key) && errors[key] === undefined;
  const audit = effectiveDays(draft, "audit_retention_days", fixed);
  const reads = effectiveDays(draft, "read_retention_days", fixed);

  // Below 1 day a window keeps every row.
  if (audit >= 1 && reads >= 1 && reads > audit) {
    if (changes("read_retention_days")) {
      errors.read_retention_days = sprintf(
        /* translators: %d: days the audit log keeps entries. */
        __(
          "Read calls can't be kept longer than the audit log (%d days).",
          "mcpmate",
        ),
        audit,
      );
    } else if (changes("audit_retention_days")) {
      errors.audit_retention_days = sprintf(
        /* translators: %d: days read calls are kept. */
        __(
          "Keep the audit log at least as long as read calls (%d days).",
          "mcpmate",
        ),
        reads,
      );
    }
  }

  if (draft.change_history && draft.change_history_types.length === 0) {
    const message = __(
      "Choose at least one kind of change to record, or turn change history off.",
      "mcpmate",
    );
    if (changes("change_history_types")) {
      errors.change_history_types = message;
    } else if (changes("change_history")) {
      errors.change_history = message;
    }
  }

  return errors;
}

/** The control a setting's error belongs to (change history's sits on its types). */
export function fieldFor(key: PreferenceKey): PreferenceKey {
  return key === "change_history" ? "change_history_types" : key;
}

/** The DOM id of a setting's control, for labels and focus. */
export function controlId(key: PreferenceKey): string {
  return `el-setting-${fieldFor(key).replace(/_/g, "-")}`;
}

/** The first invalid setting in page order (Settings', unless given). */
export function firstInvalid(
  errors: Partial<Record<PreferenceKey, string>>,
  order: PreferenceKey[] = SECTIONS.flatMap((section) => SECTION_KEYS[section]),
): PreferenceKey | null {
  return order.find((key) => errors[key] !== undefined) ?? null;
}

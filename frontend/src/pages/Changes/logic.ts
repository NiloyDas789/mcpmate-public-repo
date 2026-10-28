import { __, sprintf } from "@wordpress/i18n";
import type { DiffField } from "@components/ui/DiffView";
import type { FilterValues } from "@components/ui/FilterBar";
import type {
  ChangeAction,
  ChangeDetail,
  ChangeEntry,
  ChangesFilters,
} from "../../types/api";
import {
  idFromParam,
  isDay,
  pageFromParam,
  periodFromParam,
  periodRange,
  withParams,
  type Period,
} from "../Activity/logic";

/**
 * Change history's state in the hash query (`#/activity/changes?q=&tool=&
 * action=&type=&grant_id=&audit_id=&period=&from=&to=&page=&change=`), and
 * how a change reads: its action, object and fields (docs/ui.md §5.4.2).
 */

export const DEFAULT_PERIOD: Period = "30d";

export const ACTIONS: ChangeAction[] = ["create", "update", "trash", "delete"];

export interface ChangesView {
  search: string;
  mcpTool: string;
  action: ChangeAction | "";
  type: string;
  grantId: string;
  /** The call that made the changes (from an audit log entry). */
  auditId: string;
  period: Period;
  from: string;
  to: string;
  page: number;
  change: number | null;
}

const pick = (value: string | null, pattern: RegExp) =>
  value && pattern.test(value) ? value : "";

export function changesViewFromQuery(query: string): ChangesView {
  const params = new URLSearchParams(query);
  const action = params.get("action") ?? "";

  return {
    search: (params.get("q") ?? "").slice(0, 100),
    mcpTool: pick(params.get("tool"), /^[a-z0-9_]{1,60}$/),
    action: (ACTIONS as string[]).includes(action)
      ? (action as ChangeAction)
      : "",
    type: pick(params.get("type"), /^[a-z_]{1,20}$/),
    grantId: pick(params.get("grant_id"), /^g_[A-Za-z0-9_-]{22}$/),
    auditId: pick(params.get("audit_id"), /^[1-9]\d{0,11}$/),
    period: periodFromParam(params.get("period"), DEFAULT_PERIOD),
    from: isDay(params.get("from") ?? "") ? (params.get("from") as string) : "",
    to: isDay(params.get("to") ?? "") ? (params.get("to") as string) : "",
    page: pageFromParam(params.get("page")),
    change: idFromParam(params.get("change")),
  };
}

export function changesApiFilters(
  view: ChangesView,
  now: number = Date.now(),
): ChangesFilters {
  return {
    mcp_tool: view.mcpTool || undefined,
    change_action: view.action || undefined,
    object_type: view.type || undefined,
    grant_id: view.grantId || undefined,
    audit_id: view.auditId || undefined,
    search: view.search.trim() || undefined,
    // One call's changes are shown whenever they were made.
    ...(view.auditId
      ? {}
      : periodRange(view.period, { from: view.from, to: view.to }, now)),
  };
}

export function filterValues(view: ChangesView): FilterValues {
  return {
    tool: view.mcpTool,
    action: view.action,
    type: view.type,
    period: view.period === DEFAULT_PERIOD ? "" : view.period,
    from: view.from,
    to: view.to,
    q: view.search,
  };
}

/** After a FilterBar change: back to page one; one call's filter and an open change kept. */
export function queryForFilters(query: string, values: FilterValues): string {
  const custom = values.period === "custom";

  return withParams(query, {
    tool: values.tool,
    action: values.action,
    type: values.type,
    period: values.period,
    from: custom ? values.from : null,
    to: custom ? values.to : null,
    q: values.q?.trim() ? values.q : null,
    page: null,
  });
}

export function hasFilters(view: ChangesView): boolean {
  return Boolean(
    view.search.trim() ||
    view.mcpTool ||
    view.action ||
    view.type ||
    view.grantId ||
    view.auditId ||
    view.period !== DEFAULT_PERIOD,
  );
}

// ── Labels ──────────────────────────────────────

export function actionLabel(action: ChangeAction): string {
  switch (action) {
    case "create":
      return __("Create", "mcpmate");
    case "update":
      return __("Update", "mcpmate");
    case "trash":
      return __("Trash", "mcpmate");
    case "delete":
      return __("Delete", "mcpmate");
  }
}

const TYPE_LABELS: Record<string, () => string> = {
  post: () => __("Posts and pages", "mcpmate"),
  post_meta: () => __("Post fields", "mcpmate"),
  term: () => __("Categories and tags", "mcpmate"),
  term_meta: () => __("Category and tag fields", "mcpmate"),
  option: () => __("Site settings (options)", "mcpmate"),
  user: () => __("Users", "mcpmate"),
  user_meta: () => __("User fields", "mcpmate"),
  comment: () => __("Comments", "mcpmate"),
  product: () => __("WooCommerce products", "mcpmate"),
  order: () => __("WooCommerce orders", "mcpmate"),
};

/** "Posts and pages", for the Type filter and the Settings checkboxes. */
export function typeLabel(type: string): string {
  return TYPE_LABELS[type]?.() ?? type;
}

const capitalize = (text: string) =>
  text ? text.charAt(0).toUpperCase() + text.slice(1) : text;

/**
 * What changed, readable: `Page "Home" (#41)`, `Option blogdescription`,
 * `Field _thumbnail_id of post #41`. The server's label is data (the post
 * type and title as stored), so only the frame around it is translated.
 */
export function objectText(change: ChangeEntry): string {
  const id = change.object_id;

  switch (change.object_type) {
    case "post":
    case "term":
    case "user":
    case "comment":
    case "product":
    case "order":
      return id > 0
        ? sprintf(
            /* translators: 1: what it is, e.g. page "Home", 2: its ID. */
            __("%1$s (#%2$d)", "mcpmate"),
            capitalize(change.object_label),
            id,
          )
        : capitalize(change.object_label);
    case "option":
      return sprintf(
        /* translators: %s: option name, e.g. blogdescription. */
        __("Option %s", "mcpmate"),
        change.object_key,
      );
    case "post_meta":
      return sprintf(
        /* translators: 1: field (meta) key, 2: post ID. */
        __("Field %1$s of post #%2$d", "mcpmate"),
        change.object_key,
        id,
      );
    case "term_meta":
      return sprintf(
        /* translators: 1: field (meta) key, 2: term ID. */
        __("Field %1$s of term #%2$d", "mcpmate"),
        change.object_key,
        id,
      );
    case "user_meta":
      return sprintf(
        /* translators: 1: field (meta) key, 2: user ID. */
        __("Field %1$s of user #%2$d", "mcpmate"),
        change.object_key,
        id,
      );
    default:
      return change.object_label || change.object_key;
  }
}

/** "Claude · admin"; "wp-admin · admin" for a change a site admin made (an approval). */
export function changedBy(change: ChangeEntry): string {
  const user =
    change.user_login ??
    (change.user_id > 0 ? `#${change.user_id}` : __("System", "mcpmate"));
  const app = change.grant_id
    ? (change.client_name ?? __("Removed AI app", "mcpmate"))
    : __("wp-admin", "mcpmate");

  return sprintf(
    /* translators: 1: AI app name (or wp-admin), 2: user name. */
    __("%1$s · %2$s", "mcpmate"),
    app,
    user,
  );
}

const FIELD_LABELS: Record<string, () => string> = {
  title: () => __("Title", "mcpmate"),
  content: () => __("Content", "mcpmate"),
  excerpt: () => __("Excerpt", "mcpmate"),
  status: () => __("Status", "mcpmate"),
  type: () => __("Type", "mcpmate"),
  slug: () => __("Slug", "mcpmate"),
  parent: () => __("Parent", "mcpmate"),
  menu_order: () => __("Order", "mcpmate"),
  author: () => __("Author", "mcpmate"),
  terms: () => __("Categories and tags", "mcpmate"),
  template: () => __("Template", "mcpmate"),
  name: () => __("Name", "mcpmate"),
  value: () => __("Value", "mcpmate"),
  description: () => __("Description", "mcpmate"),
};

/** `menu_order` → "Order"; an unknown key reads as itself, spaced. */
export function fieldLabel(key: string): string {
  return FIELD_LABELS[key]?.() ?? capitalize(key.replace(/_/g, " "));
}

const UNCHANGED_LENGTH = 200;

/** An unchanged value on one line, cut to 200 characters. */
export function oneLine(value: unknown): string {
  const text =
    value === null || value === undefined
      ? "—"
      : typeof value === "string"
        ? value
        : JSON.stringify(value);
  const line = text.replace(/\s+/g, " ").trim() || "—";

  return line.length > UNCHANGED_LENGTH
    ? `${line.slice(0, UNCHANGED_LENGTH - 1)}…`
    : line;
}

/** The server's per-field diff as DiffView fields; changed fields first. */
export function diffFields(change: ChangeDetail): DiffField[] {
  const fields = change.diff.fields.map((field) =>
    field.status === "unchanged"
      ? {
          key: field.field,
          label: fieldLabel(field.field),
          unchanged: oneLine(field.value),
        }
      : {
          key: field.field,
          label: fieldLabel(field.field),
          ops: field.ops ?? [],
        },
  );

  return [
    ...fields.filter((field) => field.unchanged === undefined),
    ...fields.filter((field) => field.unchanged !== undefined),
  ];
}

import { useEffect, useMemo, useRef } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import EmptyState from "@components/ui/EmptyState";
import FilterBar, {
  type FilterField,
  type FilterValues,
} from "@components/ui/FilterBar";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn, type TableSort } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchAuditLog, fetchTools } from "@services/api";
import {
  AUDIT_LOG_QUERY_KEY,
  TOOLS_QUERY_KEY,
  useGrants,
} from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatDate, formatNumber } from "@utils/format";
import type { AuditLogEntry, AuditResult } from "../../types/api";
import {
  customRangeError,
  formatDuration,
  matchesText,
  periodLabel,
  periodOptions,
  shortDateTime,
  siteTimeZone,
  withParams,
} from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import EntryDrawer from "./EntryDrawer";
import PurgeControl from "./PurgeControl";
import {
  argsSummary,
  auditApiFilters,
  auditViewFromQuery,
  DEFAULT_PERIOD,
  entryApp,
  entryTitle,
  entryTool,
  entryUser,
  filterValues,
  hasFilters,
  queryForFilters,
  queryForSort,
  RESULTS,
  resultLabel,
  resultTone,
  toolOptions,
} from "./filters";

const PER_PAGE = 20;

const DATE_ORDERS = () => ({
  ascending: __("oldest first", "mcpmate"),
  descending: __("newest first", "mcpmate"),
});

const DURATION_ORDERS = () => ({
  ascending: __("shortest first", "mcpmate"),
  descending: __("longest first", "mcpmate"),
});

const entriesText = (count: number) =>
  sprintf(
    /* translators: %s: number of audit log entries. */
    _n("%s entry", "%s entries", count, "mcpmate"),
    formatNumber(count),
  );

/**
 * Audit log (`#/activity`; docs/ui.md §5.4.1): every call an AI app made,
 * filtered and sorted on the server, 20 per page. Filters, sort, page and
 * the open entry live in the hash query. Under the table: how long entries
 * are kept, and Remove old entries now.
 */
export default function AuditLog() {
  const { search: query, current, go, closeDrawer } = useHashQuery();
  const announce = useAnnounce();
  const captionRef = useRef<HTMLElement>(null);
  const view = useMemo(() => auditViewFromQuery(query), [query]);
  // What to say once the next page of results arrives.
  const pending = useRef<"filters" | "cleared" | null>(null);

  // The period is resolved when the request runs, so the key stays stable;
  // opening an entry is not a new list.
  const list = useQuery({
    queryKey: [...AUDIT_LOG_QUERY_KEY, "list", { ...view, entry: null }],
    queryFn: () => fetchAuditLog(view.page, PER_PAGE, auditApiFilters(view)),
    placeholderData: keepPreviousData,
    staleTime: 0,
    enabled: customRangeError(view.from, view.to) === "",
  });
  const tools = useQuery({
    queryKey: TOOLS_QUERY_KEY,
    queryFn: fetchTools,
    staleTime: 5 * 60_000,
  });
  const grants = useGrants();

  const total = list.data?.total ?? 0;

  useEffect(() => {
    if (!pending.current || list.isFetching || !list.data) {
      return;
    }
    announce(
      pending.current === "cleared"
        ? sprintf(
            /* translators: %s: "1,240 entries". */
            __("Filters cleared. %s.", "mcpmate"),
            entriesText(list.data.total),
          )
        : matchesText(list.data.total, "entries"),
    );
    pending.current = null;
  }, [list.isFetching, list.data, announce]);

  const setFilters = (values: FilterValues) => {
    const cleared = Object.values(values).every((value) => !value);
    pending.current = cleared ? "cleared" : "filters";
    // Only what the user changed, onto the newest URL (see useHashQuery).
    const shown = filterValues(view);
    const changed = Object.entries(values).filter(
      ([key, value]) => (shown[key] ?? "") !== value,
    );
    const newest = filterValues(auditViewFromQuery(current()));
    go(
      queryForFilters(
        current(),
        cleared ? values : { ...newest, ...Object.fromEntries(changed) },
      ),
    );
  };

  const setSort = (sort: TableSort) => go(queryForSort(current(), sort));

  const setPage = (page: number) => {
    go(withParams(current(), { page: page > 1 ? String(page) : null }));
    captionRef.current?.focus();
    announce(
      sprintf(
        /* translators: 1: page number, 2: number of pages. */
        __("Page %1$s of %2$s.", "mcpmate"),
        formatNumber(page),
        formatNumber(Math.max(1, Math.ceil(total / PER_PAGE))),
      ),
    );
  };

  const openEntry = (id: number) =>
    go(withParams(current(), { entry: String(id) }), { push: true });

  const closeEntry = () => closeDrawer("entry");

  const showApp = (grantId: string) => {
    pending.current = "filters";
    go(withParams(current(), { grant_id: grantId, entry: null, page: null }));
  };

  // ── Filters ──

  const catalog = (tools.data?.groups ?? [])
    .flatMap((group) => group.tools.map((tool) => tool.name))
    .sort();
  const liveGrants = grants.data?.grants ?? [];
  const users = new Map<string, string>();
  liveGrants.forEach((grant) =>
    users.set(
      String(grant.user.id),
      grant.user.login ?? grant.user.display_name ?? `#${grant.user.id}`,
    ),
  );
  if (view.userId && !users.has(view.userId)) {
    users.set(view.userId, `#${view.userId}`);
  }
  const apps: { value: string; label: string }[] = liveGrants.map((grant) => ({
    value: grant.grant_id,
    label: sprintf(
      /* translators: 1: AI app name, 2: user it acts as, 3: date it was approved. */
      __("%1$s · %2$s (approved %3$s)", "mcpmate"),
      grant.client_name,
      grant.user.login ?? `#${grant.user.id}`,
      formatDate(grant.created_at, { day: "numeric", month: "short" }),
    ),
  }));
  if (view.grantId && !apps.some((app) => app.value === view.grantId)) {
    apps.push({ value: view.grantId, label: view.grantId });
  }

  const fields: FilterField[] = [
    { key: "q", type: "search", label: __("Search", "mcpmate") },
    {
      key: "tool",
      type: "select",
      label: __("Tool", "mcpmate"),
      options: toolOptions(catalog, view),
    },
    {
      key: "user",
      type: "select",
      label: __("User", "mcpmate"),
      options: [...users].map(([value, label]) => ({ value, label })),
    },
    {
      key: "grant_id",
      type: "select",
      label: __("AI app", "mcpmate"),
      options: apps,
    },
    {
      key: "result",
      type: "select",
      label: __("Status", "mcpmate"),
      options: RESULTS.map((result) => ({
        value: result,
        label: resultLabel(result),
      })),
    },
    {
      key: "period",
      type: "select",
      label: __("Period", "mcpmate"),
      anyLabel: periodLabel(DEFAULT_PERIOD),
      options: periodOptions(DEFAULT_PERIOD),
    },
    ...(view.period === "custom"
      ? ([
          { key: "from", type: "date", label: __("From", "mcpmate") },
          { key: "to", type: "date", label: __("To", "mcpmate") },
        ] as FilterField[])
      : []),
  ];

  // ── Table ──

  const columns: TableColumn<AuditLogEntry>[] = [
    {
      key: "date",
      title: __("Date", "mcpmate"),
      rowHeader: true,
      sortable: true,
      firstOrder: "descending",
      orderLabels: DATE_ORDERS(),
      render: (row) => (
        <time dateTime={row.created_at} className="whitespace-nowrap">
          {shortDateTime(row.created_at)}
        </time>
      ),
    },
    {
      key: "user",
      title: __("User", "mcpmate"),
      render: (row) => entryUser(row),
    },
    {
      key: "app",
      title: __("AI app", "mcpmate"),
      render: (row) => entryApp(row),
    },
    {
      key: "tool",
      title: __("Tool", "mcpmate"),
      render: (row) => (
        <bdi dir="ltr" className="el-mono">
          {entryTool(row)}
        </bdi>
      ),
    },
    {
      key: "args",
      title: __("Arguments", "mcpmate"),
      render: (row) => (
        <bdi dir="ltr" className="el-mono el-audit-args">
          {argsSummary(row.args)}
        </bdi>
      ),
    },
    {
      key: "result",
      title: __("Status", "mcpmate"),
      render: (row) => (
        <StatusText tone={resultTone(row.result as AuditResult)}>
          {resultLabel(row.result as AuditResult)}
        </StatusText>
      ),
    },
    {
      key: "duration",
      title: __("Duration", "mcpmate"),
      sortable: true,
      firstOrder: "descending",
      orderLabels: DURATION_ORDERS(),
      render: (row) => (
        <span className="whitespace-nowrap">
          {formatDuration(row.duration_ms)}
        </span>
      ),
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) => (
        <Button
          variant="link"
          size="sm"
          label={sprintf(
            /* translators: %s: "elementor_save · 26 Oct 10:42". */
            __("View details: %s", "mcpmate"),
            entryTitle(row),
          )}
          onClick={() => openEntry(row.id)}
        >
          {__("View", "mcpmate")}
        </Button>
      ),
    },
  ];

  const filtered = hasFilters(view);
  const rangeError = customRangeError(view.from, view.to);

  const onlyAllTime =
    view.period === "all" && !hasFilters({ ...view, period: DEFAULT_PERIOD });

  const empty = !filtered ? (
    <EmptyState
      title={__("Nothing logged in the last 7 days.", "mcpmate")}
      body={__(
        "Every change an AI app makes is listed here, with who made it and when.",
        "mcpmate",
      )}
      action={{
        label: __("Show all time", "mcpmate"),
        onClick: () => {
          pending.current = "filters";
          go(withParams(current(), { period: "all", page: null }));
        },
      }}
    />
  ) : onlyAllTime ? (
    <EmptyState
      title={__("Nothing logged yet.", "mcpmate")}
      body={__(
        "Every change an AI app makes is listed here, with who made it and when.",
        "mcpmate",
      )}
      action={{
        label: __("Connect an AI app", "mcpmate"),
        href: "#/connections",
      }}
    />
  ) : (
    <EmptyState
      title={__("No entries match these filters.", "mcpmate")}
      action={{
        label: __("Clear filters", "mcpmate"),
        onClick: () =>
          setFilters(
            Object.fromEntries(fields.map((field) => [field.key, ""])),
          ),
      }}
    />
  );

  return (
    <div className="el-activity">
      <h2 className="sr-only">{__("Audit log", "mcpmate")}</h2>
      <FilterBar
        fields={fields}
        values={filterValues(view)}
        onChange={setFilters}
      />
      {rangeError && <Notice tone="warning" title={rangeError} live />}
      {list.data && !list.isError && (
        <p className="el-list-count">{entriesText(total)}</p>
      )}
      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<AuditLogEntry>
          caption={sprintf(
            /* translators: 1: the order, e.g. "newest first", 2: the site's timezone, e.g. Asia/Dhaka. */
            __("Audit log, %1$s. Times in %2$s.", "mcpmate"),
            view.sort.key === "duration"
              ? DURATION_ORDERS()[view.sort.order]
              : DATE_ORDERS()[view.sort.order],
            siteTimeZone(),
          )}
          captionRef={captionRef}
          columns={columns}
          rows={list.data?.items ?? []}
          rowKey={(row) => String(row.id)}
          sort={view.sort}
          onSort={setSort}
          loading={list.isPending && !rangeError}
          loadingRows={8}
          empty={empty}
          error={
            list.isError && (
              <Notice
                tone="danger"
                title={__("Couldn't load the audit log.", "mcpmate")}
                action={
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => void list.refetch()}
                  >
                    {__("Try again", "mcpmate")}
                  </Button>
                }
              >
                {requestErrorText(list.error)}
              </Notice>
            )
          }
        />
      </div>
      {total > PER_PAGE && !list.isError && (
        <Pagination
          label={__("Audit log pages", "mcpmate")}
          page={view.page}
          pageSize={PER_PAGE}
          total={total}
          onChange={setPage}
        />
      )}
      <PurgeControl />
      <EntryDrawer
        entryId={view.entry}
        rows={list.data?.items ?? []}
        onClose={closeEntry}
        onShowApp={showApp}
      />
    </div>
  );
}

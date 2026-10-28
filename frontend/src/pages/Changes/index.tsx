import { useEffect, useMemo, useRef } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import {
  DeleteOutlined,
  EditOutlined,
  PlusOutlined,
  RestOutlined,
} from "@ant-design/icons";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import EmptyState from "@components/ui/EmptyState";
import FilterBar, {
  type FilterField,
  type FilterValues,
} from "@components/ui/FilterBar";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchChanges, fetchPreferences, fetchTools } from "@services/api";
import {
  CHANGES_QUERY_KEY,
  PREFERENCES_QUERY_KEY,
  TOOLS_QUERY_KEY,
} from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { ChangeAction, ChangeEntry } from "../../types/api";
import {
  customRangeError,
  matchesText,
  periodLabel,
  periodOptions,
  shortDateTime,
  siteTimeZone,
  withParams,
} from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import ChangeDrawer from "./ChangeDrawer";
import {
  ACTIONS,
  actionLabel,
  changedBy,
  changesApiFilters,
  changesViewFromQuery,
  DEFAULT_PERIOD,
  filterValues,
  hasFilters,
  objectText,
  queryForFilters,
  typeLabel,
} from "./logic";

const PER_PAGE = 20;

const ACTION_ICONS: Record<ChangeAction, typeof EditOutlined> = {
  create: PlusOutlined,
  update: EditOutlined,
  trash: RestOutlined,
  delete: DeleteOutlined,
};

const changesText = (count: number) =>
  sprintf(
    /* translators: %s: number of changes. */
    _n("%s change", "%s changes", count, "mcpmate"),
    formatNumber(count),
  );

/** An icon and the word; the icon is never shown alone. */
function ActionText({ action }: { action: ChangeAction }) {
  const Icon = ACTION_ICONS[action];

  return (
    <span className="el-action-text">
      <Icon aria-hidden />
      {actionLabel(action)}
    </span>
  );
}

/**
 * Change history (`#/activity/changes`; docs/ui.md §5.4.2): every object a
 * write through McpMate changed, with its before and after (`?change=`)
 * and the call that made it (`#/activity?entry=`). Filters, page and the
 * open change live in the hash query.
 */
export default function Changes() {
  const { search: query, current, go, closeDrawer } = useHashQuery();
  const announce = useAnnounce();
  const captionRef = useRef<HTMLElement>(null);
  const view = useMemo(() => changesViewFromQuery(query), [query]);
  const pending = useRef<"filters" | "cleared" | null>(null);
  const rangeError = customRangeError(view.from, view.to);

  const list = useQuery({
    queryKey: [...CHANGES_QUERY_KEY, "list", { ...view, change: null }],
    queryFn: () => fetchChanges(view.page, PER_PAGE, changesApiFilters(view)),
    placeholderData: keepPreviousData,
    staleTime: 0,
    enabled: rangeError === "",
  });
  const preferences = useQuery({
    queryKey: PREFERENCES_QUERY_KEY,
    queryFn: fetchPreferences,
  });
  const tools = useQuery({
    queryKey: TOOLS_QUERY_KEY,
    queryFn: fetchTools,
    staleTime: 5 * 60_000,
  });

  const total = list.data?.total ?? 0;

  useEffect(() => {
    if (!pending.current || list.isFetching || !list.data) {
      return;
    }
    announce(
      pending.current === "cleared"
        ? sprintf(
            /* translators: %s: "24 changes". */
            __("Filters cleared. %s.", "mcpmate"),
            changesText(list.data.total),
          )
        : matchesText(list.data.total, "changes"),
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
    const newest = filterValues(changesViewFromQuery(current()));
    go(
      queryForFilters(
        current(),
        cleared ? values : { ...newest, ...Object.fromEntries(changed) },
      ),
    );
  };

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

  const openChange = (id: number) =>
    go(withParams(current(), { change: String(id) }), { push: true });

  const closeChange = () => closeDrawer("change");

  const catalog = (tools.data?.groups ?? [])
    .flatMap((group) => group.tools.map((tool) => tool.name))
    .sort();
  if (view.mcpTool && !catalog.includes(view.mcpTool)) {
    catalog.push(view.mcpTool);
  }
  const types = preferences.data?.change_types ?? [];

  const fields: FilterField[] = [
    {
      key: "tool",
      type: "select",
      label: __("Tool", "mcpmate"),
      options: catalog.map((name) => ({ value: name, label: name })),
    },
    {
      key: "action",
      type: "select",
      label: __("Action", "mcpmate"),
      options: ACTIONS.map((action) => ({
        value: action,
        label: actionLabel(action),
      })),
    },
    {
      key: "type",
      type: "select",
      label: __("Type", "mcpmate"),
      options: types.map((type) => ({ value: type, label: typeLabel(type) })),
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
    { key: "q", type: "search", label: __("Search objects", "mcpmate") },
  ];

  const columns: TableColumn<ChangeEntry>[] = [
    {
      key: "date",
      title: __("Date", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <time dateTime={row.created_at} className="whitespace-nowrap">
          {shortDateTime(row.created_at)}
        </time>
      ),
    },
    {
      key: "tool",
      title: __("Tool", "mcpmate"),
      render: (row) =>
        row.mcp_tool || row.tool ? (
          <bdi dir="ltr" className="el-mono">
            {row.mcp_tool || row.tool}
          </bdi>
        ) : (
          "—"
        ),
    },
    {
      key: "action",
      title: __("Action", "mcpmate"),
      render: (row) => <ActionText action={row.action} />,
    },
    {
      key: "object",
      title: __("Object", "mcpmate"),
      render: (row) => objectText(row),
    },
    {
      key: "by",
      title: __("By", "mcpmate"),
      render: (row) => changedBy(row),
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) => (
        <span className="el-row-actions">
          <Button
            variant="link"
            size="sm"
            label={sprintf(
              /* translators: %s: what changed, e.g. Page "Home" (#41). */
              __("View before and after: %s", "mcpmate"),
              objectText(row),
            )}
            onClick={() => openChange(row.id)}
          >
            {__("View before and after", "mcpmate")}
          </Button>
          {row.audit_id > 0 && (
            <Link
              to={`/activity?entry=${row.audit_id}`}
              aria-label={sprintf(
                /* translators: %s: what changed, e.g. Page "Home" (#41). */
                __("Originating call: %s", "mcpmate"),
                objectText(row),
              )}
            >
              {__("Originating call", "mcpmate")}
            </Link>
          )}
        </span>
      ),
    },
  ];

  const empty = hasFilters(view) ? (
    <EmptyState
      title={__("No changes match these filters.", "mcpmate")}
      action={{
        label: __("Clear filters", "mcpmate"),
        onClick: () => {
          pending.current = "cleared";
          go(
            withParams(queryForFilters(current(), {}), {
              grant_id: null,
              audit_id: null,
            }),
          );
        },
      }}
    />
  ) : (
    <EmptyState
      title={__("No changes yet.", "mcpmate")}
      body={__(
        "When an AI app creates, updates or trashes something, you'll see what it looked like before and after.",
        "mcpmate",
      )}
      action={{
        label: __("View the audit log", "mcpmate"),
        href: "#/activity",
      }}
    />
  );

  return (
    <div className="el-activity">
      <h2 className="sr-only">{__("Change history", "mcpmate")}</h2>
      {preferences.data && !preferences.data.preferences.change_history && (
        <Notice
          tone="warning"
          title={__(
            "Change history is turned off, so new changes aren't recorded.",
            "mcpmate",
          )}
          action={
            <Link to="/settings?section=logging">
              {__("Turn it on in Settings", "mcpmate")}
            </Link>
          }
        />
      )}
      <FilterBar
        fields={fields}
        values={filterValues(view)}
        onChange={setFilters}
      />
      {(view.auditId || view.grantId) && (
        <p className="el-filter-scope">
          {view.auditId
            ? sprintf(
                /* translators: %s: audit log entry ID. */
                __("Showing the changes of one call (entry #%s).", "mcpmate"),
                view.auditId,
              )
            : __("Showing the changes of one AI app.", "mcpmate")}{" "}
          <Button
            variant="link"
            size="sm"
            onClick={() => {
              pending.current = "filters";
              go(
                withParams(current(), {
                  audit_id: null,
                  grant_id: null,
                  page: null,
                }),
              );
            }}
          >
            {__("Show all changes", "mcpmate")}
          </Button>
        </p>
      )}
      {rangeError && <Notice tone="warning" title={rangeError} live />}
      {list.data && !list.isError && (
        <p className="el-list-count">{changesText(total)}</p>
      )}
      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<ChangeEntry>
          caption={sprintf(
            /* translators: %s: the site's timezone, e.g. Asia/Dhaka. */
            __(
              "Changes made through McpMate, newest first. Times in %s.",
              "mcpmate",
            ),
            siteTimeZone(),
          )}
          captionRef={captionRef}
          columns={columns}
          rows={list.data?.items ?? []}
          rowKey={(row) => String(row.id)}
          loading={list.isPending && !rangeError}
          loadingRows={8}
          empty={empty}
          error={
            list.isError && (
              <Notice
                tone="danger"
                title={__("Couldn't load the change history.", "mcpmate")}
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
          label={__("Change history pages", "mcpmate")}
          page={view.page}
          pageSize={PER_PAGE}
          total={total}
          onChange={setPage}
        />
      )}
      <ChangeDrawer changeId={view.change} onClose={closeChange} />
    </div>
  );
}

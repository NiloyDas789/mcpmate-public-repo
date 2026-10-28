import { useEffect, useMemo, useRef } from "react";
import {
  keepPreviousData,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { Link } from "react-router";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import RelativeTime from "@components/ui/RelativeTime";
import SegmentedControl from "@components/ui/SegmentedControl";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchApprovals } from "@services/api";
import {
  APPROVALS_QUERY_KEY,
  PENDING_APPROVALS_QUERY_KEY,
} from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type {
  ApprovalListItem,
  ApprovalStatus,
  ApprovalUser,
} from "../../types/api";
import {
  pageFromParam,
  shortDateTime,
  siteTimeZone,
  withParams,
} from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import { describeRequest, statusLabel, statusTone } from "./labels";

const PER_PAGE = 20;

/** `?status=`: Waiting (the default, no key), Run, Rejected, Expired, All. */
type Filter = "pending" | "executed" | "rejected" | "expired" | "all";

const FILTERS: Filter[] = ["pending", "executed", "rejected", "expired", "all"];

function filterFromParam(value: string | null): Filter {
  return FILTERS.includes(value as Filter) ? (value as Filter) : "pending";
}

function filterLabel(filter: Filter): string {
  switch (filter) {
    case "pending":
      return __("Waiting", "mcpmate");
    case "executed":
      return __("Run", "mcpmate");
    case "rejected":
      return __("Rejected", "mcpmate");
    case "expired":
      return __("Expired", "mcpmate");
    case "all":
      return __("All", "mcpmate");
  }
}

function userLabel(user: ApprovalUser): string {
  return user.display_name ?? user.login ?? `#${user.id}`;
}

const requestsText = (count: number) =>
  sprintf(
    /* translators: %s: number of approval requests. */
    _n("%s request", "%s requests", count, "mcpmate"),
    formatNumber(count),
  );

/**
 * Activity → Approvals (`#/activity/approvals`; docs/ui.md §5.4.3): requests
 * that are hard to undo wait here (15 minutes) for a site admin to open and
 * approve or reject. The filter and page live in the hash query.
 */
export default function Approvals() {
  const { search, current, go } = useHashQuery();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const captionRef = useRef<HTMLElement>(null);
  const params = useMemo(() => new URLSearchParams(search), [search]);
  const filter = filterFromParam(params.get("status"));
  const page = pageFromParam(params.get("page"));
  const pending = useRef(false);

  const list = useQuery({
    queryKey: [...APPROVALS_QUERY_KEY, "list", filter, page],
    queryFn: () =>
      fetchApprovals(filter === "all" ? "" : (filter as ApprovalStatus), page),
    placeholderData: keepPreviousData,
    staleTime: 0,
  });
  const total = list.data?.total ?? 0;

  useEffect(() => {
    if (!list.data || list.isPlaceholderData) {
      return;
    }
    if (filter === "pending") {
      queryClient.setQueryData(PENDING_APPROVALS_QUERY_KEY, list.data.total);
    }
    if (pending.current) {
      pending.current = false;
      announce(requestsText(list.data.total));
    }
  }, [list.data, list.isPlaceholderData, filter, queryClient, announce]);

  const setFilter = (next: Filter) => {
    pending.current = true;
    go(
      withParams(current(), {
        status: next === "pending" ? null : next,
        page: null,
      }),
    );
  };

  const setPage = (next: number) => {
    go(withParams(current(), { page: next > 1 ? String(next) : null }));
    captionRef.current?.focus();
    announce(
      sprintf(
        /* translators: 1: page number, 2: number of pages. */
        __("Page %1$s of %2$s.", "mcpmate"),
        formatNumber(next),
        formatNumber(Math.max(1, Math.ceil(total / PER_PAGE))),
      ),
    );
  };

  const columns: TableColumn<ApprovalListItem>[] = [
    {
      key: "requested",
      title: __("Requested", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <time dateTime={row.created_at} className="whitespace-nowrap">
          {shortDateTime(row.created_at)}
        </time>
      ),
    },
    {
      key: "request",
      title: __("What will run", "mcpmate"),
      render: (row) => (
        <bdi dir="ltr" className="el-mono break-all">
          {describeRequest(row)}
        </bdi>
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (row) => (
        <StatusText {...statusTone(row.status)}>
          {statusLabel(row.status)}
        </StatusText>
      ),
    },
    {
      key: "by",
      title: __("Requested by", "mcpmate"),
      render: (row) => userLabel(row.created_by),
    },
    {
      key: "expires",
      title: __("Expires", "mcpmate"),
      render: (row) =>
        row.status === "pending" ? <RelativeTime date={row.expires_at} /> : "—",
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) => (
        <Link
          to={`/activity/approvals/${row.id}`}
          aria-label={sprintf(
            /* translators: 1: request number, 2: what it runs. */
            __("Review request #%1$d: %2$s", "mcpmate"),
            row.id,
            describeRequest(row),
          )}
        >
          {row.status === "pending"
            ? __("Review", "mcpmate")
            : __("View", "mcpmate")}
        </Link>
      ),
    },
  ];

  const empty =
    filter === "pending" ? (
      <EmptyState
        title={__("Nothing waiting for you.", "mcpmate")}
        body={__(
          "When an AI app asks to do something that's hard to undo, the request waits here for you.",
          "mcpmate",
        )}
        action={{
          label: __("View all requests", "mcpmate"),
          onClick: () => setFilter("all"),
        }}
      />
    ) : (
      <EmptyState
        title={__("No requests here.", "mcpmate")}
        action={
          filter === "all"
            ? undefined
            : {
                label: __("View all requests", "mcpmate"),
                onClick: () => setFilter("all"),
              }
        }
      />
    );

  return (
    <div className="el-activity">
      <h2 className="sr-only">{__("Approvals", "mcpmate")}</h2>
      <p className="el-tools-intro">
        {__(
          "When an AI app asks to do something that's hard to undo, it waits here until you approve or reject it. Open a request to see exactly what will run. Requests expire after 15 minutes.",
          "mcpmate",
        )}
      </p>
      <SegmentedControl<Filter>
        label={__("Show", "mcpmate")}
        value={filter}
        onChange={setFilter}
        options={FILTERS.map((value) => ({
          value,
          label: filterLabel(value),
        }))}
      />
      {list.data && !list.isError && (
        <p className="el-list-count">{requestsText(total)}</p>
      )}
      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<ApprovalListItem>
          caption={sprintf(
            /* translators: %s: the site's timezone, e.g. Asia/Dhaka. */
            __("Approval requests, newest first. Times in %s.", "mcpmate"),
            siteTimeZone(),
          )}
          captionRef={captionRef}
          columns={columns}
          rows={list.data?.items ?? []}
          rowKey={(row) => String(row.id)}
          loading={list.isPending}
          loadingRows={5}
          empty={empty}
          error={
            list.isError && (
              <Notice
                tone="danger"
                title={__("Couldn't load approval requests.", "mcpmate")}
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
          label={__("Approval request pages", "mcpmate")}
          page={page}
          pageSize={PER_PAGE}
          total={total}
          onChange={setPage}
        />
      )}
    </div>
  );
}

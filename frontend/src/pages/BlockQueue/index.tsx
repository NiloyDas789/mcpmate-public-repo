import { useEffect, useMemo, useRef } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import SegmentedControl from "@components/ui/SegmentedControl";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchBlockQueue } from "@services/blockQueue";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { BlockBatch, BlockBatchStatus } from "../../types/api";
import {
  pageFromParam,
  shortDateTime,
  siteTimeZone,
  withParams,
} from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import {
  BLOCK_QUEUE_QUERY_KEY,
  batchName,
  batchStatusLabel,
  batchStatusTone,
  canFinalize,
  changesText,
} from "./labels";

const PER_PAGE = 20;

/** `?status=`: Waiting (the default, no key), Being queued, Finalized, Failed, All. */
type Filter = "ready" | "open" | "finalized" | "failed" | "all";

const FILTERS: Filter[] = ["ready", "open", "finalized", "failed", "all"];

function filterFromParam(value: string | null): Filter {
  return FILTERS.includes(value as Filter) ? (value as Filter) : "ready";
}

function filterLabel(filter: Filter): string {
  return filter === "all"
    ? __("All", "mcpmate")
    : batchStatusLabel(filter as BlockBatchStatus);
}

const batchesText = (count: number) =>
  sprintf(
    /* translators: %s: number of batches. */
    count === 1 ? __("%s batch", "mcpmate") : __("%s batches", "mcpmate"),
    formatNumber(count),
  );

/**
 * Activity → Block editor queue (`#/activity/block-queue`; MCPMate Pro):
 * post changes AI apps queued for the block editor. They change nothing
 * until a site admin opens a batch and finalizes it in this browser. The
 * filter and page live in the hash query.
 */
export default function BlockQueue() {
  const { search, current, go } = useHashQuery();
  const announce = useAnnounce();
  const captionRef = useRef<HTMLElement>(null);
  const params = useMemo(() => new URLSearchParams(search), [search]);
  const filter = filterFromParam(params.get("status"));
  const page = pageFromParam(params.get("page"));
  const pending = useRef(false);

  const list = useQuery({
    queryKey: [...BLOCK_QUEUE_QUERY_KEY, "list", filter, page],
    queryFn: () =>
      fetchBlockQueue(
        filter === "all" ? "" : (filter as BlockBatchStatus),
        page,
      ),
    placeholderData: keepPreviousData,
    staleTime: 0,
  });
  const total = list.data?.total ?? 0;

  useEffect(() => {
    if (list.data && !list.isPlaceholderData && pending.current) {
      pending.current = false;
      announce(batchesText(list.data.total));
    }
  }, [list.data, list.isPlaceholderData, announce]);

  const setFilter = (next: Filter) => {
    pending.current = true;
    go(
      withParams(current(), {
        status: next === "ready" ? null : next,
        page: null,
      }),
    );
  };

  const setPage = (next: number) => {
    go(withParams(current(), { page: next > 1 ? String(next) : null }));
    captionRef.current?.focus();
  };

  const columns: TableColumn<BlockBatch>[] = [
    {
      key: "updated",
      title: __("Updated", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <time dateTime={row.updated_at} className="whitespace-nowrap">
          {shortDateTime(row.updated_at)}
        </time>
      ),
    },
    {
      key: "batch",
      title: __("Batch", "mcpmate"),
      render: (row) => (
        <>
          <bdi>{batchName(row)}</bdi>
          <span className="block text-el-text-secondary">
            {changesText(row.item_count)}
          </span>
        </>
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (row) => (
        <StatusText {...batchStatusTone(row.status)}>
          {batchStatusLabel(row.status)}
        </StatusText>
      ),
    },
    {
      key: "by",
      title: __("Queued by", "mcpmate"),
      render: (row) => (
        <>
          {row.user.display_name ?? row.user.login ?? `#${row.user.id}`}
          {row.grant && (
            <span className="block text-el-text-secondary">
              <bdi>{row.grant.client_name}</bdi>
            </span>
          )}
        </>
      ),
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) => (
        <Link
          to={`/activity/block-queue/${row.id}`}
          aria-label={sprintf(
            /* translators: %s: batch name. */
            __("Open %s", "mcpmate"),
            batchName(row),
          )}
        >
          {canFinalize(row) ? __("Review", "mcpmate") : __("View", "mcpmate")}
        </Link>
      ),
    },
  ];

  const empty = (
    <EmptyState
      title={
        filter === "ready"
          ? __("Nothing waiting for you.", "mcpmate")
          : __("No batches here.", "mcpmate")
      }
      body={
        filter === "ready"
          ? __(
              "When an AI app queues post changes for the block editor and asks you to finalize them, the batch waits here.",
              "mcpmate",
            )
          : undefined
      }
      action={
        filter === "all"
          ? undefined
          : {
              label: __("View all batches", "mcpmate"),
              onClick: () => setFilter("all"),
            }
      }
    />
  );

  return (
    <div className="el-activity">
      <h2 className="sr-only">{__("Block editor queue", "mcpmate")}</h2>
      <p className="el-tools-intro">
        {__(
          "Post changes an AI app wants written by the block editor itself, so every block's markup is exactly what the editor saves. Nothing changes until you open a batch and finalize it: the block editor then runs in this browser and saves each post as a draft.",
          "mcpmate",
        )}
      </p>
      <SegmentedControl<Filter>
        label={__("Show", "mcpmate")}
        value={filter}
        onChange={setFilter}
        options={FILTERS.map((value) => ({ value, label: filterLabel(value) }))}
      />
      {list.data && !list.isError && (
        <p className="el-list-count">{batchesText(total)}</p>
      )}
      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<BlockBatch>
          caption={sprintf(
            /* translators: %s: the site's timezone, e.g. Asia/Dhaka. */
            __("Block editor batches, newest first. Times in %s.", "mcpmate"),
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
                title={__("Couldn't load the block editor queue.", "mcpmate")}
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
          label={__("Block editor queue pages", "mcpmate")}
          page={page}
          pageSize={PER_PAGE}
          total={total}
          onChange={setPage}
        />
      )}
    </div>
  );
}

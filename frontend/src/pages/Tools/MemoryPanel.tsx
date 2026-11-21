import { useMemo, useRef, useState } from "react";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Badge from "@components/ui/Badge";
import Button from "@components/ui/Button";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import FilterBar, {
  type FilterField,
  type FilterValues,
} from "@components/ui/FilterBar";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import SegmentedControl from "@components/ui/SegmentedControl";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchMemories, moveMemory, purgeMemories } from "@services/api";
import { MEMORIES_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { MemoriesPage, Memory, MemoryStatus } from "../../types/api";
import { shortDateTime, siteTimeZone, withParams } from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import MemoryDrawer from "./MemoryDrawer";
import { memoryFilterValues, memoryViewFromQuery } from "./memoryLogic";

const FALLBACK_LIMITS: MemoriesPage["limits"] = {
  title_chars: 200,
  content_bytes: 16384,
  tags: 10,
  tag_chars: 40,
};

const memoriesText = (count: number) =>
  sprintf(
    /* translators: %s: number of memories. */
    _n("%s memory", "%s memories", count, "mcpmate"),
    formatNumber(count),
  );

type Purge = { kind: "one"; memory: Memory } | { kind: "all"; count: number };

/**
 * Tools → Memory (`#/tools/memory`, MCPMate Pro; docs/pro-parity.md P2):
 * the notes AI apps saved for each WordPress user. A site admin browses,
 * edits and trashes them, restores trashed ones and deletes them for good:
 * the AI's delete_memory only moves a memory to the trash. Filters, the
 * page and the open memory live in the hash query.
 */
export default function MemoryPanel() {
  const { search: query, current, go, closeDrawer } = useHashQuery();
  const view = useMemo(() => memoryViewFromQuery(query), [query]);
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const captionRef = useRef<HTMLElement>(null);
  const [purge, setPurge] = useState<Purge | null>(null);

  const list = useQuery({
    queryKey: [...MEMORIES_QUERY_KEY, "list", { ...view, memory: null }],
    queryFn: () =>
      fetchMemories(view.page, {
        status: view.status === "trash" ? "trash" : undefined,
        user_id: view.user || undefined,
        tag: view.tag,
        search: view.q,
      }),
    placeholderData: keepPreviousData,
    staleTime: 0,
  });
  const data = list.data;
  const total = data?.total ?? 0;
  const trash = view.status === "trash";

  const refresh = () =>
    void queryClient.invalidateQueries({ queryKey: MEMORIES_QUERY_KEY });

  const restore = useMutation({
    mutationFn: (memory: Memory) => moveMemory(memory.id, "restore"),
    onSuccess: () => {
      refresh();
      announce(__("Memory restored.", "mcpmate"));
    },
  });
  const remove = useMutation({
    mutationFn: (target: Purge) =>
      purgeMemories(target.kind === "all" ? "all" : [target.memory.id]),
    onSuccess: (removed) => {
      if (view.memory) {
        closeDrawer("memory");
      }
      refresh();
      announce(
        sprintf(
          /* translators: %s: "3 memories". */
          __("%s deleted for good.", "mcpmate"),
          memoriesText(removed),
        ),
      );
    },
  });

  const setStatus = (status: MemoryStatus) =>
    go(
      withParams(current(), {
        status: status === "trash" ? "trash" : null,
        page: null,
      }),
    );

  const setFilters = (values: FilterValues) =>
    go(
      withParams(current(), {
        user: values.user,
        tag: values.tag,
        q: values.q,
        page: null,
      }),
    );

  const setPage = (page: number) => {
    go(withParams(current(), { page: page > 1 ? String(page) : null }));
    captionRef.current?.focus();
  };

  const open = (memory: Memory) =>
    go(withParams(current(), { memory: String(memory.id) }), { push: true });

  const userName = (memory: Pick<Memory, "user" | "user_id">) =>
    memory.user.name ??
    sprintf(
      /* translators: %d: user ID. */ __("User #%d", "mcpmate"),
      memory.user_id,
    );

  const fields: FilterField[] = [
    {
      key: "user",
      type: "select",
      label: __("User", "mcpmate"),
      options: (data?.users ?? []).map((user) => ({
        value: String(user.id),
        label:
          user.name ??
          sprintf(
            /* translators: %d: user ID. */ __("User #%d", "mcpmate"),
            user.id,
          ),
      })),
    },
    {
      key: "tag",
      type: "select",
      label: __("Tag", "mcpmate"),
      options: (data?.tags ?? []).map((tag) => ({ value: tag, label: tag })),
    },
    { key: "q", type: "search", label: __("Search memories", "mcpmate") },
  ];

  const columns: TableColumn<Memory>[] = [
    {
      key: "title",
      title: __("Memory", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <span className="el-stack-tight">
          <Button variant="link" size="sm" onClick={() => open(row)}>
            {row.title}
          </Button>
          {row.excerpt && (
            <span className="el-setting-hint">{row.excerpt}</span>
          )}
        </span>
      ),
    },
    { key: "user", title: __("User", "mcpmate"), render: userName },
    {
      key: "tags",
      title: __("Tags", "mcpmate"),
      render: (row) =>
        row.tags.length === 0 ? (
          "—"
        ) : (
          <span className="el-badges">
            {row.tags.map((tag) => (
              <Badge key={tag} variant="neutral">
                {tag}
              </Badge>
            ))}
          </span>
        ),
    },
    {
      key: "date",
      title: trash ? __("Trashed", "mcpmate") : __("Last changed", "mcpmate"),
      render: (row) => {
        const at = (trash ? row.trashed_at : row.updated_at) ?? row.updated_at;
        return (
          <time dateTime={at} className="whitespace-nowrap">
            {shortDateTime(at)}
          </time>
        );
      },
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) =>
        trash ? (
          <span className="el-row-actions">
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: memory title. */ __(
                  "Restore: %s",
                  "mcpmate",
                ),
                row.title,
              )}
              onClick={() => restore.mutate(row)}
            >
              {__("Restore", "mcpmate")}
            </Button>
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: memory title. */ __(
                  "Delete permanently: %s",
                  "mcpmate",
                ),
                row.title,
              )}
              onClick={() => setPurge({ kind: "one", memory: row })}
            >
              {__("Delete permanently", "mcpmate")}
            </Button>
          </span>
        ) : (
          <Button
            variant="link"
            size="sm"
            label={sprintf(
              /* translators: %s: memory title. */ __("Edit: %s", "mcpmate"),
              row.title,
            )}
            onClick={() => open(row)}
          >
            {__("Edit", "mcpmate")}
          </Button>
        ),
    },
  ];

  const filtered = Boolean(view.user || view.tag || view.q);
  const empty = filtered ? (
    <EmptyState
      title={__("No memories match these filters.", "mcpmate")}
      action={{
        label: __("Clear filters", "mcpmate"),
        onClick: () => setFilters({ user: "", tag: "", q: "" }),
      }}
    />
  ) : trash ? (
    <EmptyState title={__("The trash is empty.", "mcpmate")} />
  ) : (
    <EmptyState
      title={__("No memories yet.", "mcpmate")}
      body={__(
        "When you ask an AI app to remember something, it saves a memory here with save_memory. Turn on the Memory tools on the Core tab first.",
        "mcpmate",
      )}
    />
  );

  const failure = restore.error;

  return (
    <div className="el-activity">
      <p className="el-tools-intro">
        {__(
          "Memories are notes your AI apps keep for a WordPress user, such as their preferences or decisions about this site. Every app that user connects sees them. When an AI app deletes one, it goes to the trash, and only you can delete it for good.",
          "mcpmate",
        )}
      </p>
      <SegmentedControl<MemoryStatus>
        label={__("Show", "mcpmate")}
        value={view.status}
        onChange={setStatus}
        options={[
          {
            value: "live",
            label: sprintf(
              /* translators: %s: number of memories. */
              __("Saved (%s)", "mcpmate"),
              formatNumber(data?.counts.live ?? 0),
            ),
          },
          {
            value: "trash",
            label: sprintf(
              /* translators: %s: number of memories. */
              __("Trash (%s)", "mcpmate"),
              formatNumber(data?.counts.trash ?? 0),
            ),
          },
        ]}
      />
      <FilterBar
        fields={fields}
        values={memoryFilterValues(view)}
        onChange={setFilters}
      />
      {trash && data && (
        <p className="el-filter-scope">
          {sprintf(
            /* translators: %s: "30 days". */
            __(
              "Memories in the trash are deleted for good after %s.",
              "mcpmate",
            ),
            sprintf(
              /* translators: %s: number of days. */
              _n("%s day", "%s days", data.trash_days, "mcpmate"),
              formatNumber(data.trash_days),
            ),
          )}{" "}
          {data.counts.trash > 0 && (
            <Button
              variant="link"
              size="sm"
              onClick={() =>
                setPurge({ kind: "all", count: data.counts.trash })
              }
            >
              {__("Empty the trash", "mcpmate")}
            </Button>
          )}
        </p>
      )}
      {failure && (
        <Notice
          tone="danger"
          title={__("Couldn't change the memory.", "mcpmate")}
          live
        >
          {requestErrorText(failure)}
        </Notice>
      )}
      {data && !list.isError && (
        <p className="el-list-count">{memoriesText(total)}</p>
      )}
      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<Memory>
          caption={sprintf(
            /* translators: %s: the site's timezone, e.g. Asia/Dhaka. */
            trash
              ? __(
                  "Memories in the trash, newest first. Times in %s.",
                  "mcpmate",
                )
              : __(
                  "Saved memories, last changed first. Times in %s.",
                  "mcpmate",
                ),
            siteTimeZone(),
          )}
          captionRef={captionRef}
          columns={columns}
          rows={data?.items ?? []}
          rowKey={(row) => String(row.id)}
          loading={list.isPending}
          loadingRows={5}
          empty={empty}
          error={
            list.isError && (
              <Notice
                tone="danger"
                title={__("Couldn't load the memories.", "mcpmate")}
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
      {data && total > data.per_page && !list.isError && (
        <Pagination
          label={__("Memory pages", "mcpmate")}
          page={view.page}
          pageSize={data.per_page}
          total={total}
          onChange={setPage}
        />
      )}
      <MemoryDrawer
        memoryId={view.memory}
        limits={data?.limits ?? FALLBACK_LIMITS}
        onClose={() => closeDrawer("memory")}
        onPurge={(memory) => setPurge({ kind: "one", memory })}
      />
      <ConfirmDialog
        open={purge !== null}
        tone="danger"
        title={
          purge?.kind === "all"
            ? __("Empty the trash?", "mcpmate")
            : __("Delete this memory for good?", "mcpmate")
        }
        body={
          <p>
            {purge?.kind === "all"
              ? sprintf(
                  /* translators: %s: "3 memories". */
                  __(
                    "%s in the trash will be deleted. This can't be undone.",
                    "mcpmate",
                  ),
                  memoriesText(purge.count),
                )
              : sprintf(
                  /* translators: %s: memory title. */
                  __("“%s” will be deleted. This can't be undone.", "mcpmate"),
                  purge?.kind === "one" ? purge.memory.title : "",
                )}
          </p>
        }
        confirmLabel={__("Delete permanently", "mcpmate")}
        cancelLabel={__("Keep it", "mcpmate")}
        onConfirm={async () => {
          if (purge) {
            await remove.mutateAsync(purge);
          }
        }}
        onClose={() => setPurge(null)}
      />
    </div>
  );
}

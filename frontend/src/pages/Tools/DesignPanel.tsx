import { useMemo, useRef, useState } from "react";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import EmptyState from "@components/ui/EmptyState";
import Modal from "@components/ui/Modal";
import Notice from "@components/ui/Notice";
import Pagination from "@components/ui/Pagination";
import SegmentedControl from "@components/ui/SegmentedControl";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  DESIGNS_QUERY_KEY,
  fetchDesigns,
  fetchStarters,
  importDesign,
} from "@services/designs";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { DesignAuthority, DesignSummary } from "../../types/api";
import { shortDateTime, withParams } from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import DesignDrawer, { type DesignTarget } from "./DesignDrawer";
import { nameFromFile, readDesignFile, swatches } from "./designLogic";

type View = "saved" | "trash";

function Swatches({
  colors,
  label,
}: {
  colors: Record<string, string>;
  label: string;
}) {
  const list = swatches(colors).slice(0, 8);

  return list.length === 0 ? (
    <span className="text-el-text-secondary">—</span>
  ) : (
    <span className="el-design-chips" role="img" aria-label={label}>
      {list.map(([name, value]) => (
        <span
          key={name}
          className="el-design-chip"
          style={{ background: value }}
          title={`${name} ${value}`}
        />
      ))}
    </span>
  );
}

function colorsLabel(colors: Record<string, string>): string {
  return sprintf(
    /* translators: %s: colour names and values, e.g. "primary #0b5d7a, ink #13232b". */
    __("Colours: %s", "mcpmate"),
    swatches(colors)
      .slice(0, 8)
      .map(([name, value]) => `${name} ${value}`)
      .join(", "),
  );
}

/** Who owns the design, in a sentence for the notice. */
function authorityTitle(authority: DesignAuthority): string {
  if (authority.level === "builder") {
    return sprintf(
      /* translators: %s: page builder, e.g. Elementor. */
      __(
        "%s's global colours and fonts are the design system for pages built with it.",
        "mcpmate",
      ),
      authority.builders[0]?.label ?? authority.source,
    );
  }

  if (authority.level === "design" && authority.design) {
    return sprintf(
      /* translators: %s: design name. */
      __(
        "“%s” is the active design. AI apps build every page with its tokens.",
        "mcpmate",
      ),
      authority.design.name,
    );
  }

  return __(
    "No design is active. AI apps follow your theme's own styles.",
    "mcpmate",
  );
}

/**
 * Tools → Design (`#/tools/design`; MCPMate Pro): the saved designs AI apps
 * follow, the active one, who owns the design (a page builder can), and
 * the starter library. Activating a design here takes effect at once; an
 * AI app has to ask (activate_design), and you approve it.
 */
export default function DesignPanel() {
  const { search, current, go, closeDrawer } = useHashQuery();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const params = useMemo(() => new URLSearchParams(search), [search]);
  const view: View = params.get("status") === "trash" ? "trash" : "saved";
  const page = Math.max(1, Number(params.get("page")) || 1);
  const designParam = params.get("design");
  const target: DesignTarget =
    designParam === "new"
      ? "new"
      : designParam && /^[1-9]\d*$/.test(designParam)
        ? Number(designParam)
        : null;
  const [importOpen, setImportOpen] = useState(false);
  const [showLibrary, setShowLibrary] = useState(false);

  const list = useQuery({
    queryKey: [...DESIGNS_QUERY_KEY, "list", view, page],
    queryFn: () => fetchDesigns(view, page),
    placeholderData: keepPreviousData,
    staleTime: 0,
  });
  const emptySaved = view === "saved" && list.data?.counts.saved === 0;
  const libraryOpen = showLibrary || emptySaved;
  const starters = useQuery({
    queryKey: [...DESIGNS_QUERY_KEY, "library"],
    queryFn: fetchStarters,
    enabled: libraryOpen,
    staleTime: Infinity,
  });

  const open = (design: number | "new") =>
    go(withParams(current(), { design: String(design) }), { push: true });

  const adopt = useMutation({
    mutationFn: (slug: string) => importDesign({ slug }),
    onSuccess: (design) => {
      void queryClient.invalidateQueries({
        queryKey: [...DESIGNS_QUERY_KEY, "list"],
      });
      announce(
        sprintf(
          /* translators: %s: design name. */
          __("“%s” was added to your designs. It is not active.", "mcpmate"),
          design.name,
        ),
      );
      open(design.id);
    },
  });

  const data = list.data;
  const total = data?.total ?? 0;

  const columns: TableColumn<DesignSummary>[] = [
    {
      key: "name",
      title: __("Design", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <>
          <bdi>{row.name}</bdi>
          {row.description && (
            <span className="block text-el-text-secondary">
              <bdi>{row.description}</bdi>
            </span>
          )}
        </>
      ),
    },
    {
      key: "colors",
      title: __("Colours", "mcpmate"),
      render: (row) => (
        <Swatches colors={row.colors} label={colorsLabel(row.colors)} />
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (row) =>
        row.active ? (
          <StatusText tone="success">{__("Active", "mcpmate")}</StatusText>
        ) : row.status === "trash" ? (
          <StatusText tone="neutral" glyph="hollow">
            {__("In the trash", "mcpmate")}
          </StatusText>
        ) : (
          <StatusText tone="neutral" glyph="hollow">
            {__("Saved", "mcpmate")}
          </StatusText>
        ),
    },
    {
      key: "updated",
      title: __("Updated", "mcpmate"),
      render: (row) => (
        <time dateTime={row.updated_at}>{shortDateTime(row.updated_at)}</time>
      ),
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) => (
        <Button
          variant="link"
          onClick={() => open(row.id)}
          label={sprintf(
            /* translators: %s: design name. */
            __("Open %s", "mcpmate"),
            row.name,
          )}
        >
          {__("Open", "mcpmate")}
        </Button>
      ),
    },
  ];

  return (
    <div className="el-stack">
      <h2 className="sr-only">{__("Design", "mcpmate")}</h2>
      <p className="el-tools-intro">
        {__(
          "The look AI apps follow when they build pages: colours, fonts, sizes and spacing, with guidance in plain words. Activating a design changes only what AI apps are told; nothing on your pages changes by itself.",
          "mcpmate",
        )}
      </p>

      {data && (
        <Notice tone="info" title={authorityTitle(data.authority)}>
          {data.authority.level === "builder" ? data.authority.note : null}
        </Notice>
      )}

      <div className="el-tools-toolbar">
        <SegmentedControl<View>
          label={__("Show", "mcpmate")}
          value={view}
          onChange={(next) =>
            go(
              withParams(current(), {
                status: next === "trash" ? "trash" : null,
                page: null,
              }),
            )
          }
          options={[
            {
              value: "saved",
              label: data
                ? sprintf(
                    /* translators: %s: number of designs. */
                    __("Saved (%s)", "mcpmate"),
                    formatNumber(data.counts.saved),
                  )
                : __("Saved", "mcpmate"),
            },
            {
              value: "trash",
              label: data
                ? sprintf(
                    /* translators: %s: number of designs. */
                    __("Trash (%s)", "mcpmate"),
                    formatNumber(data.counts.trash),
                  )
                : __("Trash", "mcpmate"),
            },
          ]}
        />
        <div className="el-design-actions">
          <Button variant="primary" onClick={() => open("new")}>
            {__("New design", "mcpmate")}
          </Button>
          <Button variant="secondary" onClick={() => setImportOpen(true)}>
            {__("Import", "mcpmate")}
          </Button>
          <Button
            variant="secondary"
            aria-expanded={libraryOpen}
            aria-controls="el-design-library"
            disabled={emptySaved}
            onClick={() => setShowLibrary((open) => !open)}
          >
            {__("Starter designs", "mcpmate")}
          </Button>
        </div>
      </div>

      <div aria-busy={list.isFetching && !list.isPending ? true : undefined}>
        <Table<DesignSummary>
          caption={__("Saved designs, most recently changed first.", "mcpmate")}
          columns={columns}
          rows={data?.items ?? []}
          rowKey={(row) => String(row.id)}
          loading={list.isPending}
          loadingRows={3}
          empty={
            view === "trash" ? (
              <EmptyState title={__("The trash is empty.", "mcpmate")} />
            ) : (
              <EmptyState
                title={__("No designs yet.", "mcpmate")}
                body={__(
                  "Start from a starter design below, import a design file, or write your own. An AI app can also draft one for you.",
                  "mcpmate",
                )}
              />
            )
          }
          error={
            list.isError && (
              <Notice
                tone="danger"
                title={__("Couldn't load the designs.", "mcpmate")}
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
      {data && total > data.per_page && (
        <Pagination
          label={__("Design pages", "mcpmate")}
          page={page}
          pageSize={data.per_page}
          total={total}
          onChange={(next) =>
            go(withParams(current(), { page: next > 1 ? String(next) : null }))
          }
        />
      )}

      {libraryOpen && (
        <section
          id="el-design-library"
          aria-labelledby="el-design-library-title"
          className="el-stack"
        >
          <h3 id="el-design-library-title" className="el-setting-label">
            {__("Starter designs", "mcpmate")}
          </h3>
          <p className="el-setting-hint">
            {__(
              "Original designs to start from. Adding one makes a copy you can change; it is not activated.",
              "mcpmate",
            )}
          </p>
          {adopt.isError && (
            <Notice
              tone="danger"
              live
              title={__("Couldn't add that design.", "mcpmate")}
            >
              {requestErrorText(adopt.error)}
            </Notice>
          )}
          {starters.isPending ? null : starters.isError ? (
            <Notice
              tone="danger"
              title={__("Couldn't load the starter designs.", "mcpmate")}
            >
              {requestErrorText(starters.error)}
            </Notice>
          ) : (
            <ul className="el-design-library">
              {(starters.data ?? []).map((starter) => (
                <li key={starter.slug} className="el-design-card">
                  <Swatches
                    colors={starter.colors}
                    label={colorsLabel(starter.colors)}
                  />
                  <h4 className="el-design-card-title">
                    <bdi>{starter.name}</bdi>
                  </h4>
                  <p className="m-0 text-el-text-secondary">
                    {starter.description}
                  </p>
                  <Button
                    variant="secondary"
                    size="sm"
                    loading={
                      adopt.isPending && adopt.variables === starter.slug
                    }
                    disabled={adopt.isPending}
                    onClick={() => adopt.mutate(starter.slug)}
                    label={sprintf(
                      /* translators: %s: design name. */
                      __("Add %s to my designs", "mcpmate"),
                      starter.name,
                    )}
                  >
                    {__("Add to my designs", "mcpmate")}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <DesignDrawer
        target={target}
        onClose={() => closeDrawer("design")}
        onCreated={(design) =>
          go(withParams(current(), { design: String(design.id) }))
        }
      />
      <ImportDialog
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onImported={(id) => {
          setImportOpen(false);
          void queryClient.invalidateQueries({
            queryKey: [...DESIGNS_QUERY_KEY, "list"],
          });
          open(id);
        }}
      />
    </div>
  );
}

/** Import a design from a .md file or pasted Markdown, as a new design. */
function ImportDialog({
  open,
  onClose,
  onImported,
}: {
  open: boolean;
  onClose: () => void;
  onImported: (id: number) => void;
}) {
  const [name, setName] = useState("");
  const [content, setContent] = useState("");
  const [fileError, setFileError] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);
  const announce = useAnnounce();
  const run = useMutation({
    mutationFn: () => importDesign({ name, content }),
    onSuccess: (design) => {
      announce(
        sprintf(
          /* translators: %s: design name. */
          __("“%s” was added to your designs. It is not active.", "mcpmate"),
          design.name,
        ),
      );
      setName("");
      setContent("");
      onImported(design.id);
    },
  });

  return (
    <Modal
      title={__("Import a design", "mcpmate")}
      open={open}
      onClose={onClose}
      closable={!run.isPending}
      footer={
        <div className="el-dialog-actions">
          <Button
            variant="secondary"
            disabled={run.isPending}
            onClick={onClose}
          >
            {__("Cancel", "mcpmate")}
          </Button>
          <Button
            variant="primary"
            loading={run.isPending}
            disabled={content.trim() === ""}
            onClick={() => run.mutate()}
          >
            {__("Import design", "mcpmate")}
          </Button>
        </div>
      }
    >
      <div className="el-stack">
        {run.isError && (
          <Notice
            tone="danger"
            live
            title={__("Couldn't import it. Nothing was saved.", "mcpmate")}
          >
            {requestErrorText(run.error)}
          </Notice>
        )}
        <div>
          <label htmlFor="el-design-file" className="el-setting-label">
            {__("Design file", "mcpmate")}
          </label>
          <p id="el-design-file-hint" className="el-setting-hint">
            {__(
              "A Markdown file (.md) with the tokens in its front matter. Or paste the text below.",
              "mcpmate",
            )}
          </p>
          <input
            id="el-design-file"
            ref={fileInput}
            type="file"
            accept=".md,.markdown,.txt,text/markdown,text/plain"
            aria-describedby="el-design-file-hint"
            onChange={async (event) => {
              const file = event.target.files?.[0];
              if (!file) {
                return;
              }
              const read = await readDesignFile(file);
              if ("error" in read) {
                setFileError(read.error);
                return;
              }
              setFileError("");
              setContent(read.content);
              if (name === "") {
                setName(nameFromFile(file.name));
              }
            }}
          />
          {fileError && (
            <p className="el-field-error" role="alert">
              {fileError}
            </p>
          )}
        </div>
        <div>
          <label htmlFor="el-design-import-name" className="el-setting-label">
            {__("Name", "mcpmate")}
          </label>
          <input
            id="el-design-import-name"
            type="text"
            className="el-input"
            maxLength={100}
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </div>
        <div>
          <label
            htmlFor="el-design-import-content"
            className="el-setting-label"
          >
            {__("Markdown", "mcpmate")}
          </label>
          <textarea
            id="el-design-import-content"
            className="el-input el-mono"
            rows={10}
            spellCheck={false}
            value={content}
            onChange={(event) => setContent(event.target.value)}
          />
        </div>
      </div>
    </Modal>
  );
}

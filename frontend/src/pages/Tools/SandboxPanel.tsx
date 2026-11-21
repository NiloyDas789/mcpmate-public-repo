import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import Drawer from "@components/ui/Drawer";
import EmptyState from "@components/ui/EmptyState";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import StatusText from "@components/ui/StatusText";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  SANDBOX_QUERY_KEY,
  disableSandbox,
  fetchSandbox,
  retrySandbox,
  showSandbox,
} from "@services/sandbox";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { SandboxPlugin } from "../../types/api";
import { withParams } from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import {
  crashText,
  formatSize,
  sandboxStatusLabel,
  sandboxStatusTone,
} from "./sandboxLogic";

/**
 * Tools → Sandbox (`#/tools/sandbox`, MCPMate Pro): the small PHP plugins
 * AI apps wrote in wp-content/mcpmate-sandbox, whether each runs, the
 * error that stopped one, and its files. A site admin disables one here, or
 * loads a crashed one again. Nothing is enabled from this page: a plugin
 * runs only after its code is approved on Activity → Approvals. The open
 * plugin and file live in the hash query (`?plugin=&file=`).
 */
export default function SandboxPanel() {
  const { search, current, go, closeDrawer } = useHashQuery();
  const params = new URLSearchParams(search);
  const openSlug = params.get("plugin");
  const openFile = params.get("file");
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [retrying, setRetrying] = useState<SandboxPlugin | null>(null);

  const list = useQuery({
    queryKey: [...SANDBOX_QUERY_KEY, "list"],
    queryFn: fetchSandbox,
    staleTime: 0,
  });
  const data = list.data;
  const refresh = () =>
    void queryClient.invalidateQueries({ queryKey: SANDBOX_QUERY_KEY });

  const disable = useMutation({
    mutationFn: (plugin: SandboxPlugin) => disableSandbox(plugin.slug),
    onSuccess: (plugin) => {
      refresh();
      announce(
        sprintf(
          /* translators: %s: sandbox plugin name. */
          __("“%s” is off. It no longer runs.", "mcpmate"),
          plugin.name,
        ),
      );
    },
  });
  const retry = useMutation({
    mutationFn: (plugin: SandboxPlugin) => retrySandbox(plugin.slug),
    onSuccess: (plugin) => {
      refresh();
      announce(
        sprintf(
          /* translators: %s: sandbox plugin name. */
          __("“%s” runs again from the next page load.", "mcpmate"),
          plugin.name,
        ),
      );
    },
  });

  const open = (plugin: SandboxPlugin) =>
    go(withParams(current(), { plugin: plugin.slug, file: null }), {
      push: true,
    });

  const columns: TableColumn<SandboxPlugin>[] = [
    {
      key: "name",
      title: __("Plugin", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <span className="el-stack-tight">
          <Button variant="link" size="sm" onClick={() => open(row)}>
            {row.name}
          </Button>
          {row.description && (
            <span className="el-setting-hint">{row.description}</span>
          )}
          <bdi dir="ltr" className="el-mono el-setting-hint">
            {row.slug}
          </bdi>
        </span>
      ),
    },
    {
      key: "status",
      title: __("Status", "mcpmate"),
      render: (row) => (
        <span className="el-stack-tight">
          <StatusText tone={sandboxStatusTone(row.status)}>
            {sandboxStatusLabel(row.status)}
          </StatusText>
          {row.crash && (
            <span className="el-setting-hint">
              {row.status === "crashed"
                ? sprintf(
                    /* translators: %s: the PHP error. */
                    __("Error: %s", "mcpmate"),
                    crashText(row.crash),
                  )
                : sprintf(
                    /* translators: %s: the PHP error. */
                    __("Last error: %s", "mcpmate"),
                    crashText(row.crash),
                  )}
            </span>
          )}
          {row.status === "changed" && (
            <span className="el-setting-hint">
              {__(
                "Its files are no longer the ones you approved, so it is skipped.",
                "mcpmate",
              )}
            </span>
          )}
        </span>
      ),
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
              /* translators: %s: sandbox plugin name. */
              __("View files: %s", "mcpmate"),
              row.name,
            )}
            onClick={() => open(row)}
          >
            {__("View files", "mcpmate")}
          </Button>
          {row.status === "crashed" && (
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: sandbox plugin name. */
                __("Run again: %s", "mcpmate"),
                row.name,
              )}
              onClick={() => setRetrying(row)}
            >
              {__("Run again", "mcpmate")}
            </Button>
          )}
          {row.status !== "disabled" && (
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: sandbox plugin name. */
                __("Turn off: %s", "mcpmate"),
                row.name,
              )}
              disabled={disable.isPending}
              onClick={() => disable.mutate(row)}
            >
              {__("Turn off", "mcpmate")}
            </Button>
          )}
        </span>
      ),
    },
  ];

  const running = data?.plugins.some((plugin) => plugin.status !== "disabled");

  return (
    <div className="el-activity">
      <p className="el-tools-intro">
        {__(
          "Sandbox plugins are small PHP plugins your AI apps write in wp-content/mcpmate-sandbox, outside your installed plugins. One runs only after you approve its code on Activity → Approvals, and stops by itself if it causes a fatal error.",
          "mcpmate",
        )}
      </p>
      {data && !data.available && (
        <Notice
          tone="warning"
          title={__("Sandbox plugins are off on this site.", "mcpmate")}
        >
          {__(
            "wp-config.php sets DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS, so no sandbox plugin runs and AI apps can't write any.",
            "mcpmate",
          )}
        </Notice>
      )}
      {data?.available &&
        running &&
        (data.loader === "missing" || data.loader === "foreign") && (
          <Notice
            tone="danger"
            title={__("The sandbox loader is missing.", "mcpmate")}
          >
            {__(
              "wp-content/mu-plugins/mcpmate-sandbox-loader.php is not MCPMate's loader, so no sandbox plugin runs. It is put back the next time you approve a sandbox plugin.",
              "mcpmate",
            )}
          </Notice>
        )}
      {(disable.error || retry.error) && (
        <Notice tone="danger" title={__("That didn't work.", "mcpmate")} live>
          {requestErrorText(disable.error ?? retry.error)}
        </Notice>
      )}
      <Table<SandboxPlugin>
        caption={
          data
            ? sprintf(
                /* translators: 1: number of sandbox plugins, 2: the most there can be. */
                _n(
                  "%1$s sandbox plugin of at most %2$s.",
                  "%1$s sandbox plugins of at most %2$s.",
                  data.plugins.length,
                  "mcpmate",
                ),
                formatNumber(data.plugins.length),
                formatNumber(data.limits.plugins),
              )
            : __("Sandbox plugins.", "mcpmate")
        }
        columns={columns}
        rows={data?.plugins ?? []}
        rowKey={(row) => row.slug}
        loading={list.isPending}
        loadingRows={2}
        empty={
          <EmptyState
            title={__("No sandbox plugins yet.", "mcpmate")}
            body={__(
              "When an AI app needs a small piece of PHP on this site, such as a filter or a shortcode, it writes one here with create_sandbox_plugin. Turn the Sandbox plugins tools on under Core first.",
              "mcpmate",
            )}
          />
        }
        error={
          list.isError && (
            <Notice
              tone="danger"
              title={__("Couldn't load the sandbox plugins.", "mcpmate")}
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
      <SandboxFilesDrawer
        slug={openSlug}
        file={openFile}
        onFile={(path) =>
          go(withParams(current(), { file: path }), { push: false })
        }
        onClose={() => closeDrawer("plugin")}
      />
      <ConfirmDialog
        open={retrying !== null}
        tone="danger"
        title={__("Run this plugin again?", "mcpmate")}
        body={
          <p>
            {sprintf(
              /* translators: 1: sandbox plugin name, 2: the PHP error. */
              __(
                "“%1$s” stopped after this error: %2$s. If the code is unchanged it may stop again on the next page load.",
                "mcpmate",
              ),
              retrying?.name ?? "",
              retrying?.crash ? crashText(retrying.crash) : "",
            )}
          </p>
        }
        confirmLabel={__("Run again", "mcpmate")}
        cancelLabel={__("Keep it stopped", "mcpmate")}
        onConfirm={async () => {
          if (retrying) {
            await retry.mutateAsync(retrying);
          }
        }}
        onClose={() => setRetrying(null)}
      />
    </div>
  );
}

/** One plugin's files, and the chosen file's code as plain text. */
function SandboxFilesDrawer({
  slug,
  file,
  onFile,
  onClose,
}: {
  slug: string | null;
  file: string | null;
  onFile: (path: string) => void;
  onClose: () => void;
}) {
  const shown = useQuery({
    queryKey: [...SANDBOX_QUERY_KEY, "show", slug, file],
    queryFn: () => showSandbox(slug ?? "", file ?? undefined),
    enabled: slug !== null,
  });
  const data = shown.data;

  return (
    <Drawer
      title={data?.plugin.name ?? __("Sandbox plugin", "mcpmate")}
      open={slug !== null}
      onClose={onClose}
      size="lg"
    >
      {shown.isPending && <Skeleton count={6} />}
      {shown.isError && (
        <Notice tone="danger" title={__("Couldn't load the files.", "mcpmate")}>
          {requestErrorText(shown.error)}
        </Notice>
      )}
      {data && (
        <div className="el-stack">
          <p className="m-0">
            {sprintf(
              /* translators: 1: number of files, 2: total size, 3: main file name. */
              _n(
                "%1$s file, %2$s. WordPress loads %3$s.",
                "%1$s files, %2$s. WordPress loads %3$s.",
                data.count,
                "mcpmate",
              ),
              formatNumber(data.count),
              formatSize(data.bytes),
              data.plugin.main,
            )}
          </p>
          <ul className="el-stack-tight m-0 list-none p-0">
            {data.entries.map((entry) => (
              <li key={entry.path}>
                <Button
                  variant="link"
                  size="sm"
                  aria-current={entry.path === file ? "true" : undefined}
                  onClick={() => onFile(entry.path)}
                >
                  <bdi dir="ltr" className="el-mono">
                    {entry.path}
                  </bdi>
                </Button>{" "}
                <span className="el-setting-hint">
                  {formatSize(entry.size)}
                </span>
              </li>
            ))}
          </ul>
          {data.file && (
            <section className="el-stack-tight">
              <h3 className="m-0">
                <bdi dir="ltr" className="el-mono">
                  {data.file.path}
                </bdi>
              </h3>
              <pre
                className="el-json el-code-block"
                tabIndex={0}
                role="region"
                aria-label={sprintf(
                  /* translators: %s: file path. */ __("Code of %s", "mcpmate"),
                  data.file.path,
                )}
                dir="ltr"
              >
                {data.file.content}
              </pre>
              {data.file.clipped && (
                <p className="el-setting-hint m-0">
                  {__(
                    "Only the start of this file is shown. The whole file is in wp-content/mcpmate-sandbox.",
                    "mcpmate",
                  )}
                </p>
              )}
            </section>
          )}
        </div>
      )}
    </Drawer>
  );
}

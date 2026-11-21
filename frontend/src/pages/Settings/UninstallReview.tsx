import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchUninstallExport, fetchUninstallSummary } from "@services/api";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { UninstallExportKind, UninstallSummary } from "../../types/api";
import {
  exportAll,
  exportFileName,
  saveJson,
  sizeText,
} from "./uninstallLogic";

const EXPORTS: { kind: UninstallExportKind; label: () => string }[] = [
  { kind: "audit_log", label: () => __("Download audit log", "mcpmate") },
  { kind: "changes", label: () => __("Download change history", "mcpmate") },
];

type ExportState =
  | { kind: "idle" }
  | { kind: "busy"; what: UninstallExportKind; done: number; total: number }
  | { kind: "error"; what: UninstallExportKind; message: string };

/** What stays on the site after the plugin is deleted (rule 3). */
function Stays({ summary }: { summary: UninstallSummary }) {
  const lines: string[] = [
    summary.theme_drafts > 0 &&
      sprintf(
        /* translators: %s: number of theme drafts. */
        _n(
          "%s theme draft, in your themes folder",
          "%s theme drafts, in your themes folder",
          summary.theme_drafts,
          "mcpmate",
        ),
        formatNumber(summary.theme_drafts),
      ),
    summary.theme_backups.count > 0 &&
      sprintf(
        /* translators: 1: number of backups, 2: their size, e.g. "4.3 MB". */
        _n(
          "%1$s theme backup (%2$s), in uploads/mcpmate/theme-backups",
          "%1$s theme backups (%2$s), in uploads/mcpmate/theme-backups",
          summary.theme_backups.count,
          "mcpmate",
        ),
        formatNumber(summary.theme_backups.count),
        sizeText(summary.theme_backups.bytes),
      ),
    summary.sandbox.plugins > 0 &&
      sprintf(
        /* translators: 1: number of sandbox plugins, 2: number of files. */
        _n(
          "%1$s sandbox plugin folder (%2$s files), in wp-content/mcpmate-sandbox. Nothing loads it once MCPMate is gone.",
          "%1$s sandbox plugin folders (%2$s files), in wp-content/mcpmate-sandbox. Nothing loads them once MCPMate is gone.",
          summary.sandbox.plugins,
          "mcpmate",
        ),
        formatNumber(summary.sandbox.plugins),
        summary.sandbox.more
          ? sprintf(
              /* translators: %s: a number, e.g. "5,000". */
              __("over %s", "mcpmate"),
              formatNumber(summary.sandbox.files),
            )
          : formatNumber(summary.sandbox.files),
      ),
    summary.designs > 0 &&
      sprintf(
        /* translators: %s: number of saved design posts. */
        _n(
          "%s saved design, with its revisions",
          "%s saved designs, with their revisions",
          summary.designs,
          "mcpmate",
        ),
        formatNumber(summary.designs),
      ),
    summary.keeps.length > 0 &&
      sprintf(
        /* translators: %s: option names, comma-separated. */
        __("These settings, which point at the files above: %s", "mcpmate"),
        summary.keeps.join(", "),
      ),
  ]
    .filter((line) => line !== false)
    .map(String);

  if (lines.length === 0) {
    return (
      <p className="m-0">
        {__("Nothing: MCPMate has no files or posts on this site.", "mcpmate")}
      </p>
    );
  }

  return (
    <ul className="el-uninstall-list">
      {lines.map((line) => (
        <li key={line}>{line}</li>
      ))}
    </ul>
  );
}

function Deleted({ summary }: { summary: UninstallSummary }) {
  return (
    <>
      <table className="el-uninstall-table">
        <caption className="sr-only">
          {__("Tables and their rows", "mcpmate")}
        </caption>
        <thead>
          <tr>
            <th scope="col">{__("Table", "mcpmate")}</th>
            <th scope="col">{__("Rows", "mcpmate")}</th>
          </tr>
        </thead>
        <tbody>
          {summary.tables.map((table) => (
            <tr key={table.name}>
              <td>
                <code>{table.name}</code>
              </td>
              <td>{formatNumber(table.rows)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="el-drawer-line">
        {sprintf(
          /* translators: %s: number of settings. */
          _n(
            "And %s setting: every AI app's access, the tool switches and your choices on this page.",
            "And %s settings: every AI app's access, the tool switches and your choices on this page.",
            summary.options.length,
            "mcpmate",
          ),
          formatNumber(summary.options.length),
        )}
      </p>
    </>
  );
}

/**
 * Before you uninstall (P21; Plugins screen → Review data before deleting,
 * `#/settings?section=advanced&review=uninstall`): what Remove all data on
 * uninstall deletes from this site, what stays, and a JSON copy of the
 * audit log and change history first. The copy is put together in the
 * browser, a page at a time; nothing is written on the server.
 */
export default function UninstallReview({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const announce = useAnnounce();
  const [state, setState] = useState<ExportState>({ kind: "idle" });
  const summary = useQuery({
    queryKey: ["uninstall-summary"],
    queryFn: fetchUninstallSummary,
    enabled: open,
    staleTime: 0,
  });

  const download = async (what: UninstallExportKind) => {
    setState({ kind: "busy", what, done: 0, total: 0 });
    try {
      const file = await exportAll(what, fetchUninstallExport, (done, total) =>
        setState({ kind: "busy", what, done, total }),
      );
      saveJson(exportFileName(what, new Date()), file);
      setState({ kind: "idle" });
      announce(
        sprintf(
          /* translators: %s: number of entries. */
          _n(
            "Downloaded %s entry.",
            "Downloaded %s entries.",
            file.items.length,
            "mcpmate",
          ),
          formatNumber(file.items.length),
        ),
      );
    } catch (caught) {
      setState({ kind: "error", what, message: requestErrorText(caught) });
    }
  };

  const body = () => {
    if (summary.isLoading) {
      return <Skeleton count={4} />;
    }

    if (!summary.data) {
      return (
        <Notice
          tone="danger"
          title={__(
            "Couldn't read what MCPMate keeps on this site.",
            "mcpmate",
          )}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void summary.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(summary.error)}
        </Notice>
      );
    }

    const data = summary.data;

    return (
      <>
        {data.delete_data ? (
          <p className="el-drawer-meta">
            {__(
              "Remove all data on uninstall is on. When you delete MCPMate on the Plugins screen, this is what happens.",
              "mcpmate",
            )}
          </p>
        ) : (
          <Notice tone="info" title={__("Nothing will be deleted.", "mcpmate")}>
            {__(
              "Remove all data on uninstall is off, so deleting MCPMate keeps everything below for a reinstall. Turn it on in Advanced to delete it.",
              "mcpmate",
            )}
          </Notice>
        )}
        {data.multisite && (
          <p className="el-drawer-line">
            {__(
              "This is this site's data; each site of the network has its own.",
              "mcpmate",
            )}
          </p>
        )}

        <h3 className="el-drawer-heading">
          {data.delete_data
            ? __("Deleted with the plugin", "mcpmate")
            : __("Would be deleted with it on", "mcpmate")}
        </h3>
        <Deleted summary={data} />

        <h3 className="el-drawer-heading">
          {__("Stays on this site", "mcpmate")}
        </h3>
        <Stays summary={data} />

        <h3 className="el-drawer-heading">
          {__("Keep a copy first", "mcpmate")}
        </h3>
        <p className="el-drawer-meta">
          {__(
            "A JSON file your browser saves. Values MCPMate masked when it logged them stay masked.",
            "mcpmate",
          )}
        </p>
        <div className="el-uninstall-exports">
          {EXPORTS.map(({ kind, label }) => {
            const busy = state.kind === "busy" && state.what === kind;

            return (
              <Button
                key={kind}
                variant="secondary"
                loading={busy}
                disabled={state.kind === "busy" && !busy}
                onClick={() => void download(kind)}
              >
                {busy && state.total > 0
                  ? sprintf(
                      /* translators: 1: entries exported so far, 2: entries in the export. */
                      __("Exporting %1$s of %2$s…", "mcpmate"),
                      formatNumber(state.done),
                      formatNumber(state.total),
                    )
                  : label()}
              </Button>
            );
          })}
        </div>
        {state.kind === "error" && (
          <div className="el-drawer-line">
            <Notice
              tone="danger"
              title={
                state.what === "changes"
                  ? __("Couldn't export the change history.", "mcpmate")
                  : __("Couldn't export the audit log.", "mcpmate")
              }
              live
            >
              {state.message}
            </Notice>
          </div>
        )}
      </>
    );
  };

  return (
    <Drawer
      title={__("Before you uninstall", "mcpmate")}
      open={open}
      onClose={onClose}
    >
      {body()}
    </Drawer>
  );
}

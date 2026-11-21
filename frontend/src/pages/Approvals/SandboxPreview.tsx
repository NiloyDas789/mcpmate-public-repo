import { __, _n, sprintf } from "@wordpress/i18n";
import Notice from "@components/ui/Notice";
import { formatNumber } from "@utils/format";
import type { SandboxApprovalPreview } from "../../types/api";
import { formatSize } from "../Tools/sandboxLogic";

/**
 * An enable_sandbox_plugin request's preview: what approving runs, every
 * file of the plugin with its code, as plain text (never rendered). The
 * admin approves exactly these files; the server pinned their SHA-256 and
 * refuses the approval if any of them changed since.
 */
export default function SandboxPreview({
  preview,
}: {
  preview: SandboxApprovalPreview;
}) {
  return (
    <div className="el-stack">
      <Notice
        tone="warning"
        title={sprintf(
          /* translators: %s: sandbox plugin name. */
          __("Approving runs “%s” on every page of this site.", "mcpmate"),
          preview.plugin.name,
        )}
      >
        {preview.note}
      </Notice>
      <p className="m-0">
        {sprintf(
          /* translators: 1: number of files, 2: total size, 3: main file name. */
          _n(
            "%1$s file, %2$s. WordPress loads %3$s, which can include the others.",
            "%1$s files, %2$s. WordPress loads %3$s, which can include the others.",
            preview.file_count,
            "mcpmate",
          ),
          formatNumber(preview.file_count),
          formatSize(preview.total_bytes),
          preview.main,
        )}
      </p>
      {preview.files.map((file) => (
        <section key={file.path} className="el-stack-tight">
          <h4 className="m-0">
            <bdi dir="ltr" className="el-mono">
              {file.path}
            </bdi>{" "}
            <span className="el-setting-hint">{formatSize(file.bytes)}</span>
          </h4>
          <pre
            className="el-json el-code-block"
            tabIndex={0}
            role="region"
            aria-label={sprintf(
              /* translators: %s: file path. */ __("Code of %s", "mcpmate"),
              file.path,
            )}
            dir="ltr"
          >
            {file.content}
          </pre>
        </section>
      ))}
    </div>
  );
}

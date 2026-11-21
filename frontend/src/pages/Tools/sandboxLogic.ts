import { __, sprintf } from "@wordpress/i18n";
import type { StatusTone } from "@components/ui/StatusDot";
import { formatNumber } from "@utils/format";
import type {
  SandboxApprovalPreview,
  SandboxCrash,
  SandboxStatus,
} from "../../types/api";

/** "412 bytes", "1.8 KB". */
export function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return sprintf(
      /* translators: %s: number of bytes. */ __("%s bytes", "mcpmate"),
      formatNumber(bytes),
    );
  }

  return sprintf(
    /* translators: %s: size in kilobytes. */ __("%s KB", "mcpmate"),
    formatNumber(Math.round(bytes / 102.4) / 10, { maximumFractionDigits: 1 }),
  );
}

export function sandboxStatusLabel(status: SandboxStatus): string {
  switch (status) {
    case "enabled":
      return __("Running", "mcpmate");
    case "crashed":
      return __("Stopped after an error", "mcpmate");
    case "changed":
      return __("Stopped: files changed", "mcpmate");
    case "disabled":
      return __("Off", "mcpmate");
  }
}

export function sandboxStatusTone(status: SandboxStatus): StatusTone {
  switch (status) {
    case "enabled":
      return "success";
    case "crashed":
    case "changed":
      return "danger";
    case "disabled":
      return "neutral";
  }
}

/** "Call to undefined function x() (booking/inc/form.php, line 12)". */
export function crashText(crash: SandboxCrash): string {
  return crash.file === ""
    ? crash.message
    : sprintf(
        /* translators: 1: PHP error message, 2: file path, 3: line number. */
        __("%1$s (%2$s, line %3$s)", "mcpmate"),
        crash.message,
        crash.file,
        formatNumber(crash.line),
      );
}

/** True for the preview an enable_sandbox_plugin approval stores. */
export function isSandboxPreview(
  value: unknown,
): value is SandboxApprovalPreview {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const files = (value as { files?: unknown }).files;

  return (
    Array.isArray(files) &&
    files.every(
      (file) =>
        typeof file === "object" &&
        file !== null &&
        typeof (file as { path?: unknown }).path === "string" &&
        typeof (file as { content?: unknown }).content === "string",
    )
  );
}

import { __ } from "@wordpress/i18n";
import type { StatusGlyph, StatusTone } from "@components/ui/StatusDot";
import type { ApprovalListItem, ApprovalStatus } from "../../types/api";

/** The words of docs/ui.md §8.1: "Waiting for approval", "Run", not "Pending" or "Executed". */
export function statusLabel(status: ApprovalStatus): string {
  switch (status) {
    case "pending":
      return __("Waiting for approval", "mcpmate");
    case "approved":
      return __("Approved", "mcpmate");
    case "executed":
      return __("Run", "mcpmate");
    case "rejected":
      return __("Rejected", "mcpmate");
    case "expired":
      return __("Expired", "mcpmate");
  }
}

/** The dot next to a status: never the only sign of it. */
export function statusTone(status: ApprovalStatus): {
  tone: StatusTone;
  glyph?: StatusGlyph;
} {
  switch (status) {
    case "pending":
      return { tone: "warning" };
    case "approved":
    case "executed":
      return { tone: "success" };
    case "rejected":
      return { tone: "danger" };
    case "expired":
      return { tone: "neutral", glyph: "hollow" };
  }
}

/**
 * What the request runs, in one line: the command for `cli`, the ability
 * for `abilities`, otherwise `tool action`.
 */
export function describeRequest(
  approval: Pick<ApprovalListItem, "tool" | "action" | "args">,
): string {
  const { command, name } = approval.args;

  if (approval.tool === "cli" && typeof command === "string") {
    return command;
  }

  if (approval.tool === "abilities" && typeof name === "string") {
    return `ability ${name}`;
  }

  return `${approval.tool} ${approval.action}`.trim();
}

/**
 * The name of what runs, for the Approve button ("Approve and run
 * search-replace"): the command's leading words for `cli` (at most two,
 * before any argument), the ability's name, else the action.
 */
export function requestName(
  approval: Pick<ApprovalListItem, "tool" | "action" | "args">,
): string {
  const { command, name } = approval.args;

  if (approval.tool === "cli" && typeof command === "string") {
    const words: string[] = [];
    for (const word of command.trim().split(/\s+/)) {
      if (words.length === 2 || !/^[a-z][a-z-]*$/.test(word)) {
        break;
      }
      words.push(word);
    }

    return words.join(" ") || command;
  }

  if (approval.tool === "abilities" && typeof name === "string") {
    return name;
  }

  return approval.action || approval.tool;
}

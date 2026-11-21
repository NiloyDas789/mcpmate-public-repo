import { __, sprintf } from "@wordpress/i18n";
import { formatNumber } from "@utils/format";
import type { MemoriesPage, MemoryStatus } from "../../types/api";

/**
 * Tools → Memory (MCPMate Pro): its state in the hash query, and the checks
 * an edit runs before it is sent, with the words MemoriesController uses.
 */

export interface MemoryView {
  status: MemoryStatus;
  user: number;
  tag: string;
  q: string;
  page: number;
  /** The memory open in the drawer. */
  memory: number | null;
}

const positive = (value: string | null): number => {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : 0;
};

export function memoryViewFromQuery(query: string): MemoryView {
  const params = new URLSearchParams(query);

  return {
    status: params.get("status") === "trash" ? "trash" : "live",
    user: positive(params.get("user")),
    tag: params.get("tag") ?? "",
    q: params.get("q") ?? "",
    page: positive(params.get("page")) || 1,
    memory: positive(params.get("memory")) || null,
  };
}

export const memoryFilterValues = (view: MemoryView) => ({
  user: view.user ? String(view.user) : "",
  tag: view.tag,
  q: view.q,
});

/** Tags as typed: comma-separated, blanks dropped (the server lowercases them). */
export function parseTags(text: string): string[] {
  return text
    .split(",")
    .map((tag) => tag.trim())
    .filter((tag) => tag !== "");
}

export function contentBytes(text: string): number {
  return new TextEncoder().encode(text.replace(/\r\n?/g, "\n").trim()).length;
}

export interface MemoryDraft {
  title: string;
  content: string;
  tags: string;
}

export type MemoryErrors = Partial<Record<keyof MemoryDraft, string>>;

/** What the server would refuse, in its words (apart from secrets, which only it checks). */
export function memoryErrors(
  draft: MemoryDraft,
  limits: MemoriesPage["limits"],
): MemoryErrors {
  const errors: MemoryErrors = {};
  const title = draft.title.trim();
  const tags = parseTags(draft.tags);
  const bytes = contentBytes(draft.content);

  if (title === "" || [...title].length > limits.title_chars) {
    errors.title = sprintf(
      /* translators: %s: the limit in characters. */
      __("Give a title of one line, up to %s characters.", "mcpmate"),
      formatNumber(limits.title_chars),
    );
  }

  if (bytes === 0 || bytes > limits.content_bytes) {
    errors.content = sprintf(
      /* translators: %s: the limit in bytes. */
      __("Write the memory as plain text, up to %s bytes (16 KB).", "mcpmate"),
      formatNumber(limits.content_bytes),
    );
  }

  if (
    tags.length > limits.tags ||
    tags.some((tag) => [...tag].length > limits.tag_chars)
  ) {
    errors.tags = sprintf(
      /* translators: 1: most tags, 2: most characters per tag. */
      __(
        'Use up to %1$s tags of up to %2$s characters: lowercase letters, digits, "-" and "_".',
        "mcpmate",
      ),
      formatNumber(limits.tags),
      formatNumber(limits.tag_chars),
    );
  }

  return errors;
}

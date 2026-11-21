import { formatNumber } from "@utils/format";
import type { UninstallExportKind, UninstallExportPage } from "../../types/api";

/** The query that opens the review (Plugins screen → Review data before deleting). */
export const REVIEW_PARAM = "review";
export const REVIEW_VALUE = "uninstall";

/** One downloaded file: every page of the audit log or change history. */
export interface ExportFile {
  kind: UninstallExportKind;
  exported_at: string;
  total: number;
  items: Record<string, unknown>[];
}

/**
 * Every page of `kind`, one after another, put together into one file in
 * the browser: nothing is written on the server. The first page fixes the
 * last ID of the export (`until`) and every later page names it, so rows
 * added meanwhile stay out and none is fetched twice. `onPage` hears how
 * many rows have arrived of `total`.
 */
export async function exportAll(
  kind: UninstallExportKind,
  fetchPage: (
    kind: UninstallExportKind,
    after: number,
    until: number,
  ) => Promise<UninstallExportPage>,
  onPage: (done: number, total: number) => void = () => {},
  now: () => Date = () => new Date(),
): Promise<ExportFile> {
  let page = await fetchPage(kind, 0, 0);
  const { until, total } = page;
  const items = [...page.items];
  onPage(items.length, total);

  while (!page.done) {
    page = await fetchPage(kind, page.next_after, until);
    items.push(...page.items);
    onPage(items.length, total);
  }

  return {
    kind,
    exported_at: now().toISOString(),
    total: items.length,
    items,
  };
}

/** `mcpmate-audit-log-2026-11-15.json`, in the browser's own date (UTC). */
export function exportFileName(kind: UninstallExportKind, date: Date): string {
  const slug = kind === "changes" ? "change-history" : "audit-log";

  return `mcpmate-${slug}-${date.toISOString().slice(0, 10)}.json`;
}

/** 4500000 → "4.3 MB" (powers of 1024, as the server counts). */
export function sizeText(bytes: number): string {
  const units = [
    ["gigabyte", 1024 ** 3],
    ["megabyte", 1024 ** 2],
    ["kilobyte", 1024],
  ] as const;

  for (const [unit, size] of units) {
    if (bytes >= size) {
      return formatNumber(bytes / size, {
        style: "unit",
        unit,
        maximumFractionDigits: 1,
      });
    }
  }

  return formatNumber(bytes, { style: "unit", unit: "byte" });
}

/** Hand the browser a JSON file to save. */
export function saveJson(fileName: string, data: unknown): void {
  const blob = new Blob([JSON.stringify(data, null, 2)], {
    type: "application/json",
  });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = fileName;
  link.rel = "noopener";
  document.body.appendChild(link);
  link.click();
  link.remove();
  // After the click: the download has its own copy.
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

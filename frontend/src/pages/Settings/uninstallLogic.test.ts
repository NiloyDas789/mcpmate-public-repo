import { describe, expect, it, vi } from "vitest";

import { exportAll, exportFileName, sizeText } from "./uninstallLogic";
import type { UninstallExportPage } from "../../types/api";

/** Rows 1…5 by ID, two a page; `extra` arrives after the first page. */
function fakeExport(extra = 0) {
  const ids = [1, 2, 3, 4, 5];
  let calls = 0;

  return vi.fn(
    (
      _kind: string,
      after: number,
      until: number,
    ): Promise<UninstallExportPage> => {
      calls++;
      if (calls === 2) {
        for (let n = 1; n <= extra; n++) ids.push(5 + n);
      }
      const bound = until || Math.max(0, ...ids);
      const inExport = ids.filter((id) => id <= bound);
      const items = inExport.filter((id) => id > after).slice(0, 2);
      const next = items.length ? items[items.length - 1] : after;

      return Promise.resolve({
        kind: "audit_log",
        per_page: 2,
        until: bound,
        total: inExport.length,
        next_after: next,
        done: items.length < 2 || next >= bound,
        items: items.map((id) => ({ id })),
      });
    },
  );
}

describe("exportAll", () => {
  it("fetches every page after the last ID into one file", async () => {
    const fetchPage = fakeExport();
    const onPage = vi.fn();
    const file = await exportAll(
      "audit_log",
      fetchPage,
      onPage,
      () => new Date("2026-11-15T10:00:00Z"),
    );

    expect(fetchPage.mock.calls.map((call) => [call[1], call[2]])).toEqual([
      [0, 0],
      [2, 5],
      [4, 5],
    ]);
    expect(onPage.mock.calls).toEqual([
      [2, 5],
      [4, 5],
      [5, 5],
    ]);
    expect(file).toEqual({
      kind: "audit_log",
      exported_at: "2026-11-15T10:00:00.000Z",
      total: 5,
      items: [1, 2, 3, 4, 5].map((id) => ({ id })),
    });
  });

  it("leaves out rows added while it runs, and repeats none", async () => {
    const file = await exportAll("audit_log", fakeExport(3));

    expect(file.items).toEqual([1, 2, 3, 4, 5].map((id) => ({ id })));
    expect(file.total).toBe(5);
  });

  it("asks once for an empty log", async () => {
    const fetchPage = vi.fn(() =>
      Promise.resolve({
        kind: "changes" as const,
        per_page: 10,
        until: 0,
        total: 0,
        next_after: 0,
        done: true,
        items: [],
      }),
    );

    expect((await exportAll("changes", fetchPage)).items).toEqual([]);
    expect(fetchPage).toHaveBeenCalledTimes(1);
  });
});

describe("file names and sizes", () => {
  it("names the file after what it holds and the day", () => {
    const day = new Date("2026-11-15T23:00:00Z");

    expect(exportFileName("audit_log", day)).toBe(
      "mcpmate-audit-log-2026-11-15.json",
    );
    expect(exportFileName("changes", day)).toBe(
      "mcpmate-change-history-2026-11-15.json",
    );
  });

  it("writes sizes in the largest unit that fits", () => {
    expect(sizeText(512)).toBe("512 byte");
    expect(sizeText(1536)).toBe("1.5 kB");
    expect(sizeText(4_500_000)).toBe("4.3 MB");
  });
});

import { describe, expect, it } from "vitest";

import type { AuditLogEntry } from "../../types/api";
import {
  argsSummary,
  auditApiFilters,
  auditViewFromQuery,
  entryApp,
  filterValues,
  hasFilters,
  queryForFilters,
  queryForSort,
  toolOptions,
} from "./filters";

const now = Date.parse("2026-10-26T12:00:00Z");

describe("the audit log's hash query", () => {
  it("defaults to the last 7 days, newest first, page one", () => {
    const view = auditViewFromQuery("");

    expect(view.period).toBe("7d");
    expect(view.sort).toEqual({ key: "date", order: "descending" });
    expect(hasFilters(view)).toBe(false);
    expect(auditApiFilters(view, now)).toEqual({
      from: "2026-10-19T12:00:00Z",
      order_by: "created_at",
      order: "desc",
    });
  });

  it("reads every filter and ignores invalid ones", () => {
    const view = auditViewFromQuery(
      "?q=home&mcp_tool=elementor_save&user=2&grant_id=g_AAAAAAAAAAAAAAAAAAAAAA&result=error&period=all&sort=duration&order=asc&page=3&entry=41",
    );

    expect(auditApiFilters(view, now)).toEqual({
      search: "home",
      mcp_tool: "elementor_save",
      user_id: "2",
      grant_id: "g_AAAAAAAAAAAAAAAAAAAAAA",
      result: "error",
      order_by: "duration_ms",
      order: "asc",
    });
    expect(view.page).toBe(3);
    expect(view.entry).toBe(41);

    const bad = auditViewFromQuery(
      "?mcp_tool=x' OR 1&user=abc&grant_id=g_1&result=deleted&period=forever&page=-2&entry=x",
    );
    expect(hasFilters(bad)).toBe(false);
    expect(bad.page).toBe(1);
    expect(bad.entry).toBeNull();
  });

  it("keeps the setup wizard's old links working (internal tool)", () => {
    const view = auditViewFromQuery(
      "?tool=connection&grant_id=g_AAAAAAAAAAAAAAAAAAAAAA",
    );

    expect(filterValues(view).tool).toBe("internal:connection");
    expect(auditApiFilters(view, now).tool).toBe("connection");
    expect(toolOptions(["site_info"], view)[0]).toEqual({
      value: "internal:connection",
      label: "Setup and connection",
    });
    // An internal tool from an old link gets an option of its own.
    expect(
      toolOptions([], auditViewFromQuery("?tool=cli")).map((o) => o.value),
    ).toContain("internal:cli");
  });

  it("writes a filter change back, on page one, keeping sort and entry", () => {
    const query = "?sort=duration&page=4&entry=9";
    const view = auditViewFromQuery(query);

    expect(
      queryForFilters(query, {
        ...filterValues(view),
        tool: "internal:connection",
        period: "custom",
        from: "2026-10-01",
        to: "2026-10-02",
      }),
    ).toBe(
      "?sort=duration&entry=9&tool=connection&period=custom&from=2026-10-01&to=2026-10-02",
    );
    // Custom days are dropped with the custom period.
    expect(
      queryForFilters("?period=custom&from=2026-10-01", {
        period: "30d",
        from: "2026-10-01",
      }),
    ).toBe("?period=30d");
  });

  it("writes the sort, leaving the default out", () => {
    expect(
      queryForSort("?page=2", { key: "duration", order: "descending" }),
    ).toBe("?sort=duration&order=desc");
    expect(
      queryForSort("?sort=duration&order=asc", {
        key: "date",
        order: "descending",
      }),
    ).toBe("");
    expect(queryForSort("", { key: "date", order: "ascending" })).toBe(
      "?order=asc",
    );
  });
});

describe("rows", () => {
  it("summarises the first two arguments in one short line", () => {
    expect(
      argsSummary({
        post_id: 41,
        patches: ["a", "b", "c"],
        token: "[redacted]",
      }),
    ).toBe("post_id 41, patches 3 items");
    expect(
      argsSummary({
        command:
          "option update blogdescription 'A much longer tagline than fits'",
      }),
    ).toHaveLength(60);
    expect(argsSummary({})).toBe("—");
    expect(argsSummary(null)).toBe("—");
  });

  it("names who called", () => {
    const row = {
      grant_id: "",
      auth: "cookie",
      client_name: null,
    } as AuditLogEntry;

    expect(entryApp(row)).toBe("wp-admin");
    expect(entryApp({ ...row, auth: "" })).toBe("System");
    expect(entryApp({ ...row, grant_id: "g_x", client_name: "Claude" })).toBe(
      "Claude",
    );
    expect(entryApp({ ...row, grant_id: "g_x" })).toBe("Removed AI app");
  });
});

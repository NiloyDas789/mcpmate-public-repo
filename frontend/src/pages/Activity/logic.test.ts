import { describe, expect, it } from "vitest";

import {
  customRangeError,
  formatDuration,
  idFromParam,
  isDay,
  pageFromParam,
  periodRange,
  siteDayBoundary,
  withParams,
} from "./logic";

describe("siteDayBoundary", () => {
  it("is the start and the last second of the day in the site's zone, in UTC", () => {
    expect(siteDayBoundary("2026-10-26", false, "Asia/Dhaka")).toBe(
      "2026-10-25T18:00:00Z",
    );
    expect(siteDayBoundary("2026-10-26", true, "Asia/Dhaka")).toBe(
      "2026-10-26T17:59:59Z",
    );
    expect(siteDayBoundary("2026-10-26", false, "UTC")).toBe(
      "2026-10-26T00:00:00Z",
    );
  });

  it("follows daylight saving time", () => {
    // New York is UTC-4 in summer and UTC-5 in winter.
    expect(siteDayBoundary("2026-07-01", false, "America/New_York")).toBe(
      "2026-07-01T04:00:00Z",
    );
    expect(siteDayBoundary("2026-12-01", false, "America/New_York")).toBe(
      "2026-12-01T05:00:00Z",
    );
    // The day the clocks go back (1 Nov 2026) still starts at local midnight.
    expect(siteDayBoundary("2026-11-01", false, "America/New_York")).toBe(
      "2026-11-01T04:00:00Z",
    );
  });

  it("reads WordPress's offset timezones ('+05:30')", () => {
    expect(siteDayBoundary("2026-10-26", false, "+05:30")).toBe(
      "2026-10-25T18:30:00Z",
    );
    expect(siteDayBoundary("2026-10-26", true, "-03:00")).toBe(
      "2026-10-27T02:59:59Z",
    );
  });

  it("falls back to UTC for a zone it does not know", () => {
    expect(siteDayBoundary("2026-10-26", false, "Not/AZone")).toBe(
      "2026-10-26T00:00:00Z",
    );
  });
});

describe("periodRange", () => {
  const now = Date.parse("2026-10-26T12:00:00Z");

  it("counts back from now", () => {
    expect(periodRange("24h", { from: "", to: "" }, now)).toEqual({
      from: "2026-10-25T12:00:00Z",
    });
    expect(periodRange("7d", { from: "", to: "" }, now)).toEqual({
      from: "2026-10-19T12:00:00Z",
    });
    expect(periodRange("all", { from: "", to: "" }, now)).toEqual({});
  });

  it("uses only the real days of a custom range", () => {
    SERVER_VARIABLES.timeZone = "UTC";
    expect(
      periodRange("custom", { from: "2026-10-01", to: "2026-02-30" }, now),
    ).toEqual({ from: "2026-10-01T00:00:00Z" });
    expect(periodRange("custom", { from: "", to: "2026-10-02" }, now)).toEqual({
      to: "2026-10-02T23:59:59Z",
    });
  });
});

describe("small parsers", () => {
  it("accepts only real dates, pages and IDs", () => {
    expect(isDay("2026-02-28")).toBe(true);
    expect(isDay("2026-02-30")).toBe(false);
    expect(isDay("26/10/2026")).toBe(false);
    expect(pageFromParam("3")).toBe(3);
    expect(pageFromParam("0")).toBe(1);
    expect(pageFromParam("x")).toBe(1);
    expect(idFromParam("41")).toBe(41);
    expect(idFromParam("-1")).toBeNull();
    expect(idFromParam("1e3")).toBeNull();
  });

  it("says when a custom range is backwards", () => {
    expect(customRangeError("2026-10-02", "2026-10-01")).not.toBe("");
    expect(customRangeError("2026-10-01", "2026-10-01")).toBe("");
    expect(customRangeError("2026-10-02", "")).toBe("");
  });

  it("writes and removes hash query keys", () => {
    expect(withParams("?a=1&b=2", { b: null, c: "3" })).toBe("?a=1&c=3");
    expect(withParams("?a=1", { a: "" })).toBe("");
  });

  it("formats durations", () => {
    expect(formatDuration(640)).toBe("640 ms");
    expect(formatDuration(1240)).toBe("1.2 s");
    expect(formatDuration(null)).toBe("—");
  });
});

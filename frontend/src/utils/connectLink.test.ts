import { describe, expect, it } from "vitest";

import { connectRoute, isSameOrigin } from "./connectLink";

describe("connectRoute", () => {
  it("opens the approval screen from the query argument", () => {
    expect(connectRoute("?page=mcpmate&connect=v1.abc.def", "")).toBe(
      "#/connect",
    );
    expect(connectRoute("?page=mcpmate&connect=v1.abc.def", "#/")).toBe(
      "#/connect",
    );
  });

  it("keeps a route the page already has", () => {
    expect(
      connectRoute("?page=mcpmate&connect=v1.abc.def", "#/settings"),
    ).toBeNull();
  });

  it("ignores pages without a request", () => {
    expect(connectRoute("?page=mcpmate", "")).toBeNull();
    expect(connectRoute("?page=mcpmate&connect=", "")).toBeNull();
  });
});

describe("isSameOrigin", () => {
  const app = "https://claude.ai";

  it("accepts the app's own origin", () => {
    expect(
      isSameOrigin("https://claude.ai/api/mcp/auth_callback?code=x", app),
    ).toBe(true);
  });

  it("refuses anything else", () => {
    expect(isSameOrigin("https://claude.ai.evil.test/cb", app)).toBe(false);
    expect(isSameOrigin("http://claude.ai/cb", app)).toBe(false);
    expect(isSameOrigin("https://claude.ai:8443/cb", app)).toBe(false);
    expect(isSameOrigin("javascript:alert(1)", app)).toBe(false);
    expect(isSameOrigin("/relative", app)).toBe(false);
    expect(isSameOrigin("https://claude.ai/cb", "")).toBe(false);
  });
});

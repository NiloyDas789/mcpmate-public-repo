import { describe, expect, it } from "vitest";

import { setupRoute } from "./setupLink";

describe("setupRoute", () => {
  it("opens the wizard from the query argument", () => {
    expect(setupRoute("?page=mcpmate&setup=1", "")).toBe("#/setup");
    expect(setupRoute("?page=mcpmate&setup=1", "#/")).toBe("#/setup");
  });

  it("keeps a route the page already has", () => {
    expect(setupRoute("?page=mcpmate&setup=1", "#/settings")).toBeNull();
  });

  it("ignores other values", () => {
    expect(setupRoute("?page=mcpmate", "")).toBeNull();
    expect(setupRoute("?page=mcpmate&setup=0", "")).toBeNull();
    expect(setupRoute("?page=mcpmate&setup=yes", "")).toBeNull();
  });
});

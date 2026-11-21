import { describe, expect, it } from "vitest";

import {
  AI_CLIENTS,
  MORE_AI_CLIENTS,
  availableClients,
  findClient,
} from "./aiClients";

const url = "https://example.com/wp-json/mcpmate/mcp";

describe("AI_CLIENTS", () => {
  it.each(
    [...AI_CLIENTS, ...MORE_AI_CLIENTS].map((client) => [client.name, client]),
  )("%s has 2–3 steps, a docs link and a check date", (_name, client) => {
    const steps = client.steps(url);

    expect(steps.length).toBeGreaterThanOrEqual(2);
    expect(steps.length).toBeLessThanOrEqual(3);
    expect(client.checkedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(client.docsUrl).toMatch(/^https:\/\//);
    steps.forEach((step) => expect(step.text).not.toBe(""));
  });

  it("puts the MCP URL into every copyable command", () => {
    [...AI_CLIENTS, ...MORE_AI_CLIENTS]
      .flatMap((client) => client.steps(url))
      .filter((step) => step.code)
      .forEach((step) => expect(step.code).toContain(url));
  });

  it("covers the four apps the wizard offers, desktop apps flagged", () => {
    expect(AI_CLIENTS.map((client) => client.id)).toEqual([
      "claude",
      "chatgpt",
      "cursor",
      "claude-code",
    ]);
    expect(
      AI_CLIENTS.filter((client) => client.desktopRedirect).map((c) => c.id),
    ).toEqual(["cursor", "claude-code"]);
    expect(findClient("unknown").id).toBe("claude");
  });

  it("offers every app in the Connect dialog, with or without Pro", () => {
    const before = SERVER_VARIABLES.pro;

    SERVER_VARIABLES.pro = undefined;
    expect(availableClients().map((client) => client.id)).toEqual([
      "claude",
      "chatgpt",
      "cursor",
      "claude-code",
      "codex",
      "gemini-cli",
      "vscode",
      "windsurf",
      "antigravity",
      "zed",
      "cline",
      "continue",
      "amp",
    ]);
    expect(findClient("zed").name).toBe("Zed");

    SERVER_VARIABLES.pro = before;
  });
});

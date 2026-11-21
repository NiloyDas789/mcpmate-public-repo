import { describe, expect, it } from "vitest";

import { describeRequest } from "./labels";

describe("describeRequest", () => {
  it("shows the command for cli and the ability for abilities", () => {
    expect(
      describeRequest({
        tool: "cli",
        action: "plugin.deactivate",
        args: { command: "plugin deactivate akismet" },
      }),
    ).toBe("plugin deactivate akismet");
    expect(
      describeRequest({
        tool: "abilities",
        action: "my-plugin:delete-report",
        args: { name: "my-plugin/delete-report", input: { id: 3 } },
      }),
    ).toBe("ability my-plugin/delete-report");
  });

  it("falls back to tool and action", () => {
    expect(
      describeRequest({ tool: "theme_draft", action: "publish", args: {} }),
    ).toBe("theme_draft publish");
  });
});

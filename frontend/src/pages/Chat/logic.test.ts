import { describe, expect, it } from "vitest";

import { parseSse } from "@services/api";
import {
  applyEvent,
  bytesText,
  EMPTY_LIVE,
  imageProblem,
  planTitle,
  sessionFromSearch,
} from "./logic";

describe("parseSse", () => {
  it("reads events split anywhere and keeps the rest", () => {
    const first = parseSse('event: text\ndata: {"delta":"Hel"}\n\nevent: te');
    expect(first.events).toEqual([{ type: "text", data: { delta: "Hel" } }]);

    const second = parseSse(first.rest + 'xt\r\ndata: {"delta":"lo"}\r\n\r\n');
    expect(second.events).toEqual([{ type: "text", data: { delta: "lo" } }]);
    expect(second.rest).toBe("");
  });

  it("skips an event whose data is not JSON", () => {
    expect(parseSse("event: text\ndata: {nope\n\n").events).toEqual([]);
  });
});

describe("applyEvent", () => {
  it("builds what streams in", () => {
    let live = applyEvent(EMPTY_LIVE, { type: "text", data: { delta: "Hi" } });
    live = applyEvent(live, {
      type: "tool",
      data: { id: "t1", name: "site_info" },
    });
    live = applyEvent(live, {
      type: "tool_result",
      data: {
        id: "t1",
        name: "site_info",
        status: "ok",
        summary: "WordPress 6.9",
      },
    });

    expect(live.text).toBe("Hi");
    expect(live.tools).toEqual([
      { id: "t1", name: "site_info", status: "ok", summary: "WordPress 6.9" },
    ]);
  });
});

describe("images and labels", () => {
  it("checks count, type and size as the server does", () => {
    const png = { type: "image/png", size: 1000 };

    expect(imageProblem([png], 0)).toBeNull();
    expect(imageProblem([png, png], 3)).toBe(
      "Attach at most 4 images to one message.",
    );
    expect(imageProblem([{ type: "image/svg+xml", size: 10 }], 0)).toBe(
      "Images must be JPEG, PNG, GIF or WebP.",
    );
    expect(
      imageProblem([{ type: "image/png", size: 4 * 1024 * 1024 }], 0),
    ).toBe("Each image may be 3 MB at most.");
  });

  it("names the plan by its changes and reads the open conversation", () => {
    expect(
      planTitle([
        { id: "a", name: "site_info", kind: "read", invalid: false },
        { id: "b", name: "update_post", kind: "write", invalid: false },
      ]),
    ).toBe("Claude wants to make 1 change");
    expect(sessionFromSearch("?session=12")).toBe(12);
    expect(sessionFromSearch("?session=x")).toBeNull();
    expect(bytesText(1572864)).toBe("1.5 MB");
  });
});

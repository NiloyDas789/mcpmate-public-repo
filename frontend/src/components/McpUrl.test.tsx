import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import McpUrl from "./McpUrl";

const url = "https://example.com/wp-json/mcpmate/mcp";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("McpUrl", () => {
  it("copies with the Clipboard API", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {
      value: { writeText },
      configurable: true,
    });
    const onCopied = vi.fn();

    render(<McpUrl url={url} size="large" onCopied={onCopied} />);
    await userEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));

    expect(writeText).toHaveBeenCalledWith(url);
    await waitFor(() =>
      expect(onCopied).toHaveBeenCalledWith("MCP URL copied."),
    );
  });

  it("selects the URL for Ctrl+C when there is no Clipboard API", async () => {
    Object.defineProperty(navigator, "clipboard", {
      value: undefined,
      configurable: true,
    });
    const onCopied = vi.fn();

    render(<McpUrl url={url} onCopied={onCopied} />);
    await userEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));

    const input = screen.getByLabelText("MCP URL") as HTMLInputElement;
    await waitFor(() =>
      expect(onCopied).toHaveBeenCalledWith(
        "Press Ctrl+C (⌘C on Mac) to copy.",
      ),
    );
    expect(input).toHaveFocus();
    expect(input.selectionStart).toBe(0);
    expect(input.selectionEnd).toBe(url.length);
  });
});

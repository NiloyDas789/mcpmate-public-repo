import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import Announcer from "./Announcer";
import CopyField from "./CopyField";

const URL = "https://mcp.mcpmate.example/s/ab12cd34/mcp";

function stubClipboard(writeText?: (text: string) => Promise<void>) {
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: writeText ? { writeText } : undefined,
  });
}

function renderField(props: Partial<Parameters<typeof CopyField>[0]> = {}) {
  render(
    <Announcer>
      <CopyField label="MCP URL" value={URL} {...props} />
    </Announcer>,
  );
}

// Lets the awaited clipboard call settle.
const flush = () => act(async () => undefined);

afterEach(() => {
  vi.useRealTimers();
  stubClipboard(undefined);
});

describe("CopyField", () => {
  it("is a labelled read-only field with a named Copy button", () => {
    renderField();

    const field = screen.getByRole("textbox", { name: "MCP URL" });
    expect(field).toHaveValue(URL);
    expect(field).toHaveAttribute("readonly");
    expect(screen.getByText("MCP URL").tagName).toBe("LABEL");
    expect(
      screen.getByRole("button", { name: "Copy MCP URL" }),
    ).toHaveTextContent("Copy");
  });

  it("takes a custom button name", () => {
    renderField({ copyLabel: "Copy the wp-config.php line" });

    expect(
      screen.getByRole("button", { name: "Copy the wp-config.php line" }),
    ).toBeInTheDocument();
  });

  it("copies, says so for 3 seconds and announces it", async () => {
    vi.useFakeTimers();
    const writeText = vi.fn().mockResolvedValue(undefined);
    stubClipboard(writeText);
    renderField();

    fireEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));
    await flush();

    expect(writeText).toHaveBeenCalledWith(URL);
    expect(screen.getByRole("button", { name: "Copied" })).toHaveTextContent(
      "Copied",
    );
    expect(screen.getByRole("status")).toHaveTextContent("MCP URL copied.");

    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(
      screen.getByRole("button", { name: "Copy MCP URL" }),
    ).toBeInTheDocument();
  });

  it("selects the text for Ctrl+C without the Clipboard API", async () => {
    stubClipboard(undefined);
    renderField();

    fireEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));
    await flush();

    const field = screen.getByRole<HTMLInputElement>("textbox", {
      name: "MCP URL",
    });
    expect(field).toHaveFocus();
    expect(field.selectionStart).toBe(0);
    expect(field.selectionEnd).toBe(URL.length);
    expect(screen.getByRole("status")).toHaveTextContent(
      "Press Ctrl+C (⌘C on Mac) to copy.",
    );
    expect(
      screen.getByRole("button", { name: "Copy MCP URL" }),
    ).toBeInTheDocument();
  });

  it("falls back to selecting when the browser refuses", async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error("denied")));
    renderField();

    fireEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));
    await flush();

    expect(screen.getByRole("status")).toHaveTextContent(
      "Press Ctrl+C (⌘C on Mac) to copy.",
    );
  });

  it("says what to press, visibly, and selects a wrapped value too", async () => {
    stubClipboard(undefined);
    renderField({ layout: "narrow" });

    fireEvent.click(screen.getByRole("button", { name: "Copy MCP URL" }));
    await flush();

    const field = screen.getByRole<HTMLTextAreaElement>("textbox", {
      name: "MCP URL",
    });
    expect(field.tagName).toBe("TEXTAREA");
    expect(field).toHaveFocus();
    expect(field.selectionEnd).toBe(URL.length);
    expect(field).toHaveAccessibleDescription(
      "Your browser didn't let MCPMate copy it. The text is selected: press Ctrl+C (⌘C on Mac) to copy.",
    );
  });

  it("wraps long values in a textarea when narrow", () => {
    renderField({ layout: "narrow" });

    const field = screen.getByRole("textbox", { name: "MCP URL" });
    expect(field.tagName).toBe("TEXTAREA");
    expect(field).toHaveValue(URL);
  });
});

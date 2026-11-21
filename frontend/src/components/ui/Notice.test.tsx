import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import Notice from "./Notice";

describe("Notice", () => {
  it("has no live role when present on load, and a tone word", () => {
    render(<Notice tone="warning" title="AI access is paused." />);

    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.queryByRole("status")).toBeNull();
    expect(screen.getByText("Warning:")).toHaveClass("sr-only");
  });

  it("is a status or an alert after a user action", () => {
    const { rerender } = render(<Notice tone="success" title="Saved." live />);
    expect(screen.getByRole("status")).toHaveTextContent("Success: Saved.");

    rerender(<Notice tone="danger" title="Couldn't save." live />);
    expect(screen.getByRole("alert")).toHaveTextContent(
      "Error: Couldn't save.",
    );
  });

  it("names its dismiss button after its title", async () => {
    const onDismiss = vi.fn();
    const user = userEvent.setup();
    render(
      <Notice tone="info" title="Finish setting up" onDismiss={onDismiss} />,
    );

    await user.click(
      screen.getByRole("button", { name: "Dismiss: Finish setting up" }),
    );

    expect(onDismiss).toHaveBeenCalled();
  });
});

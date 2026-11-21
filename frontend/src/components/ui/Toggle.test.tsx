import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";

import Toggle from "./Toggle";

function Demo({ disabled = false }: { disabled?: boolean }) {
  const [on, setOn] = useState(false);

  return (
    <Toggle
      label="Also log read calls"
      description="Needed for the Dashboard numbers."
      checked={on}
      onChange={setOn}
      disabled={disabled}
    />
  );
}

describe("Toggle", () => {
  it("is a switch named by its visible label and described by its note", () => {
    render(<Demo />);

    const toggle = screen.getByRole("switch", { name: "Also log read calls" });
    expect(toggle).toHaveAttribute("aria-checked", "false");
    expect(toggle).toHaveAccessibleDescription(
      "Needed for the Dashboard numbers.",
    );
    expect(screen.getByText("Off")).toBeInTheDocument();
  });

  it("switches with Space and says On", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    await user.tab();
    await user.keyboard(" ");

    expect(screen.getByRole("switch")).toHaveAttribute("aria-checked", "true");
    expect(screen.getByText("On")).toBeInTheDocument();
  });

  it("switches from its label", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    await user.click(screen.getByText("Also log read calls"));

    expect(screen.getByRole("switch")).toHaveAttribute("aria-checked", "true");
  });

  it("can be disabled", () => {
    render(<Demo disabled />);

    expect(screen.getByRole("switch")).toBeDisabled();
  });
});

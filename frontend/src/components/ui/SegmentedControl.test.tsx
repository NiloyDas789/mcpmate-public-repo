import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";

import SegmentedControl from "./SegmentedControl";

function Demo() {
  const [value, setValue] = useState<"all" | "read" | "write">("all");

  return (
    <SegmentedControl
      label="Show"
      value={value}
      onChange={setValue}
      options={[
        { value: "all", label: "All" },
        { value: "read", label: "Read-only" },
        { value: "write", label: "Write" },
      ]}
    />
  );
}

describe("SegmentedControl", () => {
  it("is a radio group named by its visible label", () => {
    render(<Demo />);

    const group = screen.getByRole("radiogroup", { name: "Show" });
    expect(group).toBeInTheDocument();
    expect(screen.getByRole("radio", { name: "All" })).toBeChecked();
    expect(screen.getAllByRole("radio")).toHaveLength(3);
  });

  it("is one Tab stop; arrow keys move and select", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    await user.tab();
    expect(screen.getByRole("radio", { name: "All" })).toHaveFocus();
    await user.keyboard("{ArrowRight}");

    expect(screen.getByRole("radio", { name: "Read-only" })).toBeChecked();
    expect(screen.getByRole("radio", { name: "Read-only" })).toHaveFocus();
  });
});

import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import DiffView, { type DiffField } from "./DiffView";

const FIELDS: DiffField[] = [
  { key: "title", label: "Title", unchanged: "Home" },
  {
    key: "content",
    label: "Content",
    ops: [
      { op: "remove", lines: ["<p>Old intro.</p>"] },
      { op: "add", lines: ["<p>New intro.</p>", "<p>More.</p>"] },
      { op: "equal", lines: ["1", "2", "3", "4", "5", "6", "7"] },
    ],
  },
];

describe("DiffView", () => {
  it("marks lines with ins and del, signs and hidden words", () => {
    const { container } = render(<DiffView fields={FIELDS} layout="narrow" />);

    expect(container.querySelectorAll("ins")).toHaveLength(2);
    expect(container.querySelector("del")).toHaveTextContent(
      "Removed line: <p>Old intro.</p>",
    );
    expect(screen.getByText("2 lines added, 1 removed")).toBeInTheDocument();
    expect(
      screen.getByRole("region", { name: "Changes to Content" }),
    ).toHaveAttribute("tabindex", "0");
  });

  it("puts an unchanged field on one line", () => {
    render(<DiffView fields={FIELDS} layout="narrow" />);

    expect(screen.getByText("(unchanged)").parentElement).toHaveTextContent(
      "(unchanged) Home",
    );
  });

  it("folds a long unchanged run behind a disclosure", async () => {
    const user = userEvent.setup();
    render(<DiffView fields={FIELDS} layout="narrow" />);

    const show = screen.getByRole("button", { name: "Show 7 unchanged lines" });
    expect(show).toHaveAttribute("aria-expanded", "false");
    await user.click(show);
    expect(
      screen.getByRole("button", { name: "Hide 7 unchanged lines" }),
    ).toHaveAttribute("aria-expanded", "true");
  });

  it("offers side by side only when wide", async () => {
    const user = userEvent.setup();
    const { rerender } = render(<DiffView fields={FIELDS} layout="narrow" />);
    expect(screen.queryByRole("radiogroup", { name: "View" })).toBeNull();

    rerender(<DiffView fields={FIELDS} layout="wide" />);
    await user.click(screen.getByRole("radio", { name: "Side by side" }));
    expect(
      screen.getByRole("columnheader", { name: "Before" }),
    ).toBeInTheDocument();
  });
});

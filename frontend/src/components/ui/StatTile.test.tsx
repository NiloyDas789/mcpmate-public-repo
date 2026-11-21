import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import StatTile from "./StatTile";

describe("StatTile", () => {
  it("is a term and its value", () => {
    render(<StatTile label="Tool calls" value={1240} meta="Last 24 hours" />);

    expect(screen.getByRole("term")).toHaveTextContent("Tool calls");
    expect(screen.getAllByRole("definition")[0]).toHaveTextContent("1,240");
  });

  it("formats percents and fractions", () => {
    const { rerender } = render(
      <StatTile label="Success rate" value={0.957} format="percent" />,
    );
    expect(screen.getByText("96%")).toBeInTheDocument();

    rerender(
      <StatTile
        label="Tools turned on"
        value={18}
        total={31}
        format="fraction"
      />,
    );
    expect(screen.getByText("18 of 31")).toBeInTheDocument();
  });

  it("says No data for a missing value, Loading… while loading, and its error", () => {
    const { rerender } = render(<StatTile label="Success rate" value={null} />);
    expect(screen.getByText("No data")).toBeInTheDocument();

    rerender(<StatTile label="Success rate" value={null} loading />);
    expect(screen.getByText("Loading…")).toBeInTheDocument();

    rerender(
      <StatTile
        label="Success rate"
        value={null}
        error="Couldn't load the numbers."
      />,
    );
    expect(screen.getByText("Couldn't load the numbers.")).toBeInTheDocument();
  });
});

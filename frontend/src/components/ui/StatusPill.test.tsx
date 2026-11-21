import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import Badge from "./Badge";
import EmptyState from "./EmptyState";
import StatusPill from "./StatusPill";
import StatusText from "./StatusText";

describe("StatusPill and StatusText", () => {
  it("carry their meaning in text; the dot is hidden", () => {
    const { container } = render(
      <>
        <StatusPill
          tone="success"
          text="3 AI apps connected"
          href="#/connections"
        />
        <StatusText tone="danger">Failed</StatusText>
      </>,
    );

    expect(
      screen.getByRole("link", { name: "3 AI apps connected" }),
    ).toHaveAttribute("href", "#/connections");
    expect(screen.getByText("Failed")).toBeInTheDocument();
    container.querySelectorAll(".el-dot").forEach((dot) => {
      expect(dot).toHaveAttribute("aria-hidden", "true");
    });
  });
});

describe("Badge", () => {
  it("says its variant in words", () => {
    render(
      <>
        <Badge variant="read" />
        <Badge variant="approval" />
        <Badge variant="unavailable" />
      </>,
    );

    expect(screen.getByText("Read")).toBeInTheDocument();
    expect(screen.getByText("Asks you first")).toBeInTheDocument();
    expect(screen.getByText("Not installed")).toBeInTheDocument();
  });
});

describe("EmptyState", () => {
  it("has a heading, a sentence and one action", () => {
    render(
      <EmptyState
        title="No AI apps connected yet."
        body="You approve each one here."
        action={{ label: "Connect an AI app", href: "#/connections" }}
      />,
    );

    expect(
      screen.getByRole("heading", {
        level: 3,
        name: "No AI apps connected yet.",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Connect an AI app" }),
    ).toBeInTheDocument();
  });
});

import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import Pagination from "./Pagination";
import { pageList } from "./paginationLogic";

describe("Pagination", () => {
  it("is a named nav with the current page marked", () => {
    render(
      <Pagination
        label="Audit log pages"
        page={2}
        pageSize={20}
        total={1240}
        onChange={vi.fn()}
      />,
    );

    expect(
      screen.getByRole("navigation", { name: "Audit log pages" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Page 2" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(screen.getByText("Showing 21–40 of 1,240")).toBeInTheDocument();
  });

  it("moves with Previous, Next and the page numbers", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(
      <Pagination
        label="Pages"
        page={2}
        pageSize={20}
        total={1240}
        onChange={onChange}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Next" }));
    await user.click(screen.getByRole("button", { name: "Previous" }));
    await user.click(screen.getByRole("button", { name: "Page 62" }));

    expect(onChange.mock.calls).toEqual([[3], [1], [62]]);
  });

  it("disables Previous on the first page", () => {
    render(
      <Pagination
        label="Pages"
        page={1}
        pageSize={20}
        total={45}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: "Previous" })).toBeDisabled();
  });

  it("shows only Previous, the position and Next when narrow", () => {
    render(
      <Pagination
        label="Pages"
        page={2}
        pageSize={20}
        total={1240}
        layout="narrow"
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByText("Page 2 of 62")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Page 3" })).toBeNull();
  });
});

describe("pageList", () => {
  it("keeps the ends and the neighbours", () => {
    expect(pageList(5, 62)).toEqual([1, "gap", 4, 5, 6, "gap", 62]);
    expect(pageList(1, 3)).toEqual([1, 2, 3]);
    expect(pageList(1, 1)).toEqual([1]);
  });
});

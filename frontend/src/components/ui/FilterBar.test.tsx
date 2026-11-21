import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import FilterBar, { type FilterField } from "./FilterBar";

const FIELDS: FilterField[] = [
  { key: "search", type: "search", label: "Search" },
  { key: "from", type: "date", label: "From" },
];

afterEach(() => {
  vi.useRealTimers();
});

describe("FilterBar", () => {
  it("labels every control", () => {
    render(
      <FilterBar
        fields={FIELDS}
        values={{}}
        onChange={vi.fn()}
        layout="wide"
      />,
    );

    expect(
      screen.getByRole("searchbox", { name: "Search" }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("From")).toHaveAttribute("type", "date");
    expect(screen.queryByRole("button", { name: "Clear filters" })).toBeNull();
  });

  it("applies search after a 400 ms pause, without moving focus", () => {
    vi.useFakeTimers();
    const onChange = vi.fn();
    render(
      <FilterBar
        fields={FIELDS}
        values={{}}
        onChange={onChange}
        layout="wide"
      />,
    );
    const search = screen.getByRole("searchbox", { name: "Search" });
    search.focus();

    fireEvent.change(search, { target: { value: "theme" } });
    act(() => {
      vi.advanceTimersByTime(399);
    });
    expect(onChange).not.toHaveBeenCalled();
    act(() => {
      vi.advanceTimersByTime(1);
    });

    expect(onChange).toHaveBeenCalledWith({ search: "theme" });
    expect(search).toHaveFocus();
  });

  it("applies search on Enter", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(
      <FilterBar
        fields={FIELDS}
        values={{}}
        onChange={onChange}
        layout="wide"
      />,
    );

    await user.type(screen.getByRole("searchbox"), "media{Enter}");

    expect(onChange).toHaveBeenCalledWith({ search: "media" });
  });

  it("applies a search once: Enter cancels the pause that was waiting", () => {
    vi.useFakeTimers();
    const onChange = vi.fn();
    const view = render(
      <FilterBar
        fields={FIELDS}
        values={{ search: "home" }}
        onChange={onChange}
        layout="wide"
      />,
    );
    const search = screen.getByRole("searchbox", { name: "Search" });

    fireEvent.change(search, { target: { value: "" } });
    fireEvent.keyDown(search, { key: "Enter", code: "Enter", keyCode: 13 });
    expect(onChange).toHaveBeenCalledTimes(1);
    view.rerender(
      <FilterBar
        fields={FIELDS}
        values={{ search: "" }}
        onChange={onChange}
        layout="wide"
      />,
    );
    act(() => {
      vi.advanceTimersByTime(1000);
    });

    // A second call would write the page's older state back (a page or sort that changed since).
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it("drops a waiting search when the value is set from outside", () => {
    vi.useFakeTimers();
    const onChange = vi.fn();
    const view = render(
      <FilterBar
        fields={FIELDS}
        values={{ search: "x" }}
        onChange={onChange}
        layout="wide"
      />,
    );
    const search = screen.getByRole("searchbox", { name: "Search" });

    fireEvent.change(search, { target: { value: "xyz" } });
    // Clear filters, before the pause is over.
    view.rerender(
      <FilterBar
        fields={FIELDS}
        values={{ search: "" }}
        onChange={onChange}
        layout="wide"
      />,
    );
    act(() => {
      vi.advanceTimersByTime(1000);
    });

    expect(onChange).not.toHaveBeenCalled();
    expect(search).toHaveValue("");
  });

  it("keeps typing that follows a search the page is still applying", () => {
    vi.useFakeTimers();
    const onChange = vi.fn();
    const view = render(
      <FilterBar
        fields={FIELDS}
        values={{}}
        onChange={onChange}
        layout="wide"
      />,
    );
    const search = screen.getByRole("searchbox", { name: "Search" });

    fireEvent.change(search, { target: { value: "ho" } });
    act(() => {
      vi.advanceTimersByTime(400);
    });
    fireEvent.change(search, { target: { value: "home" } });
    // The page's "ho" comes back while "home" waits.
    view.rerender(
      <FilterBar
        fields={FIELDS}
        values={{ search: "ho" }}
        onChange={onChange}
        layout="wide"
      />,
    );
    act(() => {
      vi.advanceTimersByTime(400);
    });

    expect(search).toHaveValue("home");
    expect(onChange).toHaveBeenLastCalledWith({ search: "home" });
  });

  it("counts active filters and clears them", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(
      <FilterBar
        fields={FIELDS}
        values={{ search: "x", from: "2026-10-01" }}
        onChange={onChange}
        layout="wide"
      />,
    );

    expect(screen.getByText("2 filters active")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Clear filters" }));

    expect(onChange).toHaveBeenCalledWith({ search: "", from: "" });
  });

  it("folds behind a Filters disclosure when narrow", async () => {
    const user = userEvent.setup();
    render(
      <FilterBar
        fields={FIELDS}
        values={{ search: "x" }}
        onChange={vi.fn()}
        layout="narrow"
      />,
    );

    const toggle = screen.getByRole("button", { name: "Filters (1)" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("searchbox")).toBeNull();

    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(
      screen.getByRole("searchbox", { name: "Search" }),
    ).toBeInTheDocument();
  });
});

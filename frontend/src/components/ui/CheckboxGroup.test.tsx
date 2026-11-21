import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import CheckboxGroup from "./CheckboxGroup";

function Demo({
  onChange,
}: {
  onChange?: (changes: Record<string, boolean>) => void;
}) {
  const [items, setItems] = useState([
    { key: "site_info", label: "Get a summary of this site", checked: true },
    { key: "get_page_html", label: "Read a page's HTML", checked: false },
    {
      key: "check_approval",
      label: "Check on an approval request",
      checked: true,
      disabled: true,
    },
  ]);
  const [expanded, setExpanded] = useState(false);

  return (
    <CheckboxGroup
      label="Site overview"
      description={`${items.filter((item) => item.checked).length} of 3 on`}
      items={items}
      onChange={(changes) => {
        onChange?.(changes);
        setItems((current) =>
          current.map((item) =>
            item.key in changes
              ? { ...item, checked: changes[item.key] }
              : item,
          ),
        );
      }}
      expanded={expanded}
      onExpandedChange={setExpanded}
    />
  );
}

describe("CheckboxGroup", () => {
  it("is a group named by its checkbox, mixed when some are on", () => {
    render(<Demo />);

    expect(
      screen.getByRole("group", { name: "Site overview" }),
    ).toBeInTheDocument();
    const parent = screen.getByRole("checkbox", { name: "Site overview" });
    expect(parent).toHaveAttribute("aria-checked", "mixed");
    expect(parent).toBePartiallyChecked();
    expect(parent).toHaveAccessibleDescription("2 of 3 on");
  });

  it("turns every available child on, then off, never the disabled one", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<Demo onChange={onChange} />);
    const parent = screen.getByRole("checkbox", { name: "Site overview" });

    await user.click(parent);
    expect(onChange).toHaveBeenLastCalledWith({ get_page_html: true });
    expect(parent).toBeChecked();

    await user.keyboard(" ");
    expect(onChange).toHaveBeenLastCalledWith({
      site_info: false,
      get_page_html: false,
    });
    expect(parent).toHaveAttribute("aria-checked", "mixed");
  });

  it("shows and hides the items with a separate disclosure button", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    const show = screen.getByRole("button", { name: "Show Site overview" });
    expect(show).toHaveAttribute("aria-expanded", "false");
    expect(
      screen.queryByRole("checkbox", { name: "Read a page's HTML" }),
    ).toBeNull();

    await user.click(show);

    expect(
      screen.getByRole("button", { name: "Hide Site overview" }),
    ).toHaveAttribute("aria-expanded", "true");
    expect(
      screen.getByRole("checkbox", { name: "Check on an approval request" }),
    ).toBeDisabled();
    await user.click(
      screen.getByRole("checkbox", { name: "Read a page's HTML" }),
    );
    expect(
      screen.getByRole("checkbox", { name: "Site overview" }),
    ).toBeChecked();
  });
});

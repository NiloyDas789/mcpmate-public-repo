import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import Button from "./Button";

describe("Button", () => {
  it("is a button named by its text, activated by Enter and Space", async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(<Button onClick={onClick}>Save changes</Button>);

    await user.tab();
    expect(screen.getByRole("button", { name: "Save changes" })).toHaveFocus();
    await user.keyboard("{Enter}");
    await user.keyboard(" ");
    expect(onClick).toHaveBeenCalledTimes(2);
  });

  it("needs a label when it has only an icon", () => {
    render(<Button icon={<span aria-hidden>×</span>} label="Move to trash" />);

    expect(
      screen.getByRole("button", { name: "Move to trash" }),
    ).toBeInTheDocument();
  });

  it("stays focusable but does nothing while loading", async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <Button loading onClick={onClick}>
        Saving…
      </Button>,
    );

    const button = screen.getByRole("button", { name: /Saving…/ });
    expect(button).toHaveAttribute("aria-disabled", "true");
    expect(button).not.toBeDisabled();
    await user.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it("can be disabled", () => {
    render(<Button disabled>Revoke all apps</Button>);

    expect(
      screen.getByRole("button", { name: "Revoke all apps" }),
    ).toBeDisabled();
  });

  it("renders a link with href", () => {
    render(<Button href="#/tools">Manage tools</Button>);

    expect(screen.getByRole("link", { name: "Manage tools" })).toHaveAttribute(
      "href",
      "#/tools",
    );
  });
});

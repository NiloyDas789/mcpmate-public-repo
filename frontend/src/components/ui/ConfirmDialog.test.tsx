import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import ConfirmDialog from "./ConfirmDialog";

function Demo({
  tone = "danger",
  onConfirm,
  onClose = () => undefined,
}: {
  tone?: "default" | "danger";
  onConfirm: () => void | Promise<void>;
  onClose?: () => void;
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        Revoke
      </button>
      <ConfirmDialog
        open={open}
        tone={tone}
        title="Revoke access for Claude?"
        body={<p>Claude loses access to this site right away.</p>}
        confirmLabel="Revoke access for Claude"
        cancelLabel="Keep access"
        busyLabel="Revoking…"
        onConfirm={onConfirm}
        onClose={() => {
          onClose();
          setOpen(false);
        }}
      />
    </>
  );
}

async function openDemo(props: Parameters<typeof Demo>[0]) {
  const user = userEvent.setup();
  render(<Demo {...props} />);
  await user.click(screen.getByRole("button", { name: "Revoke" }));
  const dialog = await screen.findByRole("alertdialog", {
    name: "Revoke access for Claude?",
  });

  return { user, dialog };
}

describe("ConfirmDialog", () => {
  it("is an alertdialog named by its question and described by its body", async () => {
    const { dialog } = await openDemo({ onConfirm: vi.fn() });

    expect(dialog).toHaveAccessibleDescription(
      "Claude loses access to this site right away.",
    );
    expect(
      screen.getByRole("button", { name: "Revoke access for Claude" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Keep access" }),
    ).toBeInTheDocument();
  });

  it("opens with focus on Cancel when it is dangerous", async () => {
    const onConfirm = vi.fn();
    await openDemo({ onConfirm });

    await waitFor(() =>
      expect(screen.getByRole("button", { name: "Keep access" })).toHaveFocus(),
    );
    // A stray Enter keeps access.
    fireEvent.click(document.activeElement as HTMLElement);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("opens with focus on the action when it is not dangerous", async () => {
    await openDemo({ tone: "default", onConfirm: vi.fn() });

    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Revoke access for Claude" }),
      ).toHaveFocus(),
    );
  });

  it("shows progress while the action runs, then closes", async () => {
    let finish: () => void = () => undefined;
    const onConfirm = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          finish = resolve;
        }),
    );
    const onClose = vi.fn();
    const { user } = await openDemo({ onConfirm, onClose });

    await user.click(
      screen.getByRole("button", { name: "Revoke access for Claude" }),
    );

    const busy = screen.getByRole("button", { name: /Revoking…/ });
    expect(busy).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("button", { name: "Keep access" })).toBeDisabled();
    await user.click(busy);
    expect(onConfirm).toHaveBeenCalledTimes(1);

    finish();
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
  });

  it("stays open and says what went wrong when the action fails", async () => {
    const onClose = vi.fn();
    const { user, dialog } = await openDemo({
      onConfirm: () =>
        Promise.reject(new Error("MCPMate's server didn't answer.")),
      onClose,
    });

    await user.click(
      screen.getByRole("button", { name: "Revoke access for Claude" }),
    );

    const alert = await screen.findByRole("alert");
    expect(dialog).toContainElement(alert);
    expect(alert).toHaveTextContent("MCPMate's server didn't answer.");
    expect(onClose).not.toHaveBeenCalled();
    expect(
      screen.getByRole("button", { name: "Revoke access for Claude" }),
    ).not.toHaveAttribute("aria-disabled");
  });

  it("cancels without running the action", async () => {
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    const { user } = await openDemo({ onConfirm, onClose });

    await user.click(screen.getByRole("button", { name: "Keep access" }));

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "Revoke" })).toHaveFocus(),
    );
  });
});

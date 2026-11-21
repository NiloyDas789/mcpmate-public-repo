import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ConfigProvider } from "antd";
import { useRef, useState } from "react";
import { describe, expect, it } from "vitest";

import Modal from "./Modal";
import type { InitialFocus } from "@/hooks/useDialogFocus";

// rc-dialog reads `keyCode`, which user-event leaves out for Escape.
const pressEscape = () =>
  fireEvent.keyDown(document.activeElement ?? document.body, {
    key: "Escape",
    code: "Escape",
    keyCode: 27,
  });

function Demo({ initialFocus }: { initialFocus?: "auto" | "title" | "done" }) {
  const [open, setOpen] = useState(false);
  const done = useRef<HTMLButtonElement>(null);
  const focus: InitialFocus =
    initialFocus === "done" ? done : (initialFocus ?? "auto");

  return (
    <>
      <div id="mcpmate-app">
        <button type="button">Before</button>
        <button type="button" onClick={() => setOpen(true)}>
          Connect an AI app
        </button>
      </div>
      <Modal
        open={open}
        title="Connect an AI app"
        initialFocus={focus}
        onClose={() => setOpen(false)}
        footer={
          <button ref={done} type="button" onClick={() => setOpen(false)}>
            Done
          </button>
        }
      >
        <label>
          Name <input type="text" />
        </label>
        <a href="#help">Help</a>
      </Modal>
    </>
  );
}

async function openDemo(initialFocus?: "auto" | "title" | "done") {
  const user = userEvent.setup();
  // Without motion (as under reduced motion), so a closed dialog unmounts
  // at once: happy-dom never ends antd's CSS transitions.
  render(
    <ConfigProvider theme={{ token: { motion: false } }}>
      <Demo initialFocus={initialFocus} />
    </ConfigProvider>,
  );
  const opener = screen.getByRole("button", { name: "Connect an AI app" });
  await user.click(opener);
  const dialog = await screen.findByRole("dialog", {
    name: "Connect an AI app",
  });

  return { user, opener, dialog };
}

describe("Modal", () => {
  it("is a modal dialog named by its heading, with focus on the first field", async () => {
    const { dialog } = await openDemo();

    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(
      screen.getByRole("heading", { level: 2, name: "Connect an AI app" }),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByRole("textbox", { name: "Name" })).toHaveFocus(),
    );
  });

  it("can open with focus on the title or a given element", async () => {
    await openDemo("title");
    await waitFor(() =>
      expect(
        screen.getByRole("heading", { name: "Connect an AI app" }),
      ).toHaveFocus(),
    );
  });

  it("can open with focus on an element the caller names", async () => {
    await openDemo("done");
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "Done" })).toHaveFocus(),
    );
  });

  it("keeps Tab and Shift+Tab inside the dialog", async () => {
    const { user, dialog } = await openDemo();
    const close = screen.getByRole("button", { name: "Close" });
    const done = screen.getByRole("button", { name: "Done" });
    await waitFor(() => expect(screen.getByRole("textbox")).toHaveFocus());

    await user.tab();
    expect(screen.getByRole("link", { name: "Help" })).toHaveFocus();
    await user.tab();
    expect(done).toHaveFocus();
    await user.tab();
    expect(close).toHaveFocus();

    await user.tab({ shift: true });
    expect(done).toHaveFocus();

    for (let i = 0; i < 8; i++) {
      await user.tab({ shift: i % 2 === 0 });
      expect(dialog.contains(document.activeElement)).toBe(true);
    }
  });

  it("makes the app behind it inert while open", async () => {
    const { user } = await openDemo();
    const app = document.getElementById("mcpmate-app");

    expect(app).toHaveAttribute("inert");

    await user.click(screen.getByRole("button", { name: "Done" }));
    expect(app).not.toHaveAttribute("inert");
  });

  it("closes on Escape and returns focus to the opener", async () => {
    const { opener } = await openDemo();
    await waitFor(() => expect(screen.getByRole("textbox")).toHaveFocus());

    pressEscape();

    await waitFor(() => expect(opener).toHaveFocus());
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("returns focus to the opener when an action closes it", async () => {
    const { user, opener } = await openDemo();

    await user.click(screen.getByRole("button", { name: "Done" }));

    await waitFor(() => expect(opener).toHaveFocus());
  });

  it("returns focus after the close button too", async () => {
    const { user, opener } = await openDemo();

    await user.click(screen.getByRole("button", { name: "Close" }));

    await waitFor(() => expect(opener).toHaveFocus());
  });
});

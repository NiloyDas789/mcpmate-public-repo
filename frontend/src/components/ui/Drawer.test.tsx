import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ConfigProvider } from "antd";
import { useState } from "react";
import { describe, expect, it } from "vitest";

import Drawer from "./Drawer";

function Demo() {
  const [open, setOpen] = useState(false);

  return (
    <ConfigProvider theme={{ token: { motion: false } }}>
      <button type="button" onClick={() => setOpen(true)}>
        View
      </button>
      <Drawer
        open={open}
        title="elementor_save, 26 Oct"
        onClose={() => setOpen(false)}
      >
        <button type="button">Copy request ID</button>
      </Drawer>
    </ConfigProvider>
  );
}

describe("Drawer", () => {
  it("opens with focus on its h2 title, traps Tab and returns focus on Escape", async () => {
    const user = userEvent.setup();
    render(<Demo />);
    const opener = screen.getByRole("button", { name: "View" });

    await user.click(opener);
    const title = await screen.findByRole("heading", {
      level: 2,
      name: "elementor_save, 26 Oct",
    });
    await waitFor(() => expect(title).toHaveFocus());

    await user.tab();
    await user.tab();
    await user.tab();
    expect(
      title.closest(".ant-drawer-content")?.contains(document.activeElement),
    ).toBe(true);

    fireEvent.keyDown(document.activeElement as HTMLElement, {
      key: "Escape",
      keyCode: 27,
    });
    await waitFor(() => expect(opener).toHaveFocus());
  });
});

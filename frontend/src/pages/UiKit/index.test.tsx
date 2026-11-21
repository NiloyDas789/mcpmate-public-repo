import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ConfigProvider } from "antd";
import axe from "axe-core";
import { MemoryRouter } from "react-router";
import { describe, expect, it } from "vitest";

import Announcer from "@components/ui/Announcer";
import UiKit from "./index";

function renderKit(path = "/ui-kit") {
  render(
    <ConfigProvider theme={{ token: { motion: false } }}>
      <Announcer>
        <MemoryRouter initialEntries={[path]}>
          <UiKit />
        </MemoryRouter>
      </Announcer>
    </ConfigProvider>,
  );
}

/**
 * axe on one dialog. The whole page is checked in a real browser by
 * `pnpm test:a11y` (tools/a11y-ui-kit.mjs), with colour contrast: in
 * happy-dom, which computes no layout and restyles slowly, axe over the
 * full page takes minutes.
 */
async function violations(context: Element) {
  const result = await axe.run(context, {
    rules: { "color-contrast": { enabled: false } },
  });

  return result.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`,
  );
}

// axe takes seconds per dialog in happy-dom, more under a parallel run.
const AXE_TIMEOUT = 30_000;

describe("UI kit", () => {
  it("renders a section for every component", () => {
    renderKit();

    expect(
      screen.getByRole("heading", { level: 1, name: "UI kit" }),
    ).toBeInTheDocument();
    for (const name of [
      "Button",
      "StatusPill",
      "Badge",
      "Card",
      "StatTile",
      "Notice",
      "EmptyState",
      "Skeleton",
      "Tabs",
      "Toggle",
      "SegmentedControl",
      "CheckboxGroup",
      "FilterBar",
      "CopyField",
      "Table and Pagination",
      "Modal, ConfirmDialog, Drawer",
      "JsonBlock",
      "DiffView",
    ]) {
      expect(
        screen.getByRole("heading", { level: 2, name }),
      ).toBeInTheDocument();
    }
  });

  it(
    "has no violations with each dialog open",
    async () => {
      const user = userEvent.setup();
      renderKit();

      await user.click(screen.getByRole("button", { name: "Open modal" }));
      const modal = await screen.findByRole("dialog", {
        name: "Connect an AI app",
      });
      expect(await violations(modal)).toEqual([]);
      await user.click(screen.getByRole("button", { name: "Done" }));

      await user.click(
        screen.getByRole("button", { name: "Confirm (danger)" }),
      );
      const confirm = await screen.findByRole("alertdialog", {
        name: "Revoke access for Claude?",
      });
      expect(await violations(confirm)).toEqual([]);
      fireEvent.keyDown(document.activeElement as HTMLElement, {
        key: "Escape",
        keyCode: 27,
      });
      await waitFor(() => expect(screen.queryByRole("alertdialog")).toBeNull());
    },
    AXE_TIMEOUT,
  );

  it(
    "opens the drawer from the URL",
    async () => {
      renderKit("/ui-kit?drawer=demo");

      const title = await screen.findByRole("heading", {
        level: 2,
        name: "elementor_save · 26 Oct 2026, 10:42",
      });
      expect(
        await violations(title.closest(".ant-drawer-content") as Element),
      ).toEqual([]);
    },
    AXE_TIMEOUT,
  );
});

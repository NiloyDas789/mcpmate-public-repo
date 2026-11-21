import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import type { FakeSiteOptions } from "../../test/fakeSite";
import type { Memory } from "../../types/api";
import Tools from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
  SERVER_VARIABLES.pro = undefined;
});

const memory = (id: number, extra: Partial<Memory> = {}): Memory => ({
  id,
  user_id: 3,
  user: { id: 3, name: "Ann" },
  grant_id: "g_abc",
  title: `Memory ${id}`,
  tags: [],
  bytes: 20,
  content: `Text of memory ${id}.`,
  created_at: "2026-11-01T09:00:00Z",
  updated_at: `2026-11-0${id}T10:00:00Z`,
  trashed_at: null,
  ...extra,
});

const MEMORIES = [
  memory(1, {
    title: "Brand voice",
    tags: ["brand"],
    content: "British English.",
  }),
  memory(2, {
    title: "Shop layout",
    user_id: 4,
    user: { id: 4, name: "Bob" },
    tags: ["shop"],
  }),
  memory(3, { title: "Old note", trashed_at: "2026-11-03T11:00:00Z" }),
];

function renderMemory(
  path = "/tools/memory",
  options: FakeSiteOptions & { pro?: boolean } = {},
) {
  SERVER_VARIABLES.pro =
    options.pro === false ? undefined : { installed: true, active: true };

  return renderPage(<Tools />, {
    path,
    route: "tools/*",
    memories: MEMORIES,
    ...options,
  });
}

/** The requests sent to one admin-ajax route, as their form fields. */
function sent(fetchMock: ReturnType<typeof vi.fn>, route: string) {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData && body.get("action") === `MCPMATE_${route}`,
    )
    .map((body) => Object.fromEntries(body.entries()));
}

describe("Tools → Memory", () => {
  it("is not a tab, and asks nothing, without MCPMate Pro", async () => {
    const { fetchMock } = renderMemory("/tools", { pro: false });

    await screen.findByRole("tab", { name: /Core/ });
    expect(screen.queryByRole("tab", { name: "Memory" })).toBeNull();
    expect(calledRoutes(fetchMock)).not.toContain("memories/list");
  });

  it("lists every user's saved memories, newest first", async () => {
    renderMemory();

    expect(await screen.findByRole("tab", { name: "Memory" })).toHaveAttribute(
      "aria-selected",
      "true",
    );
    const table = await screen.findByRole("table");
    await within(table).findByRole("button", { name: "Shop layout" });
    const titles = within(table)
      .getAllByRole("rowheader")
      .map((cell) => within(cell).getByRole("button").textContent);
    expect(titles).toEqual(["Shop layout", "Brand voice"]);
    expect(within(table).queryByText("Old note")).toBeNull();
    expect(screen.getByRole("radio", { name: "Saved (2)" })).toBeChecked();
    expect(
      screen.getByRole("radio", { name: "Trash (1)" }),
    ).toBeInTheDocument();
  });

  it("edits a memory in a drawer kept in the URL", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderMemory();

    await user.click(
      await screen.findByRole("button", { name: "Edit: Brand voice" }),
    );
    const drawer = await screen.findByRole("dialog", { name: "Brand voice" });
    expect(location.current).toContain("memory=1");

    const text = await within(drawer).findByRole("textbox", { name: "Memory" });
    expect(text).toHaveValue("British English.");
    fireEvent.change(text, { target: { value: "American English." } });
    fireEvent.change(within(drawer).getByRole("textbox", { name: "Tags" }), {
      target: { value: "brand, Blog" },
    });
    await user.click(
      within(drawer).getByRole("button", { name: "Save changes" }),
    );

    await waitFor(() =>
      expect(sent(fetchMock, "memories/update")).toEqual([
        {
          action: "MCPMATE_memories/update",
          _ajax_nonce: expect.any(String),
          _method: "POST",
          id: "1",
          title: "Brand voice",
          content: "American English.",
          tags: '["brand","Blog"]',
        },
      ]),
    );
    const results = await axe.run(drawer, {
      rules: { "color-contrast": { enabled: false } },
    });
    expect(results.violations.map((violation) => violation.id)).toEqual([]);
  });

  it("shows the server's refusal under the field it names", async () => {
    const user = userEvent.setup();
    renderMemory("/tools/memory?memory=1");

    const drawer = await screen.findByRole("dialog", { name: "Brand voice" });
    const text = await within(drawer).findByRole("textbox", { name: "Memory" });
    fireEvent.change(text, { target: { value: "password: hunter2hunter2" } });
    await user.click(
      within(drawer).getByRole("button", { name: "Save changes" }),
    );

    expect(
      await within(drawer).findByText(
        "This looks like it holds a password or secret value. Memories never store secrets: remove it and save again.",
        { selector: ".el-field-error" },
      ),
    ).toBeInTheDocument();
    expect(text).toHaveAttribute("aria-invalid", "true");
  });

  it("checks the size before sending", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderMemory("/tools/memory?memory=1");

    const drawer = await screen.findByRole("dialog", { name: "Brand voice" });
    const text = await within(drawer).findByRole("textbox", { name: "Memory" });
    fireEvent.change(text, { target: { value: "é".repeat(8193) } });
    await user.click(
      within(drawer).getByRole("button", { name: "Save changes" }),
    );

    expect(
      within(drawer).getByText(
        "Write the memory as plain text, up to 16,384 bytes (16 KB).",
      ),
    ).toBeInTheDocument();
    expect(sent(fetchMock, "memories/update")).toEqual([]);
    expect(text).toHaveFocus();
  });

  it("restores from the trash and deletes for good after asking", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderMemory("/tools/memory?status=trash");

    const table = await screen.findByRole("table");
    expect(
      await within(table).findByRole("button", { name: "Old note" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/deleted for good after 30 days/),
    ).toBeInTheDocument();

    await user.click(
      within(table).getByRole("button", {
        name: "Delete permanently: Old note",
      }),
    );
    const dialog = await screen.findByRole("alertdialog", {
      name: "Delete this memory for good?",
    });
    expect(
      within(dialog).getByRole("button", { name: "Keep it" }),
    ).toHaveFocus();
    await user.click(
      within(dialog).getByRole("button", { name: "Delete permanently" }),
    );

    await waitFor(() =>
      expect(sent(fetchMock, "memories/purge").map((body) => body.ids)).toEqual(
        ["[3]"],
      ),
    );
    expect(await screen.findByText("The trash is empty.")).toBeInTheDocument();
  });

  it("restores a trashed memory", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderMemory("/tools/memory?status=trash");

    await user.click(
      await screen.findByRole("button", { name: "Restore: Old note" }),
    );

    await waitFor(() =>
      expect(
        sent(fetchMock, "memories/restore").map((body) => body.id),
      ).toEqual(["3"]),
    );
    expect(
      await screen.findByRole("radio", { name: "Saved (3)" }),
    ).toBeInTheDocument();
  });

  it("filters by user", async () => {
    renderMemory("/tools/memory?user=4");

    const table = await screen.findByRole("table");
    expect(
      await within(table).findByRole("button", { name: "Shop layout" }),
    ).toBeInTheDocument();
    expect(
      within(table).queryByRole("button", { name: "Brand voice" }),
    ).toBeNull();
  });

  it("says when nothing matches, and clears the filters", async () => {
    const user = userEvent.setup();
    renderMemory("/tools/memory?tag=nothing");

    const empty = (
      await screen.findByText("No memories match these filters.")
    ).closest(".el-empty") as HTMLElement;
    await user.click(
      within(empty).getByRole("button", { name: "Clear filters" }),
    );

    await waitFor(() => expect(location.current).not.toContain("tag="));
    expect(
      await screen.findByRole("button", { name: "Brand voice" }),
    ).toBeInTheDocument();
  });

  it("explains memories when there are none", async () => {
    renderMemory("/tools/memory", { memories: [] });

    expect(await screen.findByText("No memories yet.")).toBeInTheDocument();
  });
});

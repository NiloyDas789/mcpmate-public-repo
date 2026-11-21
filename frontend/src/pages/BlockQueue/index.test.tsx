import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { renderPage } from "../../test/renderPage";
import type { BlockBatch } from "../../types/api";
import BlockBatchDetail from "./Detail";
import BlockQueue from "./index";
import { runFinalization } from "./runner";

vi.mock("./runner", () => ({ runFinalization: vi.fn() }));

/** Text on the page, not its copy in the app's live regions. */
const onPage = { ignore: "[role=status] *, [role=alert] *, script, style" };

afterEach(() => {
  vi.unstubAllGlobals();
  vi.mocked(runFinalization).mockReset();
});

describe("Block editor queue", () => {
  it("lists the batches waiting for the admin, with who queued them through which app", async () => {
    renderPage(<BlockQueue />, {
      path: "/activity/block-queue",
      route: "activity/block-queue",
    });

    const table = await screen.findByRole("table", {
      name: /Block editor batches, newest first/,
    });
    const rows = (await within(table).findAllByRole("rowheader")).map(
      (cell) => cell.closest("tr") as HTMLElement,
    );
    expect(rows).toHaveLength(1);
    expect(rows[0]).toHaveTextContent("Spring menu pages");
    expect(rows[0]).toHaveTextContent("2 changes");
    expect(rows[0]).toHaveTextContent("Waiting for you");
    expect(rows[0]).toHaveTextContent("Sam Editor");
    expect(rows[0]).toHaveTextContent("Claude");
    expect(
      within(rows[0]).getByRole("link", { name: "Open Spring menu pages" }),
    ).toHaveAttribute("href", "/activity/block-queue/7");
  });

  it("shows finalized batches under All, by number when they have no label", async () => {
    const user = userEvent.setup();
    renderPage(<BlockQueue />, {
      path: "/activity/block-queue",
      route: "activity/block-queue",
    });
    await screen.findByRole("table");

    await user.click(screen.getByRole("radio", { name: "All" }));

    expect(await screen.findByText("Batch #5")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open Batch #5" }),
    ).toHaveTextContent("View");
  });

  it("says when nothing waits", async () => {
    renderPage(<BlockQueue />, {
      path: "/activity/block-queue",
      route: "activity/block-queue",
      scenario: "none",
    });

    expect(
      await screen.findByText("Nothing waiting for you."),
    ).toBeInTheDocument();
  });
});

describe("A batch", () => {
  const renderBatch = (id = 7) =>
    renderPage(<BlockBatchDetail />, {
      path: `/activity/block-queue/${id}`,
      route: "activity/block-queue/:id",
    });

  it("shows each change and what finalizing does before the admin starts it", async () => {
    renderBatch();

    expect(
      await screen.findByRole("heading", {
        level: 2,
        name: "Spring menu pages",
      }),
    ).toHaveFocus();
    expect(
      screen.getByText("An AI app asks you to finalize these post changes."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /A published post is never changed: it gets a new draft copy/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("New tea list on the menu and the about page."),
    ).toBeInTheDocument();

    const table = screen.getByRole("table", {
      name: "Post changes, in the order they are applied.",
    });
    const rows = within(table)
      .getAllByRole("rowheader")
      .map((cell) => cell.closest("tr") as HTMLElement);
    expect(rows[0]).toHaveTextContent("Tea menu");
    expect(rows[0]).toHaveTextContent("New blocks");
    expect(rows[0]).toHaveTextContent("3.4 KB");
    expect(rows[1]).toHaveTextContent("Re-save its own blocks");
    expect(rows[1]).toHaveTextContent("publish");
    expect(
      screen.getByRole("button", { name: "Finalize in the block editor" }),
    ).toBeEnabled();
  });

  it("finalizes in this browser and shows where each change went", async () => {
    const user = userEvent.setup();
    const { site } = renderBatch();
    await screen.findByRole("heading", { level: 2 });

    const finalized = {
      ...(site.blockBatches()[1] as BlockBatch),
      id: 7,
      label: "Spring menu pages",
    };
    vi.mocked(runFinalization).mockImplementation(async (_id, deps) => {
      deps.onProgress?.({
        step: "loading",
        index: 0,
        total: 2,
        title: "Tea menu",
      });
      return { ok: true, batch: finalized };
    });

    await user.click(
      screen.getByRole("button", { name: "Finalize in the block editor" }),
    );

    expect(runFinalization).toHaveBeenCalledWith(
      7,
      expect.objectContaining({ signal: { aborted: false } }),
    );
    expect(
      await screen.findByText(
        "Finalized. Every post was saved as a draft: review them before you publish.",
        onPage,
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("Saved to the draft.")).toBeInTheDocument();
    expect(
      screen.getByText("The block editor repaired 1 invalid block."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /Review the draft/ }),
    ).toHaveAttribute(
      "href",
      "https://example.com/wp-admin/post.php?post=40&action=edit",
    );
    expect(
      screen.queryByRole("button", { name: "Finalize in the block editor" }),
    ).not.toBeInTheDocument();
  });

  it("says why finalizing stopped", async () => {
    const user = userEvent.setup();
    renderBatch();
    await screen.findByRole("heading", { level: 2 });
    vi.mocked(runFinalization).mockResolvedValue({
      ok: false,
      batch: null,
      error: new Error("The block editor did not load in 60 s."),
    });

    await user.click(
      screen.getByRole("button", { name: "Finalize in the block editor" }),
    );

    expect(
      await screen.findByText(
        "Finalizing stopped, and nothing more was saved: The block editor did not load in 60 s.",
        onPage,
      ),
    ).toBeInTheDocument();
    // Said at once, assertively.
    expect(screen.getByRole("alert")).toHaveTextContent("Finalizing stopped");
  });

  it("moves a batch to the trash after asking, changing no post", async () => {
    const user = userEvent.setup();
    const { site } = renderBatch();
    await screen.findByRole("heading", { level: 2 });

    await user.click(screen.getByRole("button", { name: "Move to trash" }));
    const dialog = await screen.findByRole("alertdialog", {
      name: "Move this batch to the trash?",
    });
    await user.click(
      within(dialog).getByRole("button", { name: "Move to trash" }),
    );

    await waitFor(() =>
      expect(site.blockBatches().find((batch) => batch.id === 7)?.trashed).toBe(
        true,
      ),
    );
    expect(await screen.findByText("In the trash")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Finalize in the block editor" }),
    ).not.toBeInTheDocument();
  });

  it("says when a batch cannot be loaded", async () => {
    renderBatch(404);

    expect(
      await screen.findByText("Couldn't load this batch."),
    ).toBeInTheDocument();
    expect(screen.getByText("There is no batch.")).toBeInTheDocument();
  });
});

import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { location, renderPage } from "../../test/renderPage";
import DesignPanel from "./DesignPanel";

afterEach(() => {
  vi.unstubAllGlobals();
});

const renderDesign = (
  path = "/tools/design",
  scenario: "one" | "none" = "one",
) => renderPage(<DesignPanel />, { path, route: "tools/design", scenario });

/** The admin-ajax actions the page called, with their form fields. */
function calls(
  fetchMock: ReturnType<typeof vi.fn>,
  action: string,
): Record<string, string>[] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData && body.get("action") === `MCPMATE_${action}`,
    )
    .map((body) =>
      Object.fromEntries(
        [...body.entries()].map(([key, value]) => [key, String(value)]),
      ),
    );
}

describe("Tools → Design", () => {
  it("says which design applies and lists the saved ones with their colours", async () => {
    renderDesign();

    expect(
      await screen.findByText(
        "“Tidewater” is the active design. AI apps build every page with its tokens.",
      ),
    ).toBeInTheDocument();

    const table = screen.getByRole("table", {
      name: "Saved designs, most recently changed first.",
    });
    const rows = (await within(table).findAllByRole("rowheader")).map(
      (cell) => cell.closest("tr") as HTMLElement,
    );
    expect(rows[0]).toHaveTextContent("Tidewater");
    expect(rows[0]).toHaveTextContent("Active");
    expect(
      within(rows[0]).getByRole("img", {
        name: "Colours: primary #0e5a63, sand #f6f1e8",
      }),
    ).toBeInTheDocument();
    expect(rows[1]).toHaveTextContent("Saved");
    expect(screen.getByRole("radio", { name: "Saved (2)" })).toBeChecked();
  });

  it("opens a design with its token preview and revisions, and saves it", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderDesign("/tools/design?design=12");

    const drawer = await screen.findByRole("dialog", { name: "Tidewater" });
    expect(
      within(drawer).getByText("This is the active design. AI apps follow it."),
    ).toBeInTheDocument();
    expect(
      await within(drawer).findByRole("region", { name: "Colours" }),
    ).toHaveTextContent("primary#0e5a63");
    expect(
      within(drawer).getByRole("region", { name: "Spacing" }),
    ).toHaveTextContent("md20px");
    expect(
      within(drawer).getByRole("button", { name: /Restore the revision of/ }),
    ).toBeInTheDocument();
    expect(
      within(drawer).queryByRole("button", { name: "Move to trash" }),
    ).not.toBeInTheDocument();

    await user.click(
      within(drawer).getByRole("button", { name: "Save design" }),
    );

    expect(
      await within(drawer).findByText("Design saved."),
    ).toBeInTheDocument();
    expect(calls(fetchMock, "designs/save")[0]).toMatchObject({
      id: "12",
      name: "Tidewater",
    });
  });

  it("shows the problems of a new design before it is saved, and the server's refusal", async () => {
    const user = userEvent.setup();
    renderDesign("/tools/design?design=new");

    const drawer = await screen.findByRole("dialog", { name: "New design" });
    const content = within(drawer).getByRole("textbox", {
      name: "Design (Markdown)",
    });
    await user.clear(content);
    await user.type(
      content,
      "---{enter}tokens:{enter}  colors:{enter}    - red{enter}---",
    );

    expect(
      await within(drawer).findByText(
        "Line 4: Lists are not supported.",
        {},
        { timeout: 3000 },
      ),
    ).toBeInTheDocument();
    expect(content).toHaveAttribute("aria-invalid", "true");

    await user.click(
      within(drawer).getByRole("button", { name: "Save design" }),
    );
    expect(
      await within(drawer).findByText(
        /Nothing was saved: the design has 1 problems/,
      ),
    ).toBeInTheDocument();
  });

  it("activates a saved design and turns it off again", async () => {
    const user = userEvent.setup();
    const { site } = renderDesign("/tools/design?design=15");

    const drawer = await screen.findByRole("dialog", { name: "Broadsheet" });
    const name = within(drawer).getByRole("textbox", { name: "Name" });
    await user.clear(name);
    await user.type(name, "Unsaved draft name");
    await user.click(
      within(drawer).getByRole("button", { name: "Make active" }),
    );

    expect(
      await within(drawer).findByText(
        "“Broadsheet” is now the active design. AI apps follow it from their next conversation.",
      ),
    ).toBeInTheDocument();
    expect(name).toHaveValue("Unsaved draft name");
    expect(site.designs().find((design) => design.active)?.id).toBe(15);

    await user.click(within(drawer).getByRole("button", { name: "Turn off" }));
    await waitFor(() =>
      expect(site.designs().some((design) => design.active)).toBe(false),
    );
  });

  it("adds a starter design as a new, inactive copy and opens it", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderDesign();
    await screen.findByRole("table");

    await user.click(screen.getByRole("button", { name: "Starter designs" }));
    const library = await screen.findByRole("region", {
      name: "Starter designs",
    });
    await user.click(
      within(library).getByRole("button", {
        name: "Add Nocturne to my designs",
      }),
    );

    await waitFor(() =>
      expect(calls(fetchMock, "designs/import")).toEqual([
        expect.objectContaining({ slug: "nocturne" }),
      ]),
    );
    await waitFor(() => expect(location.current).toMatch(/design=16/));
  });

  it("imports a design from a Markdown file", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderDesign();
    await screen.findByRole("table");

    await user.click(screen.getByRole("button", { name: "Import" }));
    const dialog = await screen.findByRole("dialog", {
      name: "Import a design",
    });
    const file = new File(
      ['---\nname: Spring\ntokens:\n  colors:\n    a: "#fff"\n---\n'],
      "spring-2027.md",
      { type: "text/markdown" },
    );
    await user.upload(within(dialog).getByLabelText("Design file"), file);

    expect(within(dialog).getByRole("textbox", { name: "Name" })).toHaveValue(
      "spring 2027",
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Import design" }),
    );

    await waitFor(() =>
      expect(calls(fetchMock, "designs/import")[0]).toMatchObject({
        name: "spring 2027",
      }),
    );
    expect(calls(fetchMock, "designs/import")[0].content).toContain("tokens:");
  });

  it("offers the starter designs when nothing is saved yet", async () => {
    renderDesign("/tools/design", "none");

    expect(await screen.findByText("No designs yet.")).toBeInTheDocument();
    expect(
      await screen.findByText(
        "No design is active. AI apps follow your theme's own styles.",
      ),
    ).toBeInTheDocument();
    expect(
      await screen.findByRole("button", {
        name: "Add Tidewater to my designs",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Starter designs" }),
    ).toHaveAttribute("aria-expanded", "true");
    expect(
      screen.getByRole("button", { name: "Starter designs" }),
    ).toBeDisabled();
  });
});

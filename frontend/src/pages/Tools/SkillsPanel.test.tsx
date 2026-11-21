import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { location, renderPage } from "../../test/renderPage";
import type { FakeSiteOptions } from "../../test/fakeSite";
import type { SavedSkill } from "../../types/api";
import Tools from "./index";
import { skillViewFromQuery } from "./skillLogic";

afterEach(() => {
  vi.unstubAllGlobals();
});

const skill = (id: number, extra: Partial<SavedSkill> = {}): SavedSkill => ({
  id,
  user: { id: 3, login: "ann", name: "Ann" },
  name: `skill-${id}`,
  description: `About skill ${id}.`,
  this_site_only: false,
  bytes: 20,
  updated_at: `2026-11-0${id}T10:00:00Z`,
  trashed_at: null,
  body: `Body of skill ${id}.`,
  ...extra,
});

const SKILLS = [
  skill(1, { name: "brand-style", body: "Use navy." }),
  skill(2, { name: "shop-layout", this_site_only: true }),
  skill(3, { name: "old-layout", trashed_at: "2026-11-03T11:00:00Z" }),
];

function renderSkills(path = "/tools/skills", options: FakeSiteOptions = {}) {
  return renderPage(<Tools />, {
    path,
    route: "tools/*",
    skills: SKILLS,
    ...options,
  });
}

function sent(fetchMock: ReturnType<typeof vi.fn>, route: string) {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData && body.get("action") === `MCPMATE_${route}`,
    )
    .map((body) => Object.fromEntries(body.entries()));
}

describe("Tools → Skills", () => {
  it("reads its state from the hash query", () => {
    expect(skillViewFromQuery("?status=trash&skill=4")).toEqual({
      status: "trash",
      skill: 4,
      playbook: null,
    });
    expect(skillViewFromQuery("?playbook=seo-audit&skill=x")).toEqual({
      status: "live",
      skill: null,
      playbook: "seo-audit",
    });
    expect(skillViewFromQuery("?playbook=../x").playbook).toBeNull();
  });

  it("is a tab without MCPMate Pro too, and lists saved skills and playbooks", async () => {
    SERVER_VARIABLES.pro = undefined;
    renderSkills();

    expect(await screen.findByRole("tab", { name: "Skills" })).toHaveAttribute(
      "aria-selected",
      "true",
    );
    const saved = await screen.findByRole("table");
    const playbooks = await screen.findByRole("list", {
      name: "MCPMate playbooks",
    });
    await within(saved).findByRole("button", { name: "shop-layout" });
    expect(
      within(saved)
        .getAllByRole("rowheader")
        .map((cell) => within(cell).getByRole("button").textContent),
    ).toEqual(["shop-layout", "brand-style"]);
    expect(within(saved).getByText("This site")).toBeInTheDocument();
    expect(within(saved).queryByText("old-layout")).toBeNull();
    expect(
      within(playbooks).getByRole("button", { name: "seo-audit" }),
    ).toBeInTheDocument();
    expect(
      within(playbooks).getByText("site_info, get_page_html, run_wp_cli"),
    ).toBeInTheDocument();
  });

  it("filters the playbooks by name, description or tool, kept in the hash query", async () => {
    const user = userEvent.setup();
    renderSkills();

    const playbooks = await screen.findByRole("list", {
      name: "MCPMate playbooks",
    });
    expect(within(playbooks).getAllByRole("listitem")).toHaveLength(2);
    expect(
      screen.getByText("2 playbooks", { selector: ".el-skills-count" }),
    ).toBeInTheDocument();

    await user.type(
      screen.getByRole("searchbox", { name: "Filter playbooks" }),
      "save_skill",
    );
    await waitFor(() =>
      expect(
        screen.getByText("1 playbook matches.", {
          selector: ".el-skills-count",
        }),
      ).toBeInTheDocument(),
    );
    // The list is drawn again as the filter narrows it: find it again.
    const matching = screen.getByRole("list", { name: "MCPMate playbooks" });
    expect(
      within(matching).getByRole("button", { name: "skill-creator" }),
    ).toBeInTheDocument();
    expect(
      within(matching).queryByRole("button", { name: "seo-audit" }),
    ).toBeNull();
    expect(location.current).toContain("find=save_skill");

    await user.clear(
      screen.getByRole("searchbox", { name: "Filter playbooks" }),
    );
    await user.type(
      screen.getByRole("searchbox", { name: "Filter playbooks" }),
      "zzz",
    );
    await user.click(
      await screen.findByRole("button", { name: "Clear filter" }),
    );
    await waitFor(() =>
      expect(
        screen.getByText("2 playbooks", { selector: ".el-skills-count" }),
      ).toBeInTheDocument(),
    );
  });

  it("shows a playbook read-only", async () => {
    const user = userEvent.setup();
    renderSkills();

    await user.click(await screen.findByRole("button", { name: "seo-audit" }));
    const drawer = await screen.findByRole("dialog", { name: "seo-audit" });

    expect(location.current).toContain("playbook=seo-audit");
    expect(
      (await within(drawer).findByRole("region", { name: "Skill text" }))
        .textContent,
    ).toContain("# SEO audit");
    expect(within(drawer).queryByRole("textbox")).toBeNull();
    expect(
      within(drawer).queryByRole("button", { name: "Save changes" }),
    ).toBeNull();
  });

  it("copies a playbook's text from its drawer", async () => {
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: { writeText },
    });
    renderSkills("/tools/skills?playbook=seo-audit");

    const drawer = await screen.findByRole("dialog", { name: "seo-audit" });
    await within(drawer).findByRole("region", { name: "Skill text" });
    await user.click(
      within(drawer).getByRole("button", { name: "Copy Playbook" }),
    );

    expect(writeText).toHaveBeenCalledWith(
      "# SEO audit\n\nUse this when the user asks for an SEO review.",
    );
    expect(
      await within(drawer).findByRole("button", { name: "Copied" }),
    ).toBeInTheDocument();
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: undefined,
    });
  });

  it("edits a saved skill", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSkills("/tools/skills?skill=1");

    const drawer = await screen.findByRole("dialog", { name: "brand-style" });
    const body = await within(drawer).findByRole("textbox", { name: "Skill" });
    expect(body).toHaveValue("Use navy.");
    fireEvent.change(body, { target: { value: "Use teal." } });
    await user.click(
      within(drawer).getByRole("button", { name: "Save changes" }),
    );

    await waitFor(() =>
      expect(
        sent(fetchMock, "skills/update").map(({ id, description, body }) => ({
          id,
          description,
          body,
        })),
      ).toEqual([
        { id: "1", description: "About skill 1.", body: "Use teal." },
      ]),
    );
    const results = await axe.run(drawer, {
      rules: { "color-contrast": { enabled: false } },
    });
    expect(results.violations.map((violation) => violation.id)).toEqual([]);
  });

  it("checks the description before sending", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSkills("/tools/skills?skill=1");

    const drawer = await screen.findByRole("dialog", { name: "brand-style" });
    const description = await within(drawer).findByRole("textbox", {
      name: "Description",
    });
    fireEvent.change(description, { target: { value: "" } });
    await user.click(
      within(drawer).getByRole("button", { name: "Save changes" }),
    );

    expect(
      within(drawer).getByText(
        "Give a description of one line, up to 200 characters.",
      ),
    ).toBeInTheDocument();
    expect(description).toHaveFocus();
    expect(sent(fetchMock, "skills/update")).toEqual([]);
  });

  it("restores a skill from the trash", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSkills("/tools/skills?status=trash");

    const [trash] = await screen.findAllByRole("table");
    await user.click(
      await within(trash).findByRole("button", { name: "Restore: old-layout" }),
    );

    await waitFor(() =>
      expect(sent(fetchMock, "skills/restore").map((b) => b.id)).toEqual(["3"]),
    );
    expect(
      await screen.findByRole("radio", { name: "Saved (3)" }),
    ).toBeInTheDocument();
  });

  it("deletes a trashed skill for good after asking", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSkills("/tools/skills?status=trash");

    const [trash] = await screen.findAllByRole("table");
    await user.click(
      await within(trash).findByRole("button", {
        name: "Delete permanently: old-layout",
      }),
    );
    const dialog = await screen.findByRole("alertdialog", {
      name: "Delete this skill for good?",
    });
    expect(
      within(dialog).getByRole("button", { name: "Keep it" }),
    ).toHaveFocus();
    await user.click(
      within(dialog).getByRole("button", { name: "Delete permanently" }),
    );

    await waitFor(() =>
      expect(sent(fetchMock, "skills/delete").map((b) => b.id)).toEqual(["3"]),
    );
    expect(await screen.findByText("The trash is empty.")).toBeInTheDocument();
  });

  it("moves a saved skill to the trash from its drawer", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSkills("/tools/skills?skill=2");

    const drawer = await screen.findByRole("dialog", { name: "shop-layout" });
    await user.click(
      await within(drawer).findByRole("button", { name: "Move to trash" }),
    );

    await waitFor(() =>
      expect(sent(fetchMock, "skills/trash").map((b) => b.id)).toEqual(["2"]),
    );
    expect(
      await within(drawer).findByText(
        "This skill is in the trash. AI apps no longer see it.",
      ),
    ).toBeInTheDocument();
  });

  it("explains skills when none are saved", async () => {
    renderSkills("/tools/skills", { skills: [] });

    expect(await screen.findByText("No saved skills yet.")).toBeInTheDocument();
  });
});

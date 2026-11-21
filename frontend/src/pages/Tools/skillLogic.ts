import { __, _n, sprintf } from "@wordpress/i18n";
import { formatNumber } from "@utils/format";
import type { SkillsPage, SkillStatus } from "../../types/api";
import { contentBytes } from "./memoryLogic";

/**
 * Tools → Skills: its state in the hash query, and the checks an edit runs
 * before it is sent, with the words SkillsController uses.
 */

export interface SkillView {
  status: SkillStatus;
  /** The saved skill open in the drawer. */
  skill: number | null;
  /** The playbook open in the drawer. */
  playbook: string | null;
}

export function skillViewFromQuery(query: string): SkillView {
  const params = new URLSearchParams(query);
  const id = Number(params.get("skill"));
  const playbook = params.get("playbook") ?? "";

  return {
    status: params.get("status") === "trash" ? "trash" : "live",
    skill: Number.isInteger(id) && id > 0 ? id : null,
    playbook: /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(playbook) ? playbook : null,
  };
}

/** The playbooks filter (`?find=`), from the hash query. */
export function playbookFindFromQuery(query: string): string {
  return new URLSearchParams(query).get("find") ?? "";
}

/** Playbooks whose name, description or a needed tool holds the text. */
export function matchPlaybooks<
  T extends { name: string; description: string; tools: string[] },
>(playbooks: T[], find: string): T[] {
  const needle = find.trim().toLowerCase();
  if (!needle) {
    return playbooks;
  }

  return playbooks.filter((playbook) =>
    [playbook.name, playbook.description, ...playbook.tools].some((text) =>
      text.toLowerCase().includes(needle),
    ),
  );
}

/** "25 playbooks", or "3 playbooks match." while a filter is on. */
export function playbookCountText(count: number, filtered: boolean): string {
  return filtered
    ? sprintf(
        /* translators: %s: number of playbooks that match the filter. */
        _n("%s playbook matches.", "%s playbooks match.", count, "mcpmate"),
        formatNumber(count),
      )
    : sprintf(
        /* translators: %s: number of playbooks. */
        _n("%s playbook", "%s playbooks", count, "mcpmate"),
        formatNumber(count),
      );
}

export type SkillDraft = { description: string; body: string };

export type SkillErrors = Partial<Record<keyof SkillDraft, string>>;

/** What the server refuses, in its words (SkillsController::update()). */
export function skillErrors(
  draft: SkillDraft,
  limits: SkillsPage["limits"],
): SkillErrors {
  const errors: SkillErrors = {};
  const description = draft.description.trim();

  if (
    description === "" ||
    [...description].length > limits.description_chars ||
    /[\r\n]/.test(description)
  ) {
    errors.description = sprintf(
      /* translators: %s: the limit in characters. */
      __("Give a description of one line, up to %s characters.", "mcpmate"),
      formatNumber(limits.description_chars),
    );
  }

  const bytes = contentBytes(draft.body);
  if (bytes === 0 || bytes > limits.body_bytes) {
    errors.body = sprintf(
      /* translators: %s: the limit in bytes. */
      __("Write the skill as Markdown, up to %s bytes.", "mcpmate"),
      formatNumber(limits.body_bytes),
    );
  }

  return errors;
}

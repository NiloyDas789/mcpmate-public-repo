import { ajaxOrThrow } from "@services/api";
import type {
  Design,
  DesignPreview,
  DesignStarter,
  DesignsPage,
} from "../types/api";

/**
 * Tools → Design's admin-ajax routes (MCPMate Pro, nonce + manage_options;
 * no REST route, so no MCP call reaches them). Each throws an
 * AjaxRequestError with the server's code and message; a refused save
 * carries `errors` ("Line n: …") in its details.
 */
export const DESIGNS_QUERY_KEY = ["designs"] as const;

export function fetchDesigns(
  status: "saved" | "trash",
  page: number,
): Promise<DesignsPage> {
  return ajaxOrThrow<DesignsPage>("designs/list", { status, page });
}

export async function fetchDesign(
  id: number,
  revision?: number,
): Promise<Design> {
  return (
    await ajaxOrThrow<{ design: Design }>("designs/show", {
      id,
      ...(revision ? { revision } : {}),
    })
  ).design;
}

/** The tokens, problems and warnings of a draft. Saves nothing. */
export function previewDesign(content: string): Promise<DesignPreview> {
  return ajaxOrThrow<DesignPreview>("designs/preview", { content });
}

export async function saveDesign(draft: {
  id: number | null;
  name: string;
  content: string;
}): Promise<Design> {
  return (
    await ajaxOrThrow<{ design: Design }>("designs/save", {
      ...(draft.id ? { id: draft.id } : {}),
      name: draft.name,
      content: draft.content,
    })
  ).design;
}

export async function activateDesign(id: number): Promise<Design> {
  return (await ajaxOrThrow<{ design: Design }>("designs/activate", { id }))
    .design;
}

export async function deactivateDesign(): Promise<void> {
  await ajaxOrThrow("designs/deactivate", {});
}

export async function moveDesign(
  id: number,
  to: "trash" | "restore",
): Promise<Design> {
  return (await ajaxOrThrow<{ design: Design }>(`designs/${to}`, { id }))
    .design;
}

export async function restoreDesignRevision(
  id: number,
  revision: number,
): Promise<Design> {
  return (
    await ajaxOrThrow<{ design: Design }>("designs/revisions/restore", {
      id,
      revision,
    })
  ).design;
}

export async function fetchStarters(): Promise<DesignStarter[]> {
  return (await ajaxOrThrow<{ starters: DesignStarter[] }>("designs/library"))
    .starters;
}

/** A starter (`slug`) or a pasted or uploaded design, as a new design. */
export async function importDesign(
  source: { slug: string } | { name: string; content: string },
): Promise<Design> {
  return (await ajaxOrThrow<{ design: Design }>("designs/import", source))
    .design;
}

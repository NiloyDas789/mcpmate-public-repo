import { ajaxOrThrow } from "@services/api";
import type { SandboxListing, SandboxPlugin, SandboxShow } from "../types/api";

/**
 * Tools → Sandbox's admin-ajax routes (MCPMate Pro, nonce + manage_options;
 * no REST route, so no MCP call reaches them). There is no enable here: a
 * sandbox plugin runs only after a site admin approves an AI app's request
 * on Activity → Approvals, which shows its code.
 */
export const SANDBOX_QUERY_KEY = ["sandbox"] as const;

export function fetchSandbox(): Promise<SandboxListing> {
  return ajaxOrThrow<SandboxListing>("sandbox/list", {});
}

/** One plugin's files, and with `path` that file's code. */
export function showSandbox(slug: string, path?: string): Promise<SandboxShow> {
  return ajaxOrThrow<SandboxShow>("sandbox/show", {
    slug,
    ...(path ? { path } : {}),
  });
}

/** Stop loading it, from the next request on. */
export async function disableSandbox(slug: string): Promise<SandboxPlugin> {
  return (
    await ajaxOrThrow<{ plugin: SandboxPlugin }>("sandbox/disable", { slug })
  ).plugin;
}

/** Load a crashed plugin again: its crash marker goes. */
export async function retrySandbox(slug: string): Promise<SandboxPlugin> {
  return (
    await ajaxOrThrow<{ plugin: SandboxPlugin }>("sandbox/retry", { slug })
  ).plugin;
}

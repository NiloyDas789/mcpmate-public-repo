import { __, sprintf } from "@wordpress/i18n";
import type { EnvironmentCheck, OnboardingStep } from "../../types/api";

/** The five steps, in order. */
export const STEPS: OnboardingStep[] = [
  "welcome",
  "check",
  "safety",
  "add_ai",
  "first_call",
];

export function stepTitle(step: OnboardingStep): string {
  switch (step) {
    case "welcome":
      return __("Welcome", "mcpmate");
    case "check":
      return __("Check your site", "mcpmate");
    case "safety":
      return __("Choose what your AI can do", "mcpmate");
    case "add_ai":
      return __("Add it to your AI", "mcpmate");
    case "first_call":
      return __("Try it", "mcpmate");
  }
}

/** "Step 2 of 5: Check your site" */
export function stepPosition(step: OnboardingStep): string {
  return sprintf(
    /* translators: 1: step number, 2: number of steps, 3: step name. */
    __("Step %1$d of %2$d: %3$s", "mcpmate"),
    STEPS.indexOf(step) + 1,
    STEPS.length,
    stepTitle(step),
  );
}

/** Rows shown while the checks run (the server sends the real titles). */
export function pendingChecks(): Pick<EnvironmentCheck, "id" | "title">[] {
  return [
    { id: "https", title: __("Your site uses HTTPS", "mcpmate") },
    {
      id: "permalinks",
      title: __("Your site has readable addresses (permalinks)", "mcpmate"),
    },
    {
      id: "mcp_endpoint",
      title: __("AI apps can reach your MCP URL", "mcpmate"),
    },
    {
      id: "auth_header",
      title: __("Sign-in details reach WordPress", "mcpmate"),
    },
    {
      id: "well_known",
      title: __("AI apps can find how to sign in", "mcpmate"),
    },
    {
      id: "caching",
      title: __("Caches don't store sign-in answers", "mcpmate"),
    },
    {
      id: "execution_time",
      title: __("Requests have enough time to finish", "mcpmate"),
    },
    {
      id: "versions",
      title: __("WordPress and PHP are up to date enough", "mcpmate"),
    },
    { id: "admin", title: __("You're an administrator", "mcpmate") },
    { id: "environment", title: __("Where this site runs", "mcpmate") },
  ];
}

/**
 * Plain words for what the first call did, from its route under
 * mcpmate/v1. Unknown routes are "made a request".
 */
export function actionLabel(route: string): string {
  const base = route.split("/")[0];

  switch (base) {
    case "site-info":
      return __("read your site's details", "mcpmate");
    case "page-html":
      return __("read a page", "mcpmate");
    case "post-structure":
      return __("read a page's structure", "mcpmate");
    case "proxy":
      return __("worked with your posts", "mcpmate");
    case "blocks":
      return __("checked block markup", "mcpmate");
    case "audit-log":
      return __("read the audit log", "mcpmate");
    default:
      return __("made a request", "mcpmate");
  }
}

/**
 * The switched-off feature an error message names (`code: message` in the
 * audit row), as a Settings card name, or null.
 */
export function disabledFeature(message: string): string | null {
  const code = message.split(":")[0];

  switch (code) {
    case "mcpmate_proxy_disabled":
      return __("Posts and pages", "mcpmate");
    case "mcpmate_cli_command_disabled":
      return __("CLI commands", "mcpmate");
    case "mcpmate_db_inspect_disabled":
      return __("Database inspection", "mcpmate");
    case "mcpmate_media_disabled":
      return __("Media uploads", "mcpmate");
    case "mcpmate_svg_disabled":
      return __("SVG images", "mcpmate");
    case "mcpmate_theme_drafts_disabled":
      return __("Theme drafts", "mcpmate");
    case "mcpmate_elementor_disabled":
      return __("Elementor pages", "mcpmate");
    case "mcpmate_ability_disabled":
      return __("Other plugins' actions", "mcpmate");
    default:
      return null;
  }
}

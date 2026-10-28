import { __, sprintf } from "@wordpress/i18n";
import { AjaxRequestError } from "@services/api";

/**
 * What went wrong with a request to this site, in plain words (docs/ui.md
 * §9: errors say what happened and what to do).
 */
export function requestErrorText(error: unknown): string {
  const kind = error instanceof AjaxRequestError ? error.kind : null;

  if (kind === "session") {
    return __(
      "Your WordPress session has expired. Reload the page to continue.",
      "mcpmate",
    );
  }

  if (kind === "network") {
    return __(
      "Couldn't reach your site. Check your connection and try again.",
      "mcpmate",
    );
  }

  if (kind === "server") {
    return sprintf(
      /* translators: %s: HTTP status or error code. */
      __(
        "Something went wrong on your site (error %s). Try again. If it keeps happening, the audit log or your PHP error log may say more.",
        "mcpmate",
      ),
      error instanceof AjaxRequestError && error.status
        ? String(error.status)
        : "?",
    );
  }

  return error instanceof Error && error.message
    ? error.message
    : __("Something went wrong. Try again.", "mcpmate");
}

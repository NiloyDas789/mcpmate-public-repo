/**
 * The two children of #mcpmate-root (Views\Body): the app, and the
 * container every overlay and live region renders into. Both fall back to
 * `document.body` where they are missing (tests).
 */
export const APP_ID = "mcpmate-app";
export const PORTAL_ID = "mcpmate-portal";

export function portalContainer(): HTMLElement {
  return document.getElementById(PORTAL_ID) ?? document.body;
}

let inertCount = 0;

/**
 * Make the app behind a dialog inert (docs/ui.md §7, Modal). Counted, so a
 * dialog over a drawer does not wake the app when it closes. Returns the
 * function that undoes it.
 */
export function makeAppInert(): () => void {
  const app = document.getElementById(APP_ID);
  inertCount++;
  app?.setAttribute("inert", "");

  let done = false;
  return () => {
    if (done) {
      return;
    }
    done = true;
    inertCount = Math.max(0, inertCount - 1);
    if (inertCount === 0) {
      app?.removeAttribute("inert");
    }
  };
}

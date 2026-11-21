/**
 * The plugin's `/authorize` sends the browser to
 * `admin.php?page=mcpmate&connect=<request>` to approve an AI app: a
 * query argument, because a `#/` route would be lost in the wp-login.php
 * redirect. Returns the SPA route to open for it, or null when the page
 * already has a route. PHP checks the request itself.
 */
export function connectRoute(search: string, hash: string): string | null {
  if (!new URLSearchParams(search).get("connect")) {
    return null;
  }

  if (hash !== "" && hash !== "#" && hash !== "#/") {
    return null;
  }

  return "#/connect";
}

/**
 * Whether `url` may be navigated to after an approval: only the expected
 * origin, the AI app's registered redirect URI (the server built the URL
 * from it).
 */
export function isSameOrigin(url: string, origin: string): boolean {
  if (!origin) {
    return false;
  }

  try {
    return new URL(url).origin === new URL(origin).origin;
  } catch {
    return false;
  }
}

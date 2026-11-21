/**
 * `admin.php?page=mcpmate&setup=1` (the activation redirect, the
 * resume notice, the Plugins row link) opens the setup wizard. A query
 * argument, like `approval=`, so it survives the wp-login.php redirect.
 * Returns the SPA route, or null when the page already has a route.
 */
export function setupRoute(search: string, hash: string): string | null {
  if (new URLSearchParams(search).get("setup") !== "1") {
    return null;
  }

  if (hash !== "" && hash !== "#" && hash !== "#/") {
    return null;
  }

  return "#/setup";
}

/**
 * Leave the admin app for another page (an AI app's redirect URI after an approval).
 * A module of its own so tests can replace it.
 */
export function goTo(url: string): void {
  window.location.assign(url);
}

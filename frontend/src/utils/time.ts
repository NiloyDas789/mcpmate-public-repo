/** `2026-10-24T09:30:00Z` → `2026-10-24 09:30:00 UTC` (the server stores UTC). */
export function formatUtc(iso: string): string {
  return iso.replace("T", " ").replace("Z", " UTC");
}

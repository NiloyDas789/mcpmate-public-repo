/**
 * Plugin logos for the Tools page's tiles: `resource/integrations/<id>.svg`
 * (or .png, .webp), named by the integration's ID. A file added there is
 * picked up at the next build; a tile without one keeps an empty slot.
 */
const files = import.meta.glob<string>(
  "../../resource/integrations/*.{svg,png,webp}",
  { eager: true, query: "?url", import: "default" },
);

const LOGOS: Record<string, string> = Object.fromEntries(
  Object.entries(files).map(([path, url]) => [
    path.replace(/^.*\/([^/]+)\.[a-z]+$/, "$1"),
    url,
  ]),
);

/** Tiles that are not a plugin, by the logo they show. */
const ALIASES: Record<string, string> = { stock_photos: "unsplash" };

export function logoFor(id: string): string | undefined {
  return LOGOS[ALIASES[id] ?? id];
}

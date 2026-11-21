import { __, _n, sprintf } from "@wordpress/i18n";
import Skeleton from "@components/ui/Skeleton";
import { cx } from "@utils/classNames";
import {
  categoryLabel,
  tilesByCategory,
  type PluginTile,
} from "./integrationLogic";
import { logoFor } from "./integrationLogos";
import { tileStatus } from "./toolText";

/** The logo on its square, or the empty square a logo file fills. */
export function PluginLogo({ id, size }: { id: string; size: "md" | "lg" }) {
  const src = logoFor(id);

  return (
    <span className={cx("el-plugin-logo", `el-plugin-logo-${size}`)}>
      {src && <img src={src} alt="" decoding="async" />}
    </span>
  );
}

/**
 * Tools → Integrations: a tile per plugin under its heading (Forms, SEO…).
 * A tile opens the plugin's tools in a dialog (`?plugin=`). While a filter
 * or search narrows the list, only tiles with a matching tool stay.
 */
export default function IntegrationGrid({
  tiles,
  narrowed,
  onOpen,
}: {
  tiles: PluginTile[];
  narrowed: boolean;
  onOpen: (tile: PluginTile) => void;
}) {
  return (
    <div className="el-plugin-shelf">
      {tilesByCategory(tiles, narrowed).map(({ category, tiles: shown }) => (
        <section
          key={category}
          className="el-plugin-category"
          aria-labelledby={`el-plugin-category-${category}`}
        >
          <h2
            id={`el-plugin-category-${category}`}
            className="el-plugin-category-title"
          >
            {categoryLabel(category)}
          </h2>
          <ul className="el-plugin-grid">
            {shown.map((tile) => {
              const status = narrowed
                ? sprintf(
                    /* translators: %d: tools of this plugin that match the search or filter. */
                    _n(
                      "%d tool matches",
                      "%d tools match",
                      tile.matches,
                      "mcpmate",
                    ),
                    tile.matches,
                  )
                : tileStatus(tile);

              return (
                <li key={tile.id}>
                  <button
                    type="button"
                    className={cx(
                      "el-plugin-tile",
                      tile.status !== "active" && "el-plugin-tile-off",
                    )}
                    aria-haspopup="dialog"
                    // The visible name first, then its status (the spans would run together).
                    aria-label={sprintf(
                      /* translators: 1: plugin name, 2: its status, e.g. "3 of 11 on". */
                      __("%1$s, %2$s", "mcpmate"),
                      tile.label,
                      status,
                    )}
                    data-plugin={tile.id}
                    onClick={() => onOpen(tile)}
                  >
                    <PluginLogo id={tile.id} size="md" />
                    <span className="el-plugin-tile-name">{tile.label}</span>
                    <span className="el-plugin-tile-status">{status}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
    </div>
  );
}

/** The grid's shape while the tools load. */
export function IntegrationGridSkeleton() {
  return (
    <ul className="el-plugin-grid" aria-hidden="true">
      {Array.from({ length: 8 }, (_, index) => (
        <li key={index}>
          <span className="el-plugin-tile el-plugin-tile-loading">
            <span className="el-plugin-logo el-plugin-logo-md el-skeleton-block" />
            <Skeleton shape="text" count={2} announce={false} />
          </span>
        </li>
      ))}
    </ul>
  );
}

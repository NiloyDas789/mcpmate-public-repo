import { useRef, type ReactNode } from "react";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Modal from "@components/ui/Modal";
import Notice from "@components/ui/Notice";
import type { ToolSection } from "./logic";
import type { PluginTile } from "./integrationLogic";
import { PluginLogo } from "./IntegrationGrid";
import { adminUrl, tileStatus } from "./toolText";

/** One sentence under the plugin's name: what this dialog is for, or what it needs. */
function lead(tile: PluginTile): string {
  switch (tile.status) {
    case "missing":
      return sprintf(
        /* translators: %s: plugin name, e.g. Yoast SEO. */
        __(
          "Install %s to use these tools. Each one is off until you turn it on.",
          "mcpmate",
        ),
        tile.label,
      );
    case "not_configured":
      return __(
        "Add your own Unsplash access key to let AI apps search free stock photos.",
        "mcpmate",
      );
    default:
      return sprintf(
        /* translators: %s: plugin name, e.g. Yoast SEO. */
        __(
          "Choose what AI apps may do with %s. Each change is saved at once.",
          "mcpmate",
        ),
        tile.label,
      );
  }
}

/**
 * A plugin's tools in a dialog, opened from its tile: the same tri-state
 * groups as the list, saved at once. Follows the page's filter and search,
 * with Show all to clear them.
 */
export default function IntegrationModal({
  tile,
  onClose,
  renderSection,
  narrowed,
  onShowAll,
  saveStatus,
}: {
  tile: PluginTile | null;
  onClose: () => void;
  renderSection: (section: ToolSection) => ReactNode;
  narrowed: boolean;
  onShowAll: () => void;
  saveStatus: ReactNode;
}) {
  // Keeps the last plugin while the dialog closes.
  const last = useRef<PluginTile | null>(tile);
  if (tile) {
    last.current = tile;
  }
  const shownTile = tile ?? last.current;

  if (!shownTile) {
    return null;
  }

  const list = narrowed ? shownTile.shown : shownTile.sections;
  const runnable = shownTile.sections.some(
    (section) => section.group.available,
  );
  // Elementor and Stock photos say why in their own group (UnavailableGroup).
  const ownReason = shownTile.sections.some(
    (section) => !section.group.available,
  );

  return (
    <Modal
      title={shownTile.label}
      open={tile !== null}
      onClose={onClose}
      size="lg"
      describedBy="el-plugin-dialog-lead"
      footer={
        <div className="el-dialog-actions">
          <Button variant="secondary" onClick={onClose}>
            {__("Done", "mcpmate")}
          </Button>
        </div>
      }
    >
      <div className="el-plugin-dialog">
        <div className="el-plugin-dialog-head">
          <PluginLogo id={shownTile.id} size="lg" />
          <div>
            <p className="el-plugin-dialog-status">{tileStatus(shownTile)}</p>
            <p id="el-plugin-dialog-lead" className="el-plugin-dialog-lead">
              {lead(shownTile)}
            </p>
          </div>
        </div>

        {shownTile.status === "installed" && !ownReason && (
          <Notice
            tone="warning"
            title={sprintf(
              /* translators: %s: plugin or theme name, e.g. Yoast SEO. */
              __(
                "Activate %s first. Until then, AI apps get an error if they use these tools.",
                "mcpmate",
              ),
              shownTile.label,
            )}
            action={
              <a
                href={adminUrl(shownTile.theme ? "themes.php" : "plugins.php")}
              >
                {shownTile.theme
                  ? __("Activate in Themes", "mcpmate")
                  : __("Activate in Plugins", "mcpmate")}
              </a>
            }
          />
        )}

        {narrowed && runnable && shownTile.matches < shownTile.total && (
          <p className="el-plugin-dialog-narrowed">
            {sprintf(
              /* translators: 1: tools shown, 2: tools of this plugin. */
              __("Showing %1$d of %2$d tools.", "mcpmate"),
              shownTile.matches,
              shownTile.total,
            )}{" "}
            <Button variant="link" size="sm" onClick={onShowAll}>
              {__("Show all", "mcpmate")}
            </Button>
          </p>
        )}

        {list.length > 0 && (
          <div className="el-plugin-dialog-groups">
            {list.map(renderSection)}
          </div>
        )}

        {saveStatus}
      </div>
    </Modal>
  );
}

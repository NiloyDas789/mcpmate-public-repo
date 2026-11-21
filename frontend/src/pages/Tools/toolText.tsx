import { __, sprintf } from "@wordpress/i18n";
import { interpolate } from "@utils/i18n";
import type { ToolGroup } from "../../types/api";
import type { PluginTile } from "./integrationLogic";

/** Words and links the Tools page's list, tiles and plugin dialog share. */

/** A wp-admin screen next to admin-ajax.php (`plugins.php`). */
export const adminUrl = (file: string) =>
  SERVER_VARIABLES.ajaxURL.replace(/admin-ajax\.php$/, file);

export function onCount(on: number, total: number): string {
  return sprintf(
    /* translators: 1: tools turned on, 2: tools in the group. */
    __("%1$d of %2$d on", "mcpmate"),
    on,
    total,
  );
}

// ── A group the site cannot run ─────────────────

export function unavailableReason(group: ToolGroup) {
  switch (group.unavailable_reason) {
    case "plugin_inactive":
      return {
        badge: __("Installed, not active", "mcpmate"),
        body: sprintf(
          /* translators: %s: plugin name, e.g. Elementor. */
          __("Activate %s to use these tools.", "mcpmate"),
          group.label,
        ),
        action: {
          label: __("Activate in Plugins", "mcpmate"),
          href: adminUrl("plugins.php"),
        },
      };
    case "file_edit_disallowed":
      return {
        badge: __("Not available on this site", "mcpmate"),
        body: interpolate(
          /* translators: %s: the wp-config.php constant, DISALLOW_FILE_EDIT. */
          __(
            "File editing is turned off on this site (%s), so AI apps can't edit theme drafts.",
            "mcpmate",
          ),
          [
            <bdi dir="ltr" className="el-mono" key="constant">
              DISALLOW_FILE_EDIT
            </bdi>,
          ],
        ),
        action: null,
      };
    case "constant_missing":
      return {
        badge: __("Off in wp-config.php", "mcpmate"),
        body: interpolate(
          /* translators: %s: a line of PHP for wp-config.php, e.g. define( 'MCPMATE_ENABLE_EXECUTE_PHP', true ); */
          __(
            "These tools bypass MCPMate's usual limits, so they exist only when the site owner adds %s to wp-config.php. Then turn each one on here.",
            "mcpmate",
          ),
          [
            <bdi dir="ltr" className="el-mono" key="constant">
              {group.options.constant === "MCPMATE_CLI_PATH"
                ? "define( 'MCPMATE_CLI_PATH', '/usr/local/bin/wp' );"
                : `define( '${group.options.constant ?? ""}', true );`}
            </bdi>,
          ],
        ),
        action: null,
      };
    case "cli_unavailable":
      return {
        badge: __("WP-CLI not found", "mcpmate"),
        body: interpolate(
          /* translators: %s: the wp-config.php constant, MCPMATE_CLI_PATH. */
          __(
            "%s must be the full path of an executable wp (or of wp-cli.phar with MCPMATE_PHP_BINARY), and PHP must allow proc_open.",
            "mcpmate",
          ),
          [
            <bdi dir="ltr" className="el-mono" key="constant">
              MCPMATE_CLI_PATH
            </bdi>,
          ],
        ),
        action: null,
      };
    case "not_configured":
      return {
        badge: __("Needs an Unsplash key", "mcpmate"),
        body: __(
          "Add your own Unsplash access key to let AI apps search free stock photos. Uploading one still needs Media uploads.",
          "mcpmate",
        ),
        action: {
          label: __("Get a key from Unsplash", "mcpmate"),
          href: "https://unsplash.com/developers",
        },
      };
    case "wordpress_too_old":
      return {
        badge: __("Needs WordPress 6.9 or later", "mcpmate"),
        body: __("Update WordPress to use these tools.", "mcpmate"),
        action: {
          label: __("Update WordPress", "mcpmate"),
          href: adminUrl("update-core.php"),
        },
      };
    default:
      return {
        badge: __("Not installed", "mcpmate"),
        body: sprintf(
          /* translators: %s: plugin name, e.g. Elementor. */
          __("Install %s to use these tools.", "mcpmate"),
          group.label,
        ),
        action: null,
      };
  }
}

/** "3 of 11 on", or why the site can't run the plugin's tools. */
export function tileStatus(tile: PluginTile): string {
  switch (tile.status) {
    case "missing":
      return tile.theme
        ? __("Theme not installed", "mcpmate")
        : __("Not installed", "mcpmate");
    case "installed":
      return tile.theme
        ? __("Theme not active", "mcpmate")
        : __("Installed, not active", "mcpmate");
    case "not_configured":
      return __("Needs an Unsplash key", "mcpmate");
    default:
      return onCount(tile.on, tile.total);
  }
}

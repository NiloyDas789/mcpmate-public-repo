import { CheckCircleOutlined } from "@ant-design/icons";
import { __, _n, sprintf } from "@wordpress/i18n";
import Badge from "@components/ui/Badge";
import Button from "@components/ui/Button";
import Notice from "@components/ui/Notice";
import { requestErrorText } from "@utils/errors";
import { kindCounts, type ToolSection } from "./logic";
import StockPhotosKey from "./StockPhotosKey";
import { unavailableReason } from "./toolText";
import type { SaveState } from "./useToolSaves";

/** Parts of the Tools page that its list and the plugin modal share. */

/**
 * A group's counts: "3 of 5 on" in the text colour, then "2 read, 3 write".
 * Set apart by space on screen; a screen reader hears one phrase, joined
 * by a comma ("3 of 5 on, 2 read, 3 write").
 */
export function GroupCounts({ on, kinds }: { on: string; kinds: string }) {
  return (
    <>
      <span className="sr-only">{`${on}${__(", ", "mcpmate")}${kinds}`}</span>
      <span className="el-group-counts" aria-hidden="true">
        <span className="el-group-counts-on">{on}</span>
        <span className="el-group-counts-kinds">{kinds}</span>
      </span>
    </>
  );
}

/** Greyed, no checkboxes, the reason as text (docs/ui.md §5.3). */
export function UnavailableGroup({ section }: { section: ToolSection }) {
  const reason = unavailableReason(section.group);
  const total = section.rows.length;

  return (
    <div className="el-checkbox-group el-tools-unavailable">
      <p className="el-tools-unavailable-head">
        <span className="el-checkbox-group-name">{section.label}</span>
        <Badge variant="unavailable">{reason.badge}</Badge>
        <span className="el-checkbox-group-counts">
          <GroupCounts
            on={sprintf(
              /* translators: %d: number of tools in a group the site cannot run. */
              _n("%d tool", "%d tools", total, "mcpmate"),
              total,
            )}
            kinds={kindCounts(section.rows)}
          />
        </span>
      </p>
      <p className="el-tools-unavailable-body">
        {reason.body}
        {reason.action && (
          <>
            {" "}
            <a href={reason.action.href}>{reason.action.label}</a>
          </>
        )}
      </p>
      {section.group.unavailable_reason === "not_configured" &&
        section.group.id === "stock_photos" && (
          <StockPhotosKey configured={false} appName="" />
        )}
    </div>
  );
}

// ── Save status ─────────────────────────────────

/**
 * Under the list while a save runs or just ended: "Saving…", "Saved." for
 * five seconds, or the error with Retry until the change goes through.
 */
export function SaveStatus({
  state,
  onRetry,
}: {
  state: SaveState;
  onRetry: () => void;
}) {
  if (state.status === "idle") {
    return null;
  }

  if (state.status === "failed") {
    return (
      <div className="el-tools-save-status">
        <Notice
          tone="danger"
          live
          title={__("Couldn't save your change.", "mcpmate")}
          action={
            <Button variant="secondary" size="sm" onClick={onRetry}>
              {__("Retry", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(state.error)}{" "}
          {__("The tool is shown as it was before your change.", "mcpmate")}
        </Notice>
      </div>
    );
  }

  return (
    <div className="el-tools-save-status">
      <p className="el-tools-save-text">
        {state.status === "saving" ? (
          __("Saving…", "mcpmate")
        ) : (
          <>
            <CheckCircleOutlined aria-hidden className="el-tools-saved-icon" />{" "}
            {__(
              "Saved. Connected AI apps may need to reconnect to see the change.",
              "mcpmate",
            )}
          </>
        )}
      </p>
    </div>
  );
}

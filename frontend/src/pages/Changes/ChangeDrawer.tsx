import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import DiffView from "@components/ui/DiffView";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { fetchChange } from "@services/api";
import { CHANGES_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import type { ChangeDetail } from "../../types/api";
import { fullDateTime } from "../Activity/logic";
import { actionLabel, changedBy, diffFields, objectText } from "./logic";

function Body({ change }: { change: ChangeDetail }) {
  const tool = change.mcp_tool || change.tool;

  return (
    <>
      <p className="el-drawer-meta">
        {[
          actionLabel(change.action),
          tool,
          fullDateTime(change.created_at),
          changedBy(change),
        ]
          .filter(Boolean)
          .join(" · ")}
      </p>
      {change.truncated && (
        <Notice
          tone="info"
          title={__(
            "This snapshot was too large to keep in full (over 512 KB). The first 512 KB are shown.",
            "mcpmate",
          )}
        />
      )}
      {change.diff.fields.length === 0 ? (
        <p>{__("McpMate kept no fields for this change.", "mcpmate")}</p>
      ) : (
        <DiffView fields={diffFields(change)} />
      )}
      {change.audit_id > 0 && (
        <p className="el-drawer-line">
          <Link to={`/activity?entry=${change.audit_id}`}>
            {__("Originating call", "mcpmate")}
          </Link>
        </p>
      )}
    </>
  );
}

/**
 * Before and after (`?change=<id>`; docs/ui.md §5.4.2): the server's diff,
 * field by field, side by side from 960 px, with a link to the call that
 * made the change. 720 px wide, full screen on small screens.
 */
export default function ChangeDrawer({
  changeId,
  onClose,
}: {
  changeId: number | null;
  onClose: () => void;
}) {
  const change = useQuery({
    queryKey: [...CHANGES_QUERY_KEY, "show", changeId],
    queryFn: () => fetchChange(changeId as number),
    enabled: changeId !== null,
    staleTime: 0,
  });

  const body = () => {
    if (change.data) {
      return <Body change={change.data} />;
    }
    if (change.isError) {
      return (
        <Notice
          tone="danger"
          title={__("Couldn't load this change.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void change.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(change.error)}
        </Notice>
      );
    }

    return (
      <div aria-busy="true">
        <Skeleton count={8} />
      </div>
    );
  };

  return (
    <Drawer
      open={changeId !== null}
      onClose={onClose}
      size="lg"
      title={
        change.data
          ? sprintf(
              /* translators: %s: what changed, e.g. Page "Home" (#41). */
              __("Before and after: %s", "mcpmate"),
              objectText(change.data),
            )
          : __("Before and after", "mcpmate")
      }
    >
      {body()}
    </Drawer>
  );
}

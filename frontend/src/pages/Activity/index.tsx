import { __, _n, sprintf } from "@wordpress/i18n";
import { Outlet, useLocation } from "react-router";
import PageHeader from "@components/AppShell/PageHeader";
import Tabs from "@components/ui/Tabs";
import { usePendingApprovalCount } from "@services/queries";
import { formatNumber } from "@utils/format";

/**
 * Activity (`#/activity`, `/changes`, `/approvals`, `/approvals/:id`,
 * and with MCPMate Pro `/block-queue`, `/block-queue/:id`; docs/ui.md
 * §5.4): the audit log, the change history, the approval requests and the
 * block editor queue, each tab a route.
 */
export default function Activity() {
  const { pathname } = useLocation();
  const activeKey = pathname.startsWith("/activity/approvals")
    ? "approvals"
    : pathname.startsWith("/activity/changes")
      ? "changes"
      : pathname.startsWith("/activity/block-queue")
        ? "block-queue"
        : "audit";
  const pending = usePendingApprovalCount();

  return (
    <>
      <PageHeader
        title={__("Activity", "mcpmate")}
        intro={__(
          "Everything your AI apps did on this site, and what each change looked like before and after.",
          "mcpmate",
        )}
      />
      <Tabs
        label={__("Activity", "mcpmate")}
        activeKey={activeKey}
        items={[
          {
            key: "audit",
            label: __("Audit log", "mcpmate"),
            to: "/activity",
          },
          {
            key: "changes",
            label: __("Change history", "mcpmate"),
            to: "/activity/changes",
          },
          {
            key: "approvals",
            label: __("Approvals", "mcpmate"),
            to: "/activity/approvals",
            ...(pending > 0 && {
              count: formatNumber(pending),
              countLabel: sprintf(
                /* translators: %d: number of approval requests waiting. */
                _n("%d waiting", "%d waiting", pending, "mcpmate"),
                pending,
              ),
            }),
          },
          // MCPMate Pro's block editor queue.
          ...(SERVER_VARIABLES.pro?.active
            ? [
                {
                  key: "block-queue",
                  label: __("Block editor queue", "mcpmate"),
                  to: "/activity/block-queue",
                },
              ]
            : []),
        ]}
      >
        <Outlet />
      </Tabs>
    </>
  );
}

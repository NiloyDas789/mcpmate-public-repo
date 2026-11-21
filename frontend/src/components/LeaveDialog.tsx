import { __, _n, sprintf } from "@wordpress/i18n";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import { formatNumber } from "@utils/format";
import { useLeaveGuard } from "@/hooks/useLeaveGuard";

/**
 * "Leave without saving?" (docs/ui.md §5.5): shown when a page with
 * `count` unsaved changes is left for another page of the app. It opens on
 * Stay on this page, so a stray Enter keeps the changes.
 */
export default function LeaveDialog({ count }: { count: number }) {
  const guard = useLeaveGuard(count > 0);

  return (
    <ConfirmDialog
      open={guard.blocked}
      tone="danger"
      title={__("Leave without saving?", "mcpmate")}
      body={
        <p>
          {sprintf(
            /* translators: %s: number of settings changed and not saved. */
            _n(
              "You changed %s setting. If you leave now, that change is lost.",
              "You changed %s settings. If you leave now, those changes are lost.",
              count,
              "mcpmate",
            ),
            formatNumber(count),
          )}
        </p>
      }
      confirmLabel={__("Leave without saving", "mcpmate")}
      cancelLabel={__("Stay on this page", "mcpmate")}
      onConfirm={guard.proceed}
      onClose={guard.stay}
    />
  );
}

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import Notice from "@components/ui/Notice";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  fetchPreferences,
  previewAuditPurge,
  purgeAuditLog,
} from "@services/api";
import {
  AUDIT_LOG_QUERY_KEY,
  PREFERENCES_QUERY_KEY,
  STATS_QUERY_KEY,
} from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import { interpolate } from "@utils/i18n";

const daysText = (days: number) =>
  sprintf(
    /* translators: %s: number of days. */
    _n("%s day", "%s days", days, "mcpmate"),
    formatNumber(days),
  );

/**
 * How long entries are kept, and "Remove entries older than 90 days now"
 * (docs/ui.md §5.4.1, D6, B9): it runs the retention purge early, after a
 * dialog with the count. Nothing else can remove entries.
 */
export default function PurgeControl() {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const preferences = useQuery({
    queryKey: PREFERENCES_QUERY_KEY,
    queryFn: fetchPreferences,
  });
  const [checking, setChecking] = useState(false);
  const [preview, setPreview] = useState<{
    count: number;
    days: number;
  } | null>(null);
  const [outcome, setOutcome] = useState<
    | { tone: "info" | "success"; text: string }
    | { tone: "danger"; text: string }
    | null
  >(null);

  const fixed = preferences.data?.fixed.audit_retention_days;
  const days =
    fixed?.reason === "filtered"
      ? Number(fixed.value)
      : preferences.data?.preferences.audit_retention_days;

  if (days !== undefined && days < 1) {
    return (
      <p className="el-purge-line">
        {__("Entries are kept until they are removed in code.", "mcpmate")}
      </p>
    );
  }

  const check = async () => {
    setChecking(true);
    setOutcome(null);
    try {
      const found = await previewAuditPurge();
      if (found.count === 0) {
        const text = sprintf(
          /* translators: %s: "90 days". */
          __("Nothing to remove: every entry is newer than %s.", "mcpmate"),
          daysText(found.days),
        );
        setOutcome({ tone: "info", text });
      } else {
        setPreview({ count: found.count, days: found.days });
      }
    } catch (error) {
      setOutcome({ tone: "danger", text: requestErrorText(error) });
    } finally {
      setChecking(false);
    }
  };

  const purge = async () => {
    const done = await purgeAuditLog();
    const text = sprintf(
      /* translators: %s: number of entries. */
      _n("Removed %s entry.", "Removed %s entries.", done.removed, "mcpmate"),
      formatNumber(done.removed),
    );
    setOutcome({ tone: "success", text });
    announce(text);
    void queryClient.invalidateQueries({ queryKey: AUDIT_LOG_QUERY_KEY });
    void queryClient.invalidateQueries({ queryKey: STATS_QUERY_KEY });
  };

  return (
    <div className="el-purge">
      {days !== undefined && (
        <p className="el-purge-line">
          {interpolate(
            /* translators: 1: "90 days", 2: a link to Settings. */
            __(
              "Entries are kept for %1$s, then removed automatically. %2$s",
              "mcpmate",
            ),
            [
              daysText(days),
              <Link key="settings" to="/settings?section=logging">
                {__("Change this in Settings.", "mcpmate")}
              </Link>,
            ],
          )}
        </p>
      )}
      <Button
        variant="danger-secondary"
        loading={checking}
        onClick={() => void check()}
      >
        {days !== undefined
          ? sprintf(
              /* translators: %s: "90 days". */
              __("Remove entries older than %s now", "mcpmate"),
              daysText(days),
            )
          : __("Remove old entries now", "mcpmate")}
      </Button>
      {outcome && (
        <Notice
          tone={outcome.tone}
          title={
            outcome.tone === "danger"
              ? __("Couldn't check for old entries.", "mcpmate")
              : outcome.text
          }
          live
        >
          {outcome.tone === "danger" ? outcome.text : undefined}
        </Notice>
      )}
      <ConfirmDialog
        open={preview !== null}
        tone="danger"
        title={__("Remove old entries now?", "mcpmate")}
        body={
          preview && (
            <p>
              {sprintf(
                /* translators: 1: number of entries, 2: "90 days", 3: "90 days". */
                _n(
                  "This removes %1$s entry older than %2$s from this site. It can't be recovered. Entries from the last %3$s stay.",
                  "This removes %1$s entries older than %2$s from this site. They can't be recovered. Entries from the last %3$s stay.",
                  preview.count,
                  "mcpmate",
                ),
                formatNumber(preview.count),
                daysText(preview.days),
                daysText(preview.days),
              )}
            </p>
          )
        }
        confirmLabel={
          preview
            ? sprintf(
                /* translators: %s: number of entries. */
                _n(
                  "Remove %s entry",
                  "Remove %s entries",
                  preview.count,
                  "mcpmate",
                ),
                formatNumber(preview.count),
              )
            : ""
        }
        busyLabel={__("Removing…", "mcpmate")}
        cancelLabel={__("Keep entries", "mcpmate")}
        onConfirm={purge}
        onClose={() => setPreview(null)}
      />
    </div>
  );
}

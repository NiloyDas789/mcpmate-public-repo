import { useEffect, useId, useRef, useState } from "react";
import { Checkbox } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";

import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import { fetchGhostMode, updateGhostMode } from "@services/api";
import type { GhostModeView } from "../../types/api";
import { requestErrorText } from "@utils/errors";
import { formatDate } from "@utils/format";

const QUERY_KEY = ["ghost-mode"];

/**
 * The saved choice, without IDs this card can't show as checkable: users who
 * are no longer administrators here (deleted, demoted), super admins and you.
 * Those are never sent back either.
 */
function savedChoice(view: GhostModeView): number[] {
  return view.users
    .filter((id) =>
      view.admins.some(
        (admin) => admin.id === id && admin.hideable && !admin.self,
      ),
    )
    .sort((a, b) => a - b);
}

const keyOf = (ids: number[]) => [...ids].sort((a, b) => a - b).join(",");

/**
 * Settings → Ghost Mode (MCPMate Pro, AGENTS.md rule 10), shown only while
 * MCPMATE_ENABLE_GHOST_MODE is set: the administrators MCPMate's menu and
 * notices are hidden from. You can never pick yourself, and Site Health
 * always lists it.
 */
export default function GhostModeCard({
  onDirtyChange,
}: {
  /** How many unsaved changes the card holds (0 or 1), for the leave warning. */
  onDirtyChange?: (count: number) => void;
} = {}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const hintId = useId();
  const ghost = useQuery({ queryKey: QUERY_KEY, queryFn: fetchGhostMode });
  const [chosen, setChosen] = useState<number[]>([]);
  const loaded = useRef(false);
  const savedKey = ghost.data ? keyOf(savedChoice(ghost.data)) : "";

  // Taken from the server once: a refetch (window focus) never overwrites
  // unsaved edits; a save sets it again below.
  useEffect(() => {
    if (ghost.data && !loaded.current) {
      loaded.current = true;
      setChosen(savedChoice(ghost.data));
    }
  }, [ghost.data]);

  const save = useMutation({
    mutationFn: () => updateGhostMode(chosen),
    onSuccess: (view) => {
      queryClient.setQueryData(QUERY_KEY, view);
      setChosen(savedChoice(view));
      announce(
        view.users.length === 0
          ? __("Ghost Mode is off.", "mcpmate")
          : __("Ghost Mode saved.", "mcpmate"),
      );
    },
  });

  const dirty = ghost.data !== undefined && keyOf(chosen) !== savedKey;

  useEffect(() => {
    onDirtyChange?.(dirty ? 1 : 0);
  }, [dirty, onDirtyChange]);

  let content;

  if (ghost.isPending) {
    content = <Skeleton count={3} />;
  } else if (ghost.isError) {
    content = (
      <Notice
        tone="danger"
        title={__("Ghost Mode could not be loaded.", "mcpmate")}
        action={
          <Button size="sm" onClick={() => void ghost.refetch()}>
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(ghost.error)}
      </Notice>
    );
  } else {
    const view = ghost.data;
    const toggle = (id: number, on: boolean) =>
      setChosen((current) =>
        on ? [...current, id] : current.filter((each) => each !== id),
      );

    content = (
      <div className="el-stack">
        <p className="m-0" id={hintId}>
          {__(
            "Hide MCPMate's menu and notices from the administrators you pick. AI apps keep working, every call is still audited, the page stays reachable by its address, MCPMate stays on the Plugins screen, and Site Health tells every administrator that Ghost Mode is on.",
            "mcpmate",
          )}
        </p>
        {view.users.length > 0 && view.by && view.at && (
          <Notice
            tone="info"
            title={sprintf(
              /* translators: 1: number of administrators, 2: admin's name, 3: date. */
              __(
                "Hidden from %1$d administrator(s) by %2$s since %3$s.",
                "mcpmate",
              ),
              view.users.length,
              view.by.name,
              formatDate(view.at, { dateStyle: "long" }),
            )}
          />
        )}
        <fieldset
          className="el-stack-tight m-0 border-0 p-0"
          aria-describedby={hintId}
        >
          <legend className="mb-2 font-semibold">
            {__("Hide MCPMate from", "mcpmate")}
          </legend>
          {view.admins.map((admin) => (
            <Checkbox
              key={admin.id}
              checked={chosen.includes(admin.id)}
              // Locked while a save is on its way, so its answer never overwrites a newer edit.
              disabled={!admin.hideable || save.isPending}
              onChange={(event) => toggle(admin.id, event.target.checked)}
            >
              {admin.name}{" "}
              <span className="el-setting-hint">
                {admin.self
                  ? __("(you: never hidden)", "mcpmate")
                  : admin.hideable
                    ? admin.login
                    : __("(super admin: never hidden)", "mcpmate")}
              </span>
            </Checkbox>
          ))}
        </fieldset>
        {save.isError && (
          <Notice tone="danger" title={__("Not saved.", "mcpmate")}>
            {requestErrorText(save.error)}
          </Notice>
        )}
        <div>
          <Button
            variant="primary"
            disabled={!dirty}
            loading={save.isPending}
            onClick={() => save.mutate()}
          >
            {__("Save Ghost Mode", "mcpmate")}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <Card title={__("Ghost Mode", "mcpmate")} busy={ghost.isPending}>
      {content}
    </Card>
  );
}

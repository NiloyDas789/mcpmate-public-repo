import { useId, useMemo, useState } from "react";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import FilterBar from "@components/ui/FilterBar";
import Notice from "@components/ui/Notice";
import SegmentedControl from "@components/ui/SegmentedControl";
import Skeleton from "@components/ui/Skeleton";
import Table, { type TableColumn } from "@components/ui/Table";
import { useAnnounce } from "@/hooks/useAnnounce";
import { deleteSkill, fetchSkills, moveSkill } from "@services/api";
import { SKILLS_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type {
  SavedSkill,
  SkillsPage,
  SkillStatus,
  SystemSkill,
} from "../../types/api";
import { shortDateTime, siteTimeZone, withParams } from "../Activity/logic";
import { useHashQuery } from "../Activity/useHashQuery";
import SkillDrawer from "./SkillDrawer";
import {
  matchPlaybooks,
  playbookCountText,
  playbookFindFromQuery,
  skillViewFromQuery,
} from "./skillLogic";

const FALLBACK_LIMITS: SkillsPage["limits"] = {
  description_chars: 200,
  body_bytes: 16000,
};

/**
 * Tools → Skills (`#/tools/skills`; docs/pro-parity.md P3, AGENTS.md known
 * gap 37): the skills AI apps saved for this site's users, to view, edit,
 * trash, restore and (from the trash) delete for good, and MCPMate's
 * playbooks, read-only. The status and the open skill live in the hash query.
 */
export default function SkillsPanel() {
  const { search: query, current, go, closeDrawer } = useHashQuery();
  const view = useMemo(() => skillViewFromQuery(query), [query]);
  const find = playbookFindFromQuery(query);
  const playbooksHeadingId = useId();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [deleting, setDeleting] = useState<SavedSkill | null>(null);

  const list = useQuery({
    queryKey: [...SKILLS_QUERY_KEY, "list", view.status],
    queryFn: () => fetchSkills(view.status),
    placeholderData: keepPreviousData,
    staleTime: 0,
  });
  const data = list.data;
  const trash = view.status === "trash";

  const refresh = () =>
    void queryClient.invalidateQueries({ queryKey: SKILLS_QUERY_KEY });

  const restore = useMutation({
    mutationFn: (skill: SavedSkill) => moveSkill(skill.id, "restore"),
    onSuccess: () => {
      refresh();
      announce(__("Skill restored.", "mcpmate"));
    },
  });
  const remove = useMutation({
    mutationFn: (skill: SavedSkill) => deleteSkill(skill.id),
    onSuccess: () => {
      if (view.skill) {
        closeDrawer("skill");
      }
      refresh();
      announce(__("Skill deleted for good.", "mcpmate"));
    },
  });

  const setStatus = (status: SkillStatus) =>
    go(withParams(current(), { status: status === "trash" ? "trash" : null }));
  const openSaved = (skill: SavedSkill) =>
    go(withParams(current(), { skill: String(skill.id), playbook: null }), {
      push: true,
    });
  const openPlaybook = (skill: SystemSkill) =>
    go(withParams(current(), { playbook: skill.name, skill: null }), {
      push: true,
    });
  const close = () => closeDrawer(view.skill ? "skill" : "playbook");

  const owner = (skill: SavedSkill) =>
    skill.user.name ??
    skill.user.login ??
    sprintf(
      /* translators: %d: user ID. */ __("User #%d", "mcpmate"),
      skill.user.id,
    );

  const savedColumns: TableColumn<SavedSkill>[] = [
    {
      key: "name",
      title: __("Skill", "mcpmate"),
      rowHeader: true,
      render: (row) => (
        <span className="el-stack-tight">
          <Button variant="link" size="sm" onClick={() => openSaved(row)}>
            <bdi dir="ltr" className="el-skill-name">
              {row.name}
            </bdi>
          </Button>
          <span className="el-setting-hint">{row.description}</span>
        </span>
      ),
    },
    { key: "user", title: __("User", "mcpmate"), render: owner },
    {
      key: "scope",
      title: __("For", "mcpmate"),
      render: (row) =>
        row.this_site_only
          ? __("This site", "mcpmate")
          : __("All sites", "mcpmate"),
    },
    {
      key: "date",
      title: trash ? __("Trashed", "mcpmate") : __("Last changed", "mcpmate"),
      render: (row) => {
        const at = (trash ? row.trashed_at : row.updated_at) ?? row.updated_at;
        return (
          <time dateTime={at} className="whitespace-nowrap">
            {shortDateTime(at)}
          </time>
        );
      },
    },
    {
      key: "actions",
      title: __("Actions", "mcpmate"),
      hiddenTitle: true,
      render: (row) =>
        trash ? (
          <span className="el-row-actions">
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: skill name. */ __("Restore: %s", "mcpmate"),
                row.name,
              )}
              onClick={() => restore.mutate(row)}
            >
              {__("Restore", "mcpmate")}
            </Button>
            <Button
              variant="link"
              size="sm"
              label={sprintf(
                /* translators: %s: skill name. */ __(
                  "Delete permanently: %s",
                  "mcpmate",
                ),
                row.name,
              )}
              onClick={() => setDeleting(row)}
            >
              {__("Delete permanently", "mcpmate")}
            </Button>
          </span>
        ) : (
          <Button
            variant="link"
            size="sm"
            label={sprintf(
              /* translators: %s: skill name. */ __("Edit: %s", "mcpmate"),
              row.name,
            )}
            onClick={() => openSaved(row)}
          >
            {__("Edit", "mcpmate")}
          </Button>
        ),
    },
  ];

  const allPlaybooks = data?.system ?? [];
  const playbooks = matchPlaybooks(allPlaybooks, find);
  const filtered = find.trim() !== "";

  const setFind = (value: string) => {
    go(withParams(current(), { find: value.trim() ? value : null }));
    if (data) {
      announce(
        playbookCountText(
          matchPlaybooks(allPlaybooks, value).length,
          value.trim() !== "",
        ),
      );
    }
  };

  const error = (
    <Notice
      tone="danger"
      title={__("Couldn't load the skills.", "mcpmate")}
      action={
        <Button
          variant="secondary"
          size="sm"
          onClick={() => void list.refetch()}
        >
          {__("Try again", "mcpmate")}
        </Button>
      }
    >
      {requestErrorText(list.error)}
    </Notice>
  );

  return (
    <div className="el-activity el-skills">
      <p className="el-tools-intro">
        {__(
          "Skills are conventions your AI apps remember for a WordPress user, such as a brand's colours or how pages are laid out. MCPMate's own playbooks are listed apart from them; they can't be changed.",
          "mcpmate",
        )}
      </p>
      <div className="el-skills-layout">
        <div className="el-skills-saved">
          <div className="el-skills-head">
            <h2 className="el-section-title">
              {__("Saved skills", "mcpmate")}
            </h2>
            <SegmentedControl<SkillStatus>
              label={__("Show", "mcpmate")}
              value={view.status}
              onChange={setStatus}
              options={[
                {
                  value: "live",
                  label: sprintf(
                    /* translators: %s: number of skills. */
                    __("Saved (%s)", "mcpmate"),
                    formatNumber(data?.counts.live ?? 0),
                  ),
                },
                {
                  value: "trash",
                  label: sprintf(
                    /* translators: %s: number of skills. */
                    __("Trash (%s)", "mcpmate"),
                    formatNumber(data?.counts.trash ?? 0),
                  ),
                },
              ]}
            />
          </div>
          {trash && data && (
            <p className="el-filter-scope">
              {sprintf(
                /* translators: %s: "30 days". */
                __(
                  "Skills in the trash are deleted for good after %s.",
                  "mcpmate",
                ),
                sprintf(
                  /* translators: %s: number of days. */
                  _n("%s day", "%s days", data.trash_days, "mcpmate"),
                  formatNumber(data.trash_days),
                ),
              )}
            </p>
          )}
          {restore.error && (
            <Notice
              tone="danger"
              title={__("Couldn't restore the skill.", "mcpmate")}
              live
            >
              {requestErrorText(restore.error)}
            </Notice>
          )}
          <Table<SavedSkill>
            caption={sprintf(
              /* translators: %s: the site's timezone, e.g. Asia/Dhaka. */
              trash
                ? __(
                    "Skills in the trash, newest first. Times in %s.",
                    "mcpmate",
                  )
                : __(
                    "Saved skills, last changed first. Times in %s.",
                    "mcpmate",
                  ),
              siteTimeZone(),
            )}
            columns={savedColumns}
            rows={data?.skills ?? []}
            rowKey={(row) => String(row.id)}
            loading={list.isPending}
            loadingRows={3}
            empty={
              trash ? (
                <EmptyState title={__("The trash is empty.", "mcpmate")} />
              ) : (
                <EmptyState
                  title={__("No saved skills yet.", "mcpmate")}
                  body={__(
                    "When you ask an AI app to remember how you like things done, it saves a skill here with save_skill.",
                    "mcpmate",
                  )}
                />
              )
            }
            error={list.isError && error}
          />
        </div>
        {/* MCPMate's own, read-only: a catalog in columns, with a filter. */}
        <div className="el-skills-playbooks">
          <div className="el-skills-head">
            <div className="el-skills-title">
              <h2 id={playbooksHeadingId} className="el-section-title">
                {__("MCPMate playbooks", "mcpmate")}
              </h2>
              {data && (
                <p className="el-skills-count">
                  {playbookCountText(playbooks.length, filtered)}
                </p>
              )}
              <p className="el-skills-note">
                {__(
                  "An AI app is offered one while the tools it needs are on.",
                  "mcpmate",
                )}
              </p>
            </div>
            <FilterBar
              layout="wide"
              fields={[
                {
                  key: "find",
                  type: "search",
                  label: __("Filter playbooks", "mcpmate"),
                },
              ]}
              values={{ find }}
              onChange={(values) => setFind(values.find ?? "")}
            />
          </div>
          {list.isPending ? (
            <Skeleton shape="text" count={3} announce={false} />
          ) : list.isError ? (
            error
          ) : playbooks.length === 0 ? (
            filtered ? (
              <EmptyState
                title={sprintf(
                  /* translators: %s: what the playbooks were filtered by. */
                  __("No playbooks match “%s”.", "mcpmate"),
                  find.trim(),
                )}
                action={{
                  label: __("Clear filter", "mcpmate"),
                  onClick: () => setFind(""),
                }}
              />
            ) : (
              <EmptyState title={__("No playbooks.", "mcpmate")} />
            )
          ) : (
            <ul
              className="el-playbook-grid"
              aria-labelledby={playbooksHeadingId}
            >
              {playbooks.map((row) => (
                <li key={row.name} className="el-playbook">
                  <Button
                    variant="link"
                    size="sm"
                    onClick={() => openPlaybook(row)}
                  >
                    <bdi dir="ltr" className="el-skill-name">
                      {row.name}
                    </bdi>
                  </Button>
                  <p className="el-playbook-what">{row.description}</p>
                  {row.tools.length > 0 && (
                    <p className="el-playbook-needs">
                      <span className="el-playbook-needs-label">
                        {__("Needs", "mcpmate")}
                      </span>{" "}
                      <bdi dir="ltr" className="el-mono">
                        {row.tools.join(", ")}
                      </bdi>
                    </p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
      <SkillDrawer
        which={
          view.skill
            ? { id: view.skill }
            : view.playbook
              ? { name: view.playbook }
              : null
        }
        limits={data?.limits ?? FALLBACK_LIMITS}
        onClose={close}
        onDelete={setDeleting}
      />
      <ConfirmDialog
        open={deleting !== null}
        tone="danger"
        title={__("Delete this skill for good?", "mcpmate")}
        body={
          <p>
            {sprintf(
              /* translators: %s: skill name. */
              __("“%s” will be deleted. This can't be undone.", "mcpmate"),
              deleting?.name ?? "",
            )}
          </p>
        }
        confirmLabel={__("Delete permanently", "mcpmate")}
        cancelLabel={__("Keep it", "mcpmate")}
        onConfirm={async () => {
          if (deleting) {
            await remove.mutateAsync(deleting);
          }
        }}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}

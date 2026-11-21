import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import CopyButton from "@components/ui/CopyButton";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  AjaxRequestError,
  fetchSkill,
  moveSkill,
  saveSkillEdit,
} from "@services/api";
import { SKILLS_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { SavedSkill, SkillsPage, SystemSkill } from "../../types/api";
import { fullDateTime } from "../Activity/logic";
import { contentBytes } from "./memoryLogic";
import {
  skillErrors,
  type SkillDraft as Draft,
  type SkillErrors as Errors,
} from "./skillLogic";

type Which = { id: number } | { name: string } | null;

const isSaved = (skill: SavedSkill | SystemSkill): skill is SavedSkill =>
  "id" in skill;

/**
 * One skill in a drawer: a playbook (`?playbook=<name>`) is shown as text,
 * read-only; a saved skill (`?skill=<id>`) can be edited or moved to the
 * trash, and a trashed one restored or deleted for good (the parent asks
 * first). Skill text is shown as text, never as HTML.
 */
export default function SkillDrawer({
  which,
  limits,
  onClose,
  onDelete,
}: {
  which: Which;
  limits: SkillsPage["limits"];
  onClose: () => void;
  /** Ask, then delete this trashed skill for good. */
  onDelete: (skill: SavedSkill) => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const skill = useQuery({
    queryKey: [...SKILLS_QUERY_KEY, "show", which],
    queryFn: () => fetchSkill(which as { id: number } | { name: string }),
    enabled: which !== null,
    staleTime: 0,
  });
  const [draft, setDraft] = useState<Draft | null>(null);
  const [serverErrors, setServerErrors] = useState<Errors>({});
  const [checked, setChecked] = useState(false);
  const data = skill.data;

  useEffect(() => {
    setDraft(
      data && isSaved(data)
        ? { description: data.description, body: data.body ?? "" }
        : null,
    );
    setServerErrors({});
    setChecked(false);
  }, [data]);

  const changed = (next: SavedSkill, message: string) => {
    queryClient.setQueryData([...SKILLS_QUERY_KEY, "show", which], next);
    void queryClient.invalidateQueries({
      queryKey: [...SKILLS_QUERY_KEY, "list"],
    });
    announce(message);
  };

  const save = useMutation({
    mutationFn: (values: Draft) =>
      saveSkillEdit((data as SavedSkill).id, values.description, values.body),
    onSuccess: (next) => changed(next, __("Skill saved.", "mcpmate")),
    onError: (error) =>
      setServerErrors(
        error instanceof AjaxRequestError
          ? ((error.details?.errors as Errors | undefined) ?? {})
          : {},
      ),
  });
  const move = useMutation({
    mutationFn: (to: "trash" | "restore") =>
      moveSkill((data as SavedSkill).id, to),
    onSuccess: (next, to) =>
      changed(
        next,
        to === "trash"
          ? __("Skill moved to the trash.", "mcpmate")
          : __("Skill restored.", "mcpmate"),
      ),
  });

  const errors: Errors = {
    ...serverErrors,
    ...(draft && checked ? skillErrors(draft, limits) : {}),
  };
  const saved = data && isSaved(data) ? data : null;
  const trashed = Boolean(saved?.trashed_at);
  const dirty =
    saved && draft
      ? draft.description !== saved.description || draft.body !== saved.body
      : false;

  const edit = (key: keyof Draft, value: string) => {
    setDraft((current) => (current ? { ...current, [key]: value } : current));
    setServerErrors((current) => ({ ...current, [key]: undefined }));
  };

  const submit = () => {
    if (!draft) {
      return;
    }
    setChecked(true);
    const invalid = skillErrors(draft, limits);
    const first = (["description", "body"] as const).find(
      (key) => invalid[key],
    );
    if (first) {
      document.getElementById(`el-skill-${first}`)?.focus();
      return;
    }
    save.mutate(draft);
  };

  const field = (key: keyof Draft) => ({
    id: `el-skill-${key}`,
    "aria-invalid": errors[key] ? true : undefined,
    "aria-describedby":
      [`el-skill-${key}-hint`, errors[key] && `el-skill-${key}-error`]
        .filter(Boolean)
        .join(" ") || undefined,
  });

  const fieldError = (key: keyof Draft) =>
    errors[key] ? (
      <p id={`el-skill-${key}-error`} className="el-field-error">
        {errors[key]}
      </p>
    ) : null;

  const footer = saved && (
    <div className="el-dialog-actions">
      {trashed ? (
        <>
          <Button
            variant="primary"
            loading={move.isPending}
            onClick={() => move.mutate("restore")}
          >
            {__("Restore", "mcpmate")}
          </Button>
          <Button variant="danger-secondary" onClick={() => onDelete(saved)}>
            {__("Delete permanently", "mcpmate")}
          </Button>
        </>
      ) : (
        <>
          <Button
            variant="primary"
            loading={save.isPending}
            disabled={!dirty}
            onClick={submit}
          >
            {__("Save changes", "mcpmate")}
          </Button>
          <Button
            variant="danger-secondary"
            loading={move.isPending}
            onClick={() => move.mutate("trash")}
          >
            {__("Move to trash", "mcpmate")}
          </Button>
        </>
      )}
    </div>
  );

  // The text as shown, with a Copy button over it (a playbook's prompt, or a trashed skill).
  const textRef = useRef<HTMLPreElement>(null);
  const readOnlyBody = (text: string, copyName: string) => (
    <div className="el-skill-text">
      <div className="el-skill-text-head">
        <CopyButton value={text} label={copyName} target={textRef} />
      </div>
      <pre
        ref={textRef}
        className="el-json el-preview-text"
        dir="auto"
        tabIndex={0}
        role="region"
        aria-label={__("Skill text", "mcpmate")}
      >
        {text}
      </pre>
    </div>
  );

  let body;

  if (skill.isPending) {
    body = (
      <div aria-busy="true">
        <Skeleton count={4} />
      </div>
    );
  } else if (skill.isError || !data) {
    body = (
      <Notice
        tone="danger"
        title={__("Couldn't load this skill.", "mcpmate")}
        action={
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void skill.refetch()}
          >
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(skill.error)}
      </Notice>
    );
  } else if (!saved) {
    const system = data as SystemSkill;
    body = (
      <div className="el-stack">
        <p className="el-setting-hint">{system.description}</p>
        {system.tools.length > 0 && (
          <p className="el-playbook-needs">
            <span className="el-playbook-needs-label">
              {__("Needs", "mcpmate")}
            </span>{" "}
            <bdi dir="ltr" className="el-mono">
              {system.tools.join(", ")}
            </bdi>
          </p>
        )}
        <Notice
          tone="info"
          title={__(
            "This is one of MCPMate's playbooks. It can't be changed here; ask your AI app to save your own skill to work differently.",
            "mcpmate",
          )}
        />
        {readOnlyBody(system.body ?? "", __("Playbook", "mcpmate"))}
      </div>
    );
  } else {
    const owner =
      saved.user.name ??
      saved.user.login ??
      sprintf(
        /* translators: %d: user ID. */ __("User #%d", "mcpmate"),
        saved.user.id,
      );
    const failure = save.error ?? move.error;

    body = (
      <div className="el-stack">
        <p className="el-setting-hint">
          {sprintf(
            /* translators: 1: a person's name, 2: "this site" or "all sites", 3: date and time. */
            __("Saved by %1$s for %2$s. Last changed %3$s.", "mcpmate"),
            owner,
            saved.this_site_only
              ? __("this site", "mcpmate")
              : __("all sites", "mcpmate"),
            fullDateTime(saved.updated_at),
          )}
        </p>
        {trashed && (
          <Notice
            tone="warning"
            title={__(
              "This skill is in the trash. AI apps no longer see it.",
              "mcpmate",
            )}
          />
        )}
        {failure && (
          <Notice
            tone="danger"
            title={__("Couldn't save. Nothing was changed.", "mcpmate")}
            live
          >
            {requestErrorText(failure)}
          </Notice>
        )}
        {trashed || !draft ? (
          <>
            <p className="el-setting-label">{saved.description}</p>
            {readOnlyBody(saved.body ?? "", __("Skill", "mcpmate"))}
          </>
        ) : (
          <form
            noValidate
            aria-label={__("Edit skill", "mcpmate")}
            className="el-stack"
            onSubmit={(event) => {
              event.preventDefault();
              submit();
            }}
          >
            <div>
              <label
                htmlFor="el-skill-description"
                className="el-setting-label"
              >
                {__("Description", "mcpmate")}
              </label>
              <p id="el-skill-description-hint" className="el-setting-hint">
                {__(
                  "One line: what the skill is for and when AI apps should load it.",
                  "mcpmate",
                )}
              </p>
              <input
                type="text"
                className="el-input"
                value={draft.description}
                onChange={(event) => edit("description", event.target.value)}
                {...field("description")}
              />
              {fieldError("description")}
            </div>
            <div>
              <label htmlFor="el-skill-body" className="el-setting-label">
                {__("Skill", "mcpmate")}
              </label>
              <p id="el-skill-body-hint" className="el-setting-hint">
                {sprintf(
                  /* translators: 1: bytes used, 2: the limit in bytes. */
                  __("Markdown. %1$s of %2$s bytes.", "mcpmate"),
                  formatNumber(contentBytes(draft.body)),
                  formatNumber(limits.body_bytes),
                )}
              </p>
              <textarea
                className="el-input el-mono"
                rows={14}
                value={draft.body}
                onChange={(event) => edit("body", event.target.value)}
                {...field("body")}
              />
              {fieldError("body")}
            </div>
          </form>
        )}
      </div>
    );
  }

  return (
    <Drawer
      title={data ? data.name : __("Skill", "mcpmate")}
      open={which !== null}
      onClose={onClose}
      size="lg"
      footer={footer}
    >
      {body}
    </Drawer>
  );
}

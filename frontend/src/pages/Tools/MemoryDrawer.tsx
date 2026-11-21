import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  AjaxRequestError,
  fetchMemory,
  moveMemory,
  saveMemory,
} from "@services/api";
import { MEMORIES_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { MemoriesPage, Memory } from "../../types/api";
import { fullDateTime } from "../Activity/logic";
import {
  contentBytes,
  memoryErrors,
  parseTags,
  type MemoryDraft,
  type MemoryErrors,
} from "./memoryLogic";

const draftOf = (memory: Memory): MemoryDraft => ({
  title: memory.title,
  content: memory.content ?? "",
  tags: memory.tags.join(", "),
});

/**
 * One memory in a drawer (`?memory=<id>`): a live one can be edited with
 * save_memory's checks or moved to the trash; a trashed one is shown as
 * text, to restore or delete for good (the parent asks first). The note
 * is shown as text, never as HTML.
 */
export default function MemoryDrawer({
  memoryId,
  limits,
  onClose,
  onPurge,
}: {
  memoryId: number | null;
  limits: MemoriesPage["limits"];
  onClose: () => void;
  /** Ask, then delete this trashed memory for good. */
  onPurge: (memory: Memory) => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const memory = useQuery({
    queryKey: [...MEMORIES_QUERY_KEY, "show", memoryId],
    queryFn: () => fetchMemory(memoryId as number),
    enabled: memoryId !== null,
    staleTime: 0,
  });
  const [draft, setDraft] = useState<MemoryDraft | null>(null);
  const [serverErrors, setServerErrors] = useState<MemoryErrors>({});
  const [checked, setChecked] = useState(false);

  useEffect(() => {
    setDraft(memory.data ? draftOf(memory.data) : null);
    setServerErrors({});
    setChecked(false);
  }, [memory.data]);

  const changed = (next: Memory, message: string) => {
    queryClient.setQueryData([...MEMORIES_QUERY_KEY, "show", next.id], next);
    void queryClient.invalidateQueries({
      queryKey: [...MEMORIES_QUERY_KEY, "list"],
    });
    announce(message);
  };

  const save = useMutation({
    mutationFn: (values: MemoryDraft) =>
      saveMemory(
        memoryId as number,
        values.title,
        values.content,
        parseTags(values.tags),
      ),
    onSuccess: (next) => changed(next, __("Memory saved.", "mcpmate")),
    onError: (error) => {
      const refused =
        error instanceof AjaxRequestError
          ? (error.details?.errors as MemoryErrors | undefined)
          : undefined;
      setServerErrors(refused ?? {});
    },
  });
  const move = useMutation({
    mutationFn: (to: "trash" | "restore") => moveMemory(memoryId as number, to),
    onSuccess: (next, to) =>
      changed(
        next,
        to === "trash"
          ? __("Memory moved to the trash.", "mcpmate")
          : __("Memory restored.", "mcpmate"),
      ),
  });

  const data = memory.data;
  const trashed = Boolean(data?.trashed_at);
  const clientErrors = draft && checked ? memoryErrors(draft, limits) : {};
  const errors: MemoryErrors = { ...serverErrors, ...clientErrors };
  const dirty =
    data && draft
      ? JSON.stringify(draftOf(data)) !== JSON.stringify(draft)
      : false;

  const edit = (key: keyof MemoryDraft, value: string) => {
    setDraft((current) => (current ? { ...current, [key]: value } : current));
    setServerErrors((current) => ({ ...current, [key]: undefined }));
  };

  const submit = () => {
    if (!draft) {
      return;
    }
    setChecked(true);
    const invalid = memoryErrors(draft, limits);
    const first = (["title", "tags", "content"] as const).find(
      (key) => invalid[key],
    );
    if (first) {
      document.getElementById(`el-memory-${first}`)?.focus();
      return;
    }
    save.mutate(draft);
  };

  const field = (key: keyof MemoryDraft) => ({
    id: `el-memory-${key}`,
    "aria-invalid": errors[key] ? true : undefined,
    "aria-describedby":
      [`el-memory-${key}-hint`, errors[key] && `el-memory-${key}-error`]
        .filter(Boolean)
        .join(" ") || undefined,
  });

  const fieldError = (key: keyof MemoryDraft) =>
    errors[key] ? (
      <p id={`el-memory-${key}-error`} className="el-field-error">
        {errors[key]}
      </p>
    ) : null;

  const failure = save.error ?? move.error;

  const footer = data && draft && (
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
          <Button variant="danger-secondary" onClick={() => onPurge(data)}>
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

  let body;

  if (memory.isPending) {
    body = (
      <div aria-busy="true">
        <Skeleton count={4} />
      </div>
    );
  } else if (memory.isError || !data || !draft) {
    body = (
      <Notice
        tone="danger"
        title={__("Couldn't load this memory.", "mcpmate")}
        action={
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void memory.refetch()}
          >
            {__("Try again", "mcpmate")}
          </Button>
        }
      >
        {requestErrorText(memory.error)}
      </Notice>
    );
  } else {
    const owner =
      data.user.name ??
      sprintf(
        /* translators: %d: user ID. */ __("User #%d", "mcpmate"),
        data.user_id,
      );
    const meta = sprintf(
      /* translators: 1: a person's name, 2: date and time. */
      __("Saved for %1$s. Last changed %2$s.", "mcpmate"),
      owner,
      fullDateTime(data.updated_at),
    );

    body = (
      <div className="el-stack">
        <p className="el-setting-hint">{meta}</p>
        {trashed && (
          <Notice
            tone="warning"
            title={__(
              "This memory is in the trash. AI apps no longer see it.",
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
        {trashed ? (
          <>
            <h3 className="el-setting-label">{data.title}</h3>
            <pre
              className="el-json el-preview-text"
              dir="auto"
              tabIndex={0}
              role="region"
              aria-label={__("Memory text", "mcpmate")}
            >
              {data.content}
            </pre>
          </>
        ) : (
          <form
            noValidate
            aria-label={__("Edit memory", "mcpmate")}
            className="el-stack"
            onSubmit={(event) => {
              event.preventDefault();
              submit();
            }}
          >
            <div>
              <label htmlFor="el-memory-title" className="el-setting-label">
                {__("Title", "mcpmate")}
              </label>
              <p id="el-memory-title-hint" className="el-setting-hint">
                {__("One line saying what the memory is about.", "mcpmate")}
              </p>
              <input
                type="text"
                className="el-input"
                value={draft.title}
                onChange={(event) => edit("title", event.target.value)}
                {...field("title")}
              />
              {fieldError("title")}
            </div>
            <div>
              <label htmlFor="el-memory-tags" className="el-setting-label">
                {__("Tags", "mcpmate")}
              </label>
              <p id="el-memory-tags-hint" className="el-setting-hint">
                {__("Separate tags with commas, e.g. brand, shop.", "mcpmate")}
              </p>
              <input
                type="text"
                className="el-input"
                value={draft.tags}
                onChange={(event) => edit("tags", event.target.value)}
                {...field("tags")}
              />
              {fieldError("tags")}
            </div>
            <div>
              <label htmlFor="el-memory-content" className="el-setting-label">
                {__("Memory", "mcpmate")}
              </label>
              <p id="el-memory-content-hint" className="el-setting-hint">
                {sprintf(
                  /* translators: 1: bytes used, 2: the limit in bytes. */
                  __("Plain text or Markdown. %1$s of %2$s bytes.", "mcpmate"),
                  formatNumber(contentBytes(draft.content)),
                  formatNumber(limits.content_bytes),
                )}
              </p>
              <textarea
                className="el-input"
                rows={12}
                value={draft.content}
                onChange={(event) => edit("content", event.target.value)}
                {...field("content")}
              />
              {fieldError("content")}
            </div>
          </form>
        )}
      </div>
    );
  }

  return (
    <Drawer
      title={data ? data.title : __("Memory", "mcpmate")}
      open={memoryId !== null}
      onClose={onClose}
      size="lg"
      footer={footer}
    >
      {body}
    </Drawer>
  );
}

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import { AjaxRequestError } from "@services/api";
import {
  activateDesign,
  deactivateDesign,
  DESIGNS_QUERY_KEY,
  fetchDesign,
  moveDesign,
  previewDesign,
  restoreDesignRevision,
  saveDesign,
} from "@services/designs";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import type { Design, DesignTokens } from "../../types/api";
import { fullDateTime } from "../Activity/logic";
import {
  cssLength,
  designBytes,
  MAX_DESIGN_BYTES,
  NEW_DESIGN,
  swatches,
} from "./designLogic";

/** `?design=new` opens an empty design. */
export type DesignTarget = number | "new" | null;

interface Draft {
  name: string;
  content: string;
}

/** The token preview: colours, fonts, the type scale, spacing and radii, drawn with the values themselves. */
export function TokenPreview({ tokens }: { tokens: DesignTokens }) {
  const colors = swatches(tokens.colors);
  const fonts = Object.entries(tokens.fonts ?? {});
  const sizes = Object.entries(tokens.type_scale ?? {}).filter(([, v]) =>
    cssLength(v),
  );
  const spacing = Object.entries(tokens.spacing ?? {}).filter(([, v]) =>
    cssLength(v),
  );
  const radii = Object.entries(tokens.radii ?? {}).filter(([, v]) =>
    cssLength(v),
  );

  if (
    colors.length +
      fonts.length +
      sizes.length +
      spacing.length +
      radii.length ===
    0
  ) {
    return (
      <p className="m-0 text-el-text-secondary">
        {__("No tokens to show yet.", "mcpmate")}
      </p>
    );
  }

  return (
    <div className="el-design-preview">
      {colors.length > 0 && (
        <section aria-label={__("Colours", "mcpmate")}>
          <h4 className="el-setting-label">{__("Colours", "mcpmate")}</h4>
          <ul className="el-design-swatches">
            {colors.map(([name, value]) => (
              <li key={name}>
                <span
                  className="el-design-swatch"
                  style={{ background: value }}
                  aria-hidden
                />
                <span className="el-design-token">
                  <bdi>{name}</bdi>
                  <span className="el-mono text-el-text-secondary">
                    {value}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {fonts.length > 0 && (
        <section aria-label={__("Fonts", "mcpmate")}>
          <h4 className="el-setting-label">{__("Fonts", "mcpmate")}</h4>
          <ul className="el-design-list">
            {fonts.map(([name, stack]) => (
              <li key={name}>
                <span
                  className="el-design-sample"
                  style={{ fontFamily: stack }}
                >
                  {__("The quick brown fox", "mcpmate")}
                </span>
                <span className="el-design-token">
                  <bdi>{name}</bdi>
                  <span className="el-mono text-el-text-secondary">
                    {stack}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {sizes.length > 0 && (
        <section aria-label={__("Type scale", "mcpmate")}>
          <h4 className="el-setting-label">{__("Type scale", "mcpmate")}</h4>
          <ul className="el-design-list">
            {sizes.map(([name, value]) => (
              <li key={name}>
                <span
                  className="el-design-sample"
                  style={{ fontSize: value, fontFamily: tokens.fonts?.heading }}
                >
                  Aa
                </span>
                <span className="el-design-token">
                  <bdi>{name}</bdi>
                  <span className="el-mono text-el-text-secondary">
                    {value}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {spacing.length > 0 && (
        <section aria-label={__("Spacing", "mcpmate")}>
          <h4 className="el-setting-label">{__("Spacing", "mcpmate")}</h4>
          <ul className="el-design-list">
            {spacing.map(([name, value]) => (
              <li key={name}>
                <span
                  className="el-design-bar"
                  style={{ inlineSize: value }}
                  aria-hidden
                />
                <span className="el-design-token">
                  <bdi>{name}</bdi>
                  <span className="el-mono text-el-text-secondary">
                    {value}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {radii.length > 0 && (
        <section aria-label={__("Radii", "mcpmate")}>
          <h4 className="el-setting-label">{__("Radii", "mcpmate")}</h4>
          <ul className="el-design-swatches">
            {radii.map(([name, value]) => (
              <li key={name}>
                <span
                  className="el-design-radius"
                  style={{ borderRadius: value }}
                  aria-hidden
                />
                <span className="el-design-token">
                  <bdi>{name}</bdi>
                  <span className="el-mono text-el-text-secondary">
                    {value}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

/**
 * One design in a drawer (`?design=<id>` or `?design=new`): edit its
 * Markdown with a live token preview and the server's own checks, save,
 * activate, see and restore revisions, move it to the trash.
 */
export default function DesignDrawer({
  target,
  onClose,
  onCreated,
}: {
  target: DesignTarget;
  onClose: () => void;
  /** A new design was saved: open it. */
  onCreated: (design: Design) => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const id = typeof target === "number" ? target : null;
  const design = useQuery({
    queryKey: [...DESIGNS_QUERY_KEY, "show", id],
    queryFn: () => fetchDesign(id as number),
    enabled: id !== null,
    staleTime: 0,
  });
  const [draft, setDraft] = useState<Draft | null>(null);
  const [debounced, setDebounced] = useState("");
  const [notice, setNotice] = useState("");
  const savedName = design.data?.name;
  const savedContent = design.data?.content;

  // Each design starts from its saved text (or the template); a save or a
  // restored revision brings its new text in, keeping the notice about it.
  useEffect(() => {
    if (target === "new") {
      setDraft({ name: "", content: NEW_DESIGN });
    } else if (savedName !== undefined && savedContent !== undefined) {
      setDraft({ name: savedName, content: savedContent });
    } else {
      setDraft(null);
    }
  }, [target, savedName, savedContent]);

  useEffect(() => {
    setNotice("");
  }, [target]);

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebounced(draft?.content ?? ""),
      400,
    );

    return () => window.clearTimeout(timer);
  }, [draft?.content]);

  const preview = useQuery({
    queryKey: [...DESIGNS_QUERY_KEY, "preview", debounced],
    queryFn: () => previewDesign(debounced),
    enabled: target !== null && debounced !== "",
    staleTime: Infinity,
  });

  const refresh = (saved: Design) => {
    queryClient.setQueryData([...DESIGNS_QUERY_KEY, "show", saved.id], saved);
    void queryClient.invalidateQueries({
      queryKey: [...DESIGNS_QUERY_KEY, "list"],
    });
  };

  const save = useMutation({
    mutationFn: () =>
      saveDesign({
        id,
        name: draft?.name ?? "",
        content: draft?.content ?? "",
      }),
    onSuccess: (saved) => {
      refresh(saved);
      announce(__("Design saved.", "mcpmate"));
      setNotice(__("Design saved.", "mcpmate"));
      if (target === "new") {
        onCreated(saved);
      }
    },
  });

  const act = useMutation({
    mutationFn: async (
      action: "activate" | "deactivate" | "trash" | "restore",
    ) => {
      if (action === "activate") {
        return activateDesign(id as number);
      }
      if (action === "deactivate") {
        await deactivateDesign();

        return fetchDesign(id as number);
      }

      return moveDesign(id as number, action);
    },
    onSuccess: (saved, action) => {
      refresh(saved);
      const text =
        action === "activate"
          ? sprintf(
              /* translators: %s: design name. */
              __(
                "“%s” is now the active design. AI apps follow it from their next conversation.",
                "mcpmate",
              ),
              saved.name,
            )
          : action === "deactivate"
            ? __("No design is active now.", "mcpmate")
            : action === "trash"
              ? __("Design moved to the trash.", "mcpmate")
              : __("Design restored.", "mcpmate");
      setNotice(text);
      announce(text);
    },
  });

  const restore = useMutation({
    mutationFn: (revision: number) =>
      restoreDesignRevision(id as number, revision),
    onSuccess: (saved) => {
      refresh(saved);
      const text = __(
        "Revision restored. The text it replaced is kept as a revision.",
        "mcpmate",
      );
      setNotice(text);
      announce(text);
    },
  });

  const failure = save.error ?? act.error ?? restore.error;
  const serverErrors =
    failure instanceof AjaxRequestError &&
    Array.isArray(failure.details?.errors)
      ? (failure.details.errors as unknown as string[])
      : [];
  const data = design.data;
  const trashed = data?.status === "trash";
  const bytes = designBytes(draft?.content ?? "");
  const busy = save.isPending || act.isPending || restore.isPending;
  const title =
    target === "new"
      ? __("New design", "mcpmate")
      : (data?.name ?? __("Design", "mcpmate"));

  let body;
  if (target === null) {
    body = null;
  } else if (id !== null && design.isPending) {
    body = <Skeleton count={6} />;
  } else if (id !== null && (design.isError || !data)) {
    body = (
      <Notice tone="danger" title={__("Couldn't load this design.", "mcpmate")}>
        {requestErrorText(design.error)}
      </Notice>
    );
  } else if (draft) {
    const problems = preview.data?.errors ?? [];
    const warnings = preview.data?.warnings ?? [];

    body = (
      <div className="el-stack">
        {notice && <Notice tone="success" title={notice} />}
        {data?.active && (
          <Notice
            tone="info"
            title={__(
              "This is the active design. AI apps follow it.",
              "mcpmate",
            )}
          >
            {__(
              "Your changes apply to AI apps from their next conversation. AI apps cannot change this design themselves.",
              "mcpmate",
            )}
          </Notice>
        )}
        {trashed && (
          <Notice
            tone="warning"
            title={__("This design is in the trash.", "mcpmate")}
          />
        )}
        {failure && (
          <Notice
            tone="danger"
            live
            title={__("That didn't work. Nothing was changed.", "mcpmate")}
          >
            {requestErrorText(failure)}
            {serverErrors.length > 1 && (
              <ul className="el-design-issues">
                {serverErrors.slice(1).map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            )}
          </Notice>
        )}
        <form
          noValidate
          aria-label={__("Edit design", "mcpmate")}
          className="el-stack"
          onSubmit={(event) => {
            event.preventDefault();
            if (!trashed) {
              save.mutate();
            }
          }}
        >
          <div>
            <label htmlFor="el-design-name" className="el-setting-label">
              {__("Name", "mcpmate")}
            </label>
            <p id="el-design-name-hint" className="el-setting-hint">
              {__(
                "Left empty, the name in the front matter is used.",
                "mcpmate",
              )}
            </p>
            <input
              id="el-design-name"
              type="text"
              className="el-input"
              value={draft.name}
              maxLength={100}
              readOnly={trashed}
              aria-describedby="el-design-name-hint"
              onChange={(event) =>
                setDraft({ ...draft, name: event.target.value })
              }
            />
          </div>
          <div>
            <label htmlFor="el-design-content" className="el-setting-label">
              {__("Design (Markdown)", "mcpmate")}
            </label>
            <p id="el-design-content-hint" className="el-setting-hint">
              {sprintf(
                /* translators: 1: bytes used, 2: the limit in bytes. */
                __(
                  "Tokens in the front matter between the two --- lines, then the guidance AI apps read. %1$s of %2$s bytes.",
                  "mcpmate",
                ),
                formatNumber(bytes),
                formatNumber(MAX_DESIGN_BYTES),
              )}
            </p>
            <textarea
              id="el-design-content"
              className="el-input el-mono"
              rows={18}
              spellCheck={false}
              value={draft.content}
              readOnly={trashed}
              aria-describedby="el-design-content-hint el-design-checks"
              aria-invalid={
                problems.length > 0 || bytes > MAX_DESIGN_BYTES
                  ? true
                  : undefined
              }
              onChange={(event) =>
                setDraft({ ...draft, content: event.target.value })
              }
            />
          </div>
          <div id="el-design-checks" aria-live="polite">
            {problems.length > 0 && (
              <Notice
                tone="danger"
                title={__("Fix these before you save:", "mcpmate")}
              >
                <ul className="el-design-issues">
                  {problems.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </Notice>
            )}
            {problems.length === 0 && warnings.length > 0 && (
              <Notice
                tone="warning"
                title={sprintf(
                  /* translators: %s: number of warnings. */
                  _n("%s warning", "%s warnings", warnings.length, "mcpmate"),
                  formatNumber(warnings.length),
                )}
              >
                <ul className="el-design-issues">
                  {warnings.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </Notice>
            )}
          </div>
        </form>

        <section aria-labelledby="el-design-preview-title">
          <h3 id="el-design-preview-title" className="el-setting-label">
            {__("Preview", "mcpmate")}
          </h3>
          <TokenPreview tokens={preview.data?.tokens ?? data?.tokens ?? {}} />
        </section>

        {data && data.revisions.length > 0 && (
          <section aria-labelledby="el-design-revisions-title">
            <h3 id="el-design-revisions-title" className="el-setting-label">
              {__("Revisions", "mcpmate")}
            </h3>
            <p className="el-setting-hint">
              {__(
                "Each save keeps the text it replaced. Restoring one keeps the current text as a revision too.",
                "mcpmate",
              )}
            </p>
            <ul className="el-design-revisions">
              {data.revisions.slice(0, 20).map((revision) => (
                <li key={revision.id}>
                  <span>
                    <time dateTime={revision.at}>
                      {fullDateTime(revision.at)}
                    </time>
                    {" · "}
                    {revision.by.name ?? `#${revision.by.id}`}
                  </span>
                  {!trashed && (
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={busy}
                      onClick={() => restore.mutate(revision.id)}
                      label={sprintf(
                        /* translators: %s: date and time of the revision. */
                        __("Restore the revision of %s", "mcpmate"),
                        fullDateTime(revision.at),
                      )}
                    >
                      {__("Restore", "mcpmate")}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    );
  }

  const footer =
    draft && target !== null ? (
      <div className="el-dialog-actions">
        {!trashed && (
          <Button
            variant="primary"
            loading={save.isPending}
            disabled={busy || bytes > MAX_DESIGN_BYTES}
            onClick={() => save.mutate()}
          >
            {__("Save design", "mcpmate")}
          </Button>
        )}
        {data && !trashed && !data.active && (
          <Button
            variant="secondary"
            disabled={busy}
            onClick={() => act.mutate("activate")}
          >
            {__("Make active", "mcpmate")}
          </Button>
        )}
        {data?.active && (
          <Button
            variant="secondary"
            disabled={busy}
            onClick={() => act.mutate("deactivate")}
          >
            {__("Turn off", "mcpmate")}
          </Button>
        )}
        {data && !trashed && !data.active && (
          <Button
            variant="secondary"
            disabled={busy}
            onClick={() => act.mutate("trash")}
          >
            {__("Move to trash", "mcpmate")}
          </Button>
        )}
        {trashed && (
          <Button
            variant="primary"
            disabled={busy}
            onClick={() => act.mutate("restore")}
          >
            {__("Restore", "mcpmate")}
          </Button>
        )}
      </div>
    ) : undefined;

  return (
    <Drawer
      title={title}
      open={target !== null}
      onClose={onClose}
      size="lg"
      footer={footer}
    >
      {body}
    </Drawer>
  );
}

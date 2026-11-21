import { useEffect, useRef, useState, type Ref } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import {
  AjaxRequestError,
  fetchSiteInstructions,
  previewSiteInstructions,
  saveSiteInstructions,
} from "@services/api";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import Drawer from "@components/ui/Drawer";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import { requestErrorText } from "@utils/errors";
import { formatDate, formatNumber } from "@utils/format";
import type { SiteInstructions } from "../../types/api";
import {
  INSTRUCTIONS_MAX_BYTES,
  instructionBytes,
  instructionsError,
} from "./logic";
import { FieldError, SettingRow, SettingsForm, type SaveStatus } from "./rows";

const SITE_INSTRUCTIONS_QUERY_KEY = ["site-instructions"];

const SAVED_MS = 5000;
const TEXT_ID = "el-setting-site-instructions";
const PREVIEW_PARAM = "preview";
const PREVIEW_VALUE = "instructions";

interface Draft {
  enabled: boolean;
  content: string;
}

/**
 * Settings → AI instructions (MCPMate Pro; docs/pro-parity.md P1): text the
 * site admin writes for connected AI apps, sent first in the MCP server
 * instructions while it is on. Its own form with its own Save changes, and
 * a preview of the full instructions an AI app gets (`?preview=instructions`
 * keeps the drawer open across Back and reload). The text is shown as text
 * everywhere, never as HTML.
 */
export default function InstructionsCard({
  title,
  headingId,
  headingRef,
  onDirtyChange,
}: {
  title: string;
  headingId: string;
  headingRef?: Ref<HTMLHeadingElement>;
  /** How many of the two values differ from what is saved, for the leave guard. */
  onDirtyChange: (count: number) => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [params, setParams] = useSearchParams();
  const settings = useQuery({
    queryKey: SITE_INSTRUCTIONS_QUERY_KEY,
    queryFn: fetchSiteInstructions,
    staleTime: 0,
  });
  const saved = settings.data;
  const [draft, setDraft] = useState<Draft | null>(null);
  const [status, setStatus] = useState<SaveStatus>({ kind: "idle" });
  const [serverError, setServerError] = useState<string>();
  const savedTimer = useRef<number>();

  useEffect(() => {
    if (saved && draft === null) {
      setDraft({ enabled: saved.enabled, content: saved.content });
    }
  }, [saved, draft]);

  useEffect(() => () => window.clearTimeout(savedTimer.current), []);

  const dirty =
    saved && draft
      ? Number(draft.enabled !== saved.enabled) +
        Number(draft.content !== saved.content)
      : 0;

  useEffect(() => onDirtyChange(dirty), [dirty, onDirtyChange]);

  const max = saved?.max_bytes ?? INSTRUCTIONS_MAX_BYTES;
  const error =
    serverError ?? (draft ? instructionsError(draft.content, max) : undefined);

  const setStatusTo = (next: SaveStatus) => {
    window.clearTimeout(savedTimer.current);
    setStatus(next);
    if (next.kind === "saved" || next.kind === "nothing") {
      savedTimer.current = window.setTimeout(
        () => setStatus({ kind: "idle" }),
        SAVED_MS,
      );
    }
  };

  const change = (next: Partial<Draft>) => {
    setDraft((current) => (current ? { ...current, ...next } : current));
    setServerError(undefined);
    if (status.kind !== "saving") {
      setStatusTo({ kind: "idle" });
    }
  };

  const focusText = () =>
    window.requestAnimationFrame(() =>
      document.getElementById(TEXT_ID)?.focus(),
    );

  const save = async () => {
    if (!saved || !draft) {
      return;
    }
    if (dirty === 0) {
      setStatusTo({ kind: "nothing" });
      announce(__("Nothing to save. Change a setting first.", "mcpmate"));
      return;
    }
    if (instructionsError(draft.content, max)) {
      setStatusTo({ kind: "invalid", count: 1 });
      focusText();
      return;
    }

    setStatusTo({ kind: "saving" });
    try {
      const response = await saveSiteInstructions(draft.enabled, draft.content);
      queryClient.setQueryData<SiteInstructions>(
        SITE_INSTRUCTIONS_QUERY_KEY,
        response,
      );
      // Show what the server stored (line ends and trimming).
      setDraft({ enabled: response.enabled, content: response.content });
      setStatusTo({ kind: "saved" });
      announce(__("Saved.", "mcpmate"));
    } catch (failure) {
      const refused =
        failure instanceof AjaxRequestError
          ? (failure.details?.errors as Record<string, string> | undefined)
              ?.content
          : undefined;
      if (refused) {
        setServerError(refused);
        focusText();
      }
      const message = requestErrorText(failure);
      setStatusTo({ kind: "failed", message });
      announce(
        sprintf(
          /* translators: %s: why the save failed. */
          __("Couldn't save. Nothing was changed. %s", "mcpmate"),
          message,
        ),
        { assertive: true },
      );
    }
  };

  const discard = () => {
    if (saved) {
      setDraft({ enabled: saved.enabled, content: saved.content });
    }
    setServerError(undefined);
    setStatusTo({ kind: "idle" });
    announce(__("Changes discarded.", "mcpmate"));
  };

  // ── Preview ──

  const previewOpen = params.get(PREVIEW_PARAM) === PREVIEW_VALUE;
  const preview = useQuery({
    queryKey: [
      ...SITE_INSTRUCTIONS_QUERY_KEY,
      "preview",
      draft?.enabled,
      draft?.content,
    ],
    queryFn: () =>
      previewSiteInstructions(draft?.enabled ?? false, draft?.content ?? ""),
    enabled: previewOpen && draft !== null && !error,
    staleTime: 0,
  });

  const setPreview = (open: boolean) =>
    setParams((current) => {
      const next = new URLSearchParams(current);
      if (open) {
        next.set(PREVIEW_PARAM, PREVIEW_VALUE);
      } else {
        next.delete(PREVIEW_PARAM);
      }
      return next;
    });

  if (settings.isError && !saved) {
    return (
      <Card title={title} headingId={headingId} headingRef={headingRef}>
        <Notice
          tone="danger"
          title={__("Couldn't load the AI instructions.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void settings.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(settings.error)}
        </Notice>
      </Card>
    );
  }

  if (!saved || !draft) {
    return (
      <Card title={title} headingId={headingId} headingRef={headingRef} busy>
        <div aria-busy="true">
          <Skeleton count={3} />
        </div>
      </Card>
    );
  }

  const hintId = `${TEXT_ID}-hint`;
  const countId = `${TEXT_ID}-count`;
  const errorId = `${TEXT_ID}-error`;
  const bytes = instructionBytes(draft.content);

  return (
    <>
      <SettingsForm
        title={title}
        headingId={headingId}
        headingRef={headingRef}
        busy={false}
        dirty={dirty}
        status={status}
        editable
        onSave={() => void save()}
        onDiscard={discard}
        className="el-settings-section"
      >
        <div className="el-setting-row">
          <Toggle
            label={__("Send these instructions to AI apps", "mcpmate")}
            description={__(
              "Connected AI apps get them first, before MCPMate's own instructions. An app sees a change in its next chat.",
              "mcpmate",
            )}
            checked={draft.enabled}
            onChange={(enabled) => change({ enabled })}
          />
        </div>
        <div className="el-setting-row el-setting-stack">
          <div className="el-setting-text">
            <label htmlFor={TEXT_ID} className="el-setting-label">
              {__("Instructions", "mcpmate")}
            </label>
            <p id={hintId} className="el-setting-hint">
              {__(
                "What AI apps should know about this site: your house style, pages to leave alone, who to ask. Plain text. Every connected AI app can read it, so don't put passwords or keys here.",
                "mcpmate",
              )}
            </p>
          </div>
          <textarea
            id={TEXT_ID}
            className="el-input"
            rows={8}
            value={draft.content}
            aria-invalid={error ? true : undefined}
            aria-describedby={[hintId, countId, error && errorId]
              .filter(Boolean)
              .join(" ")}
            onChange={(event) => change({ content: event.target.value })}
          />
          <p id={countId} className="el-setting-hint">
            {sprintf(
              /* translators: 1: bytes used, 2: the limit in bytes. */
              __("%1$s of %2$s bytes", "mcpmate"),
              formatNumber(bytes),
              formatNumber(max),
            )}
            {saved.updated_at &&
              saved.updated_by &&
              ` · ${sprintf(
                /* translators: 1: a person's name, 2: a date. */
                __("Last saved by %1$s on %2$s.", "mcpmate"),
                saved.updated_by.name,
                formatDate(saved.updated_at, { dateStyle: "medium" }),
              )}`}
          </p>
          <FieldError id={errorId} text={error} />
        </div>
        <SettingRow
          label={__("Preview", "mcpmate")}
          hint={__(
            "Everything an AI app gets when it connects, with your changes. Nothing is saved.",
            "mcpmate",
          )}
        >
          <Button disabled={Boolean(error)} onClick={() => setPreview(true)}>
            {__("Preview", "mcpmate")}
          </Button>
        </SettingRow>
      </SettingsForm>

      <Drawer
        title={__("What AI apps receive", "mcpmate")}
        open={previewOpen}
        onClose={() => setPreview(false)}
        size="lg"
      >
        {error ? (
          <Notice
            tone="warning"
            title={__("Fix the instructions to see a preview.", "mcpmate")}
          >
            {error}
          </Notice>
        ) : preview.isPending ? (
          <div aria-busy="true">
            <Skeleton count={4} />
          </div>
        ) : preview.isError ? (
          <Notice
            tone="danger"
            title={__("Couldn't show the preview.", "mcpmate")}
            action={
              <Button
                variant="secondary"
                size="sm"
                onClick={() => void preview.refetch()}
              >
                {__("Try again", "mcpmate")}
              </Button>
            }
          >
            {requestErrorText(preview.error)}
          </Notice>
        ) : (
          <div className="el-stack">
            <p className="el-setting-hint">
              {preview.data.site_bytes > 0
                ? __(
                    "Your instructions come first, inside the site-owner-instructions tags. MCPMate's own instructions follow.",
                    "mcpmate",
                  )
                : __(
                    "Your instructions are off or empty, so AI apps get only MCPMate's own instructions.",
                    "mcpmate",
                  )}
            </p>
            <pre
              className="el-json el-preview-text"
              dir="auto"
              tabIndex={0}
              role="region"
              aria-label={__("Instructions AI apps receive", "mcpmate")}
            >
              {preview.data.instructions}
            </pre>
          </div>
        )}
      </Drawer>
    </>
  );
}

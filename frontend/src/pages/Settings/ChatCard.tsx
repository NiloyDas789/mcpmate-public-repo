import { useEffect, useRef, useState, type Ref } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { __ } from "@wordpress/i18n";
import { saveChatSettings } from "@services/api";
import { CHAT_QUERY_KEY, useChatSettings } from "@services/queries";
import Button from "@components/ui/Button";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import { requestErrorText } from "@utils/errors";
import type { ChatSettingsView } from "../../types/api";
import { FieldError, SettingRow, SettingsForm, type SaveStatus } from "./rows";

const KEY_ID = "el-setting-chat-key";
const MODEL_ID = "el-setting-chat-model";
const SAVED_MS = 5000;

interface Draft {
  /** A new key to save; "" keeps the saved one. */
  key: string;
  removeKey: boolean;
  model: string;
  enabled: boolean;
}

const draftOf = (settings: ChatSettingsView): Draft => ({
  key: "",
  removeKey: false,
  model: settings.model,
  enabled: settings.enabled,
});

/**
 * Settings → Chat (MCPMate Pro; docs/pro-parity.md P22): the site's
 * Anthropic API key (write-only: once saved, it is never shown again), the
 * model and the switch. A new key is checked with Anthropic before it is
 * saved. Its own form with its own Save changes.
 */
export default function ChatCard({
  title,
  headingId,
  headingRef,
  onDirtyChange,
}: {
  title: string;
  headingId: string;
  headingRef?: Ref<HTMLHeadingElement>;
  onDirtyChange: (count: number) => void;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const settings = useChatSettings();
  const [draft, setDraft] = useState<Draft | null>(
    settings ? draftOf(settings) : null,
  );
  const [status, setStatus] = useState<SaveStatus>({ kind: "idle" });
  const [keyError, setKeyError] = useState("");
  const savedTimer = useRef<number | undefined>(undefined);

  useEffect(() => () => window.clearTimeout(savedTimer.current), []);

  const dirty =
    settings && draft
      ? Number(draft.key.trim() !== "" || draft.removeKey) +
        Number(draft.model !== settings.model) +
        Number(draft.enabled !== settings.enabled)
      : 0;

  useEffect(() => onDirtyChange(dirty), [dirty, onDirtyChange]);

  // Settings that arrive after the first render (no bootstrap data) still get a draft.
  useEffect(() => {
    if (settings && draft === null) {
      setDraft(draftOf(settings));
    }
  }, [settings, draft]);

  if (!settings || !draft) {
    return null;
  }

  const change = (next: Partial<Draft>) => {
    setDraft({ ...draft, ...next });
    setStatus({ kind: "idle" });
    setKeyError("");
  };

  const save = async () => {
    if (dirty === 0) {
      setStatus({ kind: "nothing" });
      return;
    }

    const changes: { api_key?: string; model?: string; enabled?: boolean } = {};
    if (draft.removeKey) {
      changes.api_key = "";
    } else if (draft.key.trim() !== "") {
      changes.api_key = draft.key.trim();
    }
    if (draft.model !== settings.model) {
      changes.model = draft.model;
    }
    if (draft.enabled !== settings.enabled && !draft.removeKey) {
      changes.enabled = draft.enabled;
    }

    // A "saved" timer of an earlier save must not end this one's status.
    window.clearTimeout(savedTimer.current);
    setStatus({ kind: "saving" });
    try {
      const saved = await saveChatSettings(changes);
      queryClient.setQueryData(CHAT_QUERY_KEY, saved);
      setDraft(draftOf(saved));
      setStatus({ kind: "saved" });
      announce(__("Chat settings saved.", "mcpmate"));
      savedTimer.current = window.setTimeout(
        () => setStatus({ kind: "idle" }),
        SAVED_MS,
      );
    } catch (caught) {
      const message = requestErrorText(caught);
      if (changes.api_key) {
        setKeyError(message);
      }
      setStatus({ kind: "failed", message });
    }
  };

  const keyHintId = `${KEY_ID}-hint`;
  const keyErrorId = `${KEY_ID}-error`;

  return (
    <SettingsForm
      title={title}
      headingId={headingId}
      headingRef={headingRef}
      busy={status.kind === "saving"}
      dirty={dirty}
      status={status}
      editable
      onSave={() => void save()}
      onDiscard={() => {
        setDraft(draftOf(settings));
        setStatus({ kind: "idle" });
        setKeyError("");
      }}
      className="el-settings-section"
    >
      <SettingRow
        label={__("Anthropic API key", "mcpmate")}
        hint={
          settings.has_key && !draft.removeKey
            ? __(
                "A key is saved. It is never shown again; paste a new one to replace it. Chat bills every message to this key's Anthropic account.",
                "mcpmate",
              )
            : __(
                "Create one in the Claude Console under API keys. It is checked with Anthropic, then stored sealed; nobody can read it back, not even you.",
                "mcpmate",
              )
        }
        htmlFor={KEY_ID}
        hintId={keyHintId}
      >
        <input
          id={KEY_ID}
          type="password"
          className="el-input"
          autoComplete="off"
          spellCheck={false}
          value={draft.key}
          disabled={draft.removeKey}
          placeholder={
            settings.has_key
              ? __("Saved — paste a new key to replace it", "mcpmate")
              : "sk-ant-…"
          }
          aria-invalid={keyError ? true : undefined}
          aria-describedby={[keyHintId, keyError && keyErrorId]
            .filter(Boolean)
            .join(" ")}
          onChange={(event) => change({ key: event.target.value })}
        />
        <FieldError id={keyErrorId} text={keyError} />
        {settings.has_key && (
          <div>
            <Button
              variant="link"
              size="sm"
              onClick={() => change({ removeKey: !draft.removeKey, key: "" })}
            >
              {draft.removeKey
                ? __("Keep the saved key", "mcpmate")
                : __("Remove the saved key", "mcpmate")}
            </Button>
          </div>
        )}
      </SettingRow>

      <SettingRow
        label={__("Model", "mcpmate")}
        hint={__(
          "Every admin's chats use this model. Larger models cost more per message.",
          "mcpmate",
        )}
        htmlFor={MODEL_ID}
      >
        <select
          id={MODEL_ID}
          className="el-input el-native-select"
          value={draft.model}
          onChange={(event) => change({ model: event.target.value })}
        >
          {settings.models.map((model) => (
            <option key={model.id} value={model.id}>
              {model.label}
            </option>
          ))}
        </select>
      </SettingRow>

      <div className="el-setting-row">
        <Toggle
          label={__("Turn on Chat", "mcpmate")}
          description={__(
            "Adds Chat to the top bar for site admins. Each admin accepts a notice about costs before their first message, and gets their own grant on Connections.",
            "mcpmate",
          )}
          checked={draft.enabled && !draft.removeKey}
          disabled={
            draft.removeKey || (!settings.has_key && draft.key.trim() === "")
          }
          onChange={(enabled) => change({ enabled })}
        />
      </div>
    </SettingsForm>
  );
}

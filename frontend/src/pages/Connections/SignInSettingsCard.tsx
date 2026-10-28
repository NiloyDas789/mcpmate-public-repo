import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import LeaveDialog from "@components/LeaveDialog";
import Card from "@components/ui/Card";
import Notice from "@components/ui/Notice";
import Button from "@components/ui/Button";
import Skeleton from "@components/ui/Skeleton";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  AjaxRequestError,
  fetchPreferences,
  savePreferences,
} from "@services/api";
import { CONNECTION_QUERY_KEY, PREFERENCES_QUERY_KEY } from "@services/queries";
import { requestErrorText } from "@utils/errors";
import type {
  PreferenceKey,
  Preferences,
  PreferencesResponse,
} from "../../types/api";
import {
  changedKeys,
  changesFor,
  CONNECTION_KEYS,
  controlId,
  firstInvalid,
  validate,
} from "../Settings/logic";
import { LifetimeRow, SettingsForm, type SaveStatus } from "../Settings/rows";

const SAVED_MS = 5000;

const HEADING_ID = "el-connections-sign-in";

type Errors = Partial<Record<PreferenceKey, string>>;

/**
 * Token lifetimes and client registration (docs/ui.md §5.2): whether AI
 * apps may register themselves, how long an access token lasts, and after
 * how many days an approval must be given again. One form with Save
 * changes, checked with the server's rules (pages/Settings/logic.ts).
 */
export default function SignInSettingsCard({
  focusOnLoad = false,
}: {
  /** Move focus to the heading once the settings are shown (`?section=sign-in`). */
  focusOnLoad?: boolean;
}) {
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const preferences = useQuery({
    queryKey: PREFERENCES_QUERY_KEY,
    queryFn: fetchPreferences,
    staleTime: 0,
  });
  const saved = preferences.data?.preferences;
  const fixed = preferences.data?.fixed ?? {};
  const types = preferences.data?.change_types ?? [];
  const [draft, setDraft] = useState<Preferences | null>(null);
  const [serverErrors, setServerErrors] = useState<Errors>({});
  const [status, setStatus] = useState<SaveStatus>({ kind: "idle" });
  const timer = useRef<number>();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const title = __("Token lifetimes and client registration", "mcpmate");

  useEffect(() => {
    if (saved && draft === null) {
      setDraft(saved);
    }
  }, [saved, draft]);

  useEffect(() => () => window.clearTimeout(timer.current), []);

  // The loading card is replaced by the form: focus the heading that stays.
  const loaded = draft !== null;
  useEffect(() => {
    if (focusOnLoad && loaded) {
      headingRef.current?.focus();
    }
  }, [focusOnLoad, loaded]);

  const changed =
    saved && draft ? changedKeys(saved, draft, CONNECTION_KEYS) : [];
  const errors: Errors = {
    ...serverErrors,
    ...(draft ? validate(draft, changed, fixed, types) : {}),
  };

  const setStatusTo = (next: SaveStatus) => {
    window.clearTimeout(timer.current);
    setStatus(next);
    if (next.kind === "saved" || next.kind === "nothing") {
      timer.current = window.setTimeout(
        () => setStatus({ kind: "idle" }),
        SAVED_MS,
      );
    }
  };

  const change = <K extends PreferenceKey>(key: K, value: Preferences[K]) => {
    setDraft((current) => (current ? { ...current, [key]: value } : current));
    setServerErrors((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
    if (status.kind !== "saving") {
      setStatusTo({ kind: "idle" });
    }
  };

  const focusSetting = (key: PreferenceKey) =>
    window.requestAnimationFrame(() =>
      document.getElementById(controlId(key))?.focus(),
    );

  const save = async () => {
    if (!saved || !draft) {
      return;
    }
    if (changed.length === 0) {
      setStatusTo({ kind: "nothing" });
      announce(__("Nothing to save. Change a setting first.", "mcpmate"));
      return;
    }
    const invalid = validate(draft, changed, fixed, types);
    const count = Object.keys(invalid).length;
    if (count > 0) {
      setStatusTo({ kind: "invalid", count });
      focusSetting(firstInvalid(invalid, CONNECTION_KEYS) as PreferenceKey);
      return;
    }

    setStatusTo({ kind: "saving" });
    try {
      const response = await savePreferences(
        changesFor(saved, draft, CONNECTION_KEYS),
      );
      queryClient.setQueryData<PreferencesResponse>(
        PREFERENCES_QUERY_KEY,
        response,
      );
      setDraft(response.preferences);
      setStatusTo({ kind: "saved" });
      announce(__("Saved.", "mcpmate"));
      // The MCP URL card shows whether apps may register themselves.
      void queryClient.invalidateQueries({ queryKey: CONNECTION_QUERY_KEY });
    } catch (error) {
      const refused =
        error instanceof AjaxRequestError ? error.details?.errors : undefined;
      if (refused && Object.keys(refused).length > 0) {
        setServerErrors(refused as Errors);
        const first = firstInvalid(refused as Errors, CONNECTION_KEYS);
        if (first) {
          focusSetting(first);
        }
      }
      const message = requestErrorText(error);
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
    if (!saved) {
      return;
    }
    setDraft(saved);
    setServerErrors({});
    setStatusTo({ kind: "idle" });
    announce(__("Changes discarded.", "mcpmate"));
  };

  if (!draft) {
    return preferences.isError ? (
      <Card title={title} headingRef={headingRef} headingId={HEADING_ID}>
        <Notice
          tone="danger"
          title={__("Couldn't load these settings.", "mcpmate")}
          action={
            <Button
              variant="secondary"
              size="sm"
              onClick={() => void preferences.refetch()}
            >
              {__("Try again", "mcpmate")}
            </Button>
          }
        >
          {requestErrorText(preferences.error)}
        </Notice>
      </Card>
    ) : (
      <Card title={title} headingRef={headingRef} headingId={HEADING_ID} busy>
        <Skeleton count={3} />
      </Card>
    );
  }

  return (
    <>
      <SettingsForm
        title={title}
        headingId={HEADING_ID}
        headingRef={headingRef}
        busy={false}
        dirty={changed.length}
        status={status}
        editable
        onSave={() => void save()}
        onDiscard={discard}
      >
        <div className="el-setting-row">
          <Toggle
            label={__("Let AI apps register themselves", "mcpmate")}
            description={__(
              "Apps that register themselves show as not verified when you approve them. Apps with a published client ID document can always connect.",
              "mcpmate",
            )}
            checked={draft.open_registration}
            onChange={(on) => change("open_registration", on)}
          />
        </div>
        <LifetimeRow
          name="access_token_minutes"
          label={__("Access tokens last", "mcpmate")}
          hint={__(
            "An app renews its token after this, without asking you.",
            "mcpmate",
          )}
          value={draft.access_token_minutes}
          onChange={(minutes) => change("access_token_minutes", minutes)}
          error={errors.access_token_minutes}
        />
        <LifetimeRow
          name="refresh_token_days"
          label={__("Approve apps again after", "mcpmate")}
          hint={__(
            "Counted from your approval. Applies to apps you approve from now on.",
            "mcpmate",
          )}
          value={draft.refresh_token_days}
          onChange={(days) => change("refresh_token_days", days)}
          error={errors.refresh_token_days}
        />
      </SettingsForm>
      <LeaveDialog count={changed.length} />
    </>
  );
}

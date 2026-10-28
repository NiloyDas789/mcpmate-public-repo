import { useEffect, useRef, useState, type ComponentProps } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation, useNavigate } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import {
  AjaxRequestError,
  fetchPreferences,
  fetchTools,
  savePreferences,
  updateOnboarding,
} from "@services/api";
import {
  PREFERENCES_QUERY_KEY,
  STATS_QUERY_KEY,
  TOOLS_QUERY_KEY,
} from "@services/queries";
import PageHeader from "@components/AppShell/PageHeader";
import LeaveDialog from "@components/LeaveDialog";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import CheckboxGroup from "@components/ui/CheckboxGroup";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import Toggle from "@components/ui/Toggle";
import { useAnnounce } from "@/hooks/useAnnounce";
import { useContainerWidth } from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import { requestErrorText } from "@utils/errors";
import { formatNumber } from "@utils/format";
import { cx } from "@utils/classNames";
import type {
  PreferenceKey,
  Preferences,
  PreferencesResponse,
} from "../../types/api";
import { typeLabel } from "../Changes/logic";
import { SIGN_IN_HREF } from "../Connections/logic";
import {
  changedKeys,
  changesFor,
  controlId,
  daysText,
  fieldFor,
  firstInvalid,
  minutesText,
  RATE_LIMIT_CHOICES,
  RETENTION_CHOICES,
  SECTION_KEYS,
  SECTIONS,
  sectionFromParam,
  validate,
  type RetentionKey,
  type SectionId,
} from "./logic";
import {
  AlwaysOn,
  FieldError,
  SettingRow,
  SettingsForm,
  type SaveStatus,
} from "./rows";

const SAVED_MS = 5000;

const SECTION_TITLES: Record<SectionId, () => string> = {
  access: () => __("Access and safety", "mcpmate"),
  logging: () => __("Logging and history", "mcpmate"),
  advanced: () => __("Advanced", "mcpmate"),
};

type Errors = Partial<Record<PreferenceKey, string>>;

/** Access and safety: how many calls a minute each AI app may make. */
function RateLimitRow({
  value,
  onChange,
  error,
}: {
  value: number;
  onChange: (value: number) => void;
  error?: string;
}) {
  const id = controlId("rate_limit");
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const choices: readonly number[] = RATE_LIMIT_CHOICES;

  return (
    <SettingRow
      label={__("Calls per minute for each app", "mcpmate")}
      hint={__(
        "An app that sends more waits until the minute is over. It stays connected.",
        "mcpmate",
      )}
      htmlFor={id}
      hintId={hintId}
    >
      <select
        id={id}
        className="el-input el-native-select"
        value={String(value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={[hintId, error && errorId].filter(Boolean).join(" ")}
        onChange={(event) => onChange(Number(event.target.value))}
      >
        {(choices.includes(value) ? choices : [...choices, value]).map(
          (choice) => (
            <option key={choice} value={String(choice)}>
              {formatNumber(choice)}
            </option>
          ),
        )}
      </select>
      <FieldError id={errorId} text={error} />
    </SettingRow>
  );
}

/** A retention select; disabled, with the reason, when a filter sets it in code. */
function RetentionRow({
  name,
  label,
  hint,
  value,
  onChange,
  error,
  fixedDays,
}: {
  name: RetentionKey;
  label: string;
  hint?: string;
  value: number;
  onChange: (days: number) => void;
  error?: string;
  fixedDays?: number;
}) {
  const id = controlId(name);
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const choices: readonly number[] = RETENTION_CHOICES[name];
  const shown = fixedDays ?? value;
  const hintText =
    fixedDays !== undefined
      ? sprintf(
          /* translators: %s: "120 days". */
          __("Set by code on this site: %s.", "mcpmate"),
          daysText(fixedDays),
        )
      : hint;

  return (
    <SettingRow label={label} hint={hintText} htmlFor={id} hintId={hintId}>
      <select
        id={id}
        className="el-input el-native-select"
        value={String(shown)}
        disabled={fixedDays !== undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={
          [hintText && hintId, error && errorId].filter(Boolean).join(" ") ||
          undefined
        }
        onChange={(event) => onChange(Number(event.target.value))}
      >
        {(choices.includes(shown) ? choices : [...choices, shown]).map(
          (days) => (
            <option key={days} value={String(days)}>
              {daysText(days)}
            </option>
          ),
        )}
      </select>
      <FieldError id={errorId} text={error} />
    </SettingRow>
  );
}

// ── A section: its own form and Save changes ────

/** One section of the page: its own form and Save changes. */
function Section({
  id,
  ...form
}: Omit<
  ComponentProps<typeof SettingsForm>,
  "title" | "headingId" | "className"
> & { id: SectionId }) {
  return (
    <SettingsForm
      title={SECTION_TITLES[id]()}
      headingId={`el-settings-${id}`}
      className="el-settings-section"
      {...form}
    />
  );
}

// ── Page ────────────────────────────────────────

/**
 * Settings (`#/settings`; docs/ui.md §5.5): Access and safety, Logging and
 * history, and Advanced, each its own form with its own Save changes. Every
 * value is checked as it changes, with the server's rules (./logic.ts);
 * the server's own refusal is shown next to the setting it names. Leaving
 * with unsaved changes asks first. `?section=` moves to a section.
 */
export default function Settings() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const [widthRef, width] = useContainerWidth();
  const wide = width !== undefined && width >= breakpoints.lg;

  const preferences = useQuery({
    queryKey: PREFERENCES_QUERY_KEY,
    queryFn: fetchPreferences,
    staleTime: 0,
  });
  const tools = useQuery({
    queryKey: TOOLS_QUERY_KEY,
    queryFn: fetchTools,
    staleTime: 0,
  });
  const saved = preferences.data?.preferences;
  const fixed = preferences.data?.fixed ?? {};
  const types = preferences.data?.change_types ?? [];

  const [draft, setDraft] = useState<Preferences | null>(null);
  const [serverErrors, setServerErrors] = useState<Errors>({});
  const [status, setStatus] = useState<Record<SectionId, SaveStatus>>({
    access: { kind: "idle" },
    logging: { kind: "idle" },
    advanced: { kind: "idle" },
  });
  const [typesOpen, setTypesOpen] = useState(true);
  const [confirmOff, setConfirmOff] = useState(false);
  const headings = useRef<
    Partial<Record<SectionId, HTMLHeadingElement | null>>
  >({});
  const savedTimers = useRef<Partial<Record<SectionId, number>>>({});

  useEffect(() => {
    if (saved && draft === null) {
      setDraft(saved);
    }
  }, [saved, draft]);

  useEffect(
    () => () =>
      Object.values(savedTimers.current).forEach((timer) =>
        window.clearTimeout(timer),
      ),
    [],
  );

  // `?section=logging` moves to that section's heading once it is there.
  const section = sectionFromParam(new URLSearchParams(search).get("section"));
  const loaded = draft !== null;
  useEffect(() => {
    if (section && loaded) {
      headings.current[section]?.focus();
    }
  }, [section, loaded]);

  const changedIn = (id: SectionId) =>
    saved && draft ? changedKeys(saved, draft, SECTION_KEYS[id]) : [];
  const allChanged = SECTIONS.flatMap(changedIn);
  const clientErrors: Errors = draft
    ? validate(draft, allChanged, fixed, types)
    : {};
  const errors: Errors = { ...serverErrors, ...clientErrors };
  const errorFor = (key: PreferenceKey): string | undefined =>
    errors[key] ??
    (key === "change_history_types" ? errors.change_history : undefined);

  const setStatusOf = (id: SectionId, next: SaveStatus) => {
    window.clearTimeout(savedTimers.current[id]);
    setStatus((current) => ({ ...current, [id]: next }));
    if (next.kind === "saved" || next.kind === "nothing") {
      savedTimers.current[id] = window.setTimeout(
        () => setStatus((current) => ({ ...current, [id]: { kind: "idle" } })),
        SAVED_MS,
      );
    }
  };

  const change = <K extends PreferenceKey>(key: K, value: Preferences[K]) => {
    setDraft((current) => (current ? { ...current, [key]: value } : current));
    // The server's word on this setting no longer applies.
    setServerErrors((current) => {
      const next = { ...current };
      delete next[key];
      if (key === "change_history_types") {
        delete next.change_history;
      }
      return next;
    });
    const id = SECTIONS.find((each) => SECTION_KEYS[each].includes(key));
    if (id && status[id].kind !== "saving") {
      setStatusOf(id, { kind: "idle" });
    }
  };

  const focusSetting = (key: PreferenceKey) =>
    window.requestAnimationFrame(() =>
      document.getElementById(controlId(key))?.focus(),
    );

  const save = async (id: SectionId) => {
    if (!saved || !draft) {
      return;
    }
    const keys = changedIn(id);
    if (keys.length === 0) {
      setStatusOf(id, { kind: "nothing" });
      announce(__("Nothing to save. Change a setting first.", "mcpmate"));
      return;
    }
    const invalid = validate(draft, keys, fixed, types);
    const count = Object.keys(invalid).length;
    if (count > 0) {
      setStatusOf(id, { kind: "invalid", count });
      focusSetting(fieldFor(firstInvalid(invalid) as PreferenceKey));
      return;
    }

    setStatusOf(id, { kind: "saving" });
    try {
      const response = await savePreferences(changesFor(saved, draft, keys));
      queryClient.setQueryData<PreferencesResponse>(
        PREFERENCES_QUERY_KEY,
        response,
      );
      // This section now shows what the server stored; the others keep their edits.
      setDraft((current) =>
        current
          ? {
              ...current,
              ...Object.fromEntries(
                SECTION_KEYS[id].map((key) => [key, response.preferences[key]]),
              ),
            }
          : current,
      );
      setStatusOf(id, { kind: "saved" });
      announce(__("Saved.", "mcpmate"));
      void queryClient.invalidateQueries({ queryKey: STATS_QUERY_KEY });
    } catch (error) {
      const refused =
        error instanceof AjaxRequestError ? error.details?.errors : undefined;
      if (refused && Object.keys(refused).length > 0) {
        setServerErrors(refused as Errors);
        const first = firstInvalid(refused as Errors);
        if (first) {
          focusSetting(fieldFor(first));
        }
      }
      const message = requestErrorText(error);
      setStatusOf(id, { kind: "failed", message });
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

  const discard = (id: SectionId) => {
    if (!saved) {
      return;
    }
    setDraft((current) =>
      current
        ? {
            ...current,
            ...Object.fromEntries(
              SECTION_KEYS[id].map((key) => [key, saved[key]]),
            ),
          }
        : current,
    );
    setServerErrors((current) =>
      Object.fromEntries(
        Object.entries(current).filter(
          ([key]) => !SECTION_KEYS[id].includes(key as PreferenceKey),
        ),
      ),
    );
    setStatusOf(id, { kind: "idle" });
    announce(__("Changes discarded.", "mcpmate"));
  };

  const rerun = useMutation({
    mutationFn: () =>
      updateOnboarding({ step: "welcome", status: "in_progress" }),
    onSuccess: () => navigate("/setup"),
  });

  const sectionProps = (id: SectionId) => ({
    id,
    headingRef: (node: HTMLHeadingElement | null) => {
      headings.current[id] = node;
    },
    busy: !draft,
    dirty: changedIn(id).length,
    status: status[id],
    editable: SECTION_KEYS[id].length > 0,
    onSave: () => void save(id),
    onDiscard: () => discard(id),
  });

  const filteredDays = (key: RetentionKey) =>
    fixed[key]?.reason === "filtered" ? Number(fixed[key]?.value) : undefined;

  const loading = (
    <div aria-busy="true">
      <Skeleton count={3} />
    </div>
  );

  // ── Sections ──

  const access = draft && (
    <Section {...sectionProps("access")}>
      <SettingRow
        label={__("Who can approve AI apps", "mcpmate")}
        hint={__("Only administrators, for now.", "mcpmate")}
        htmlFor="el-setting-approve-capability"
        hintId="el-setting-approve-capability-hint"
      >
        <select
          id="el-setting-approve-capability"
          className="el-input el-native-select"
          value="manage_options"
          disabled
          aria-describedby="el-setting-approve-capability-hint"
        >
          <option value="manage_options">
            {__("Administrators", "mcpmate")}
          </option>
        </select>
      </SettingRow>
      <SettingRow
        label={__("New content is saved as a draft", "mcpmate")}
        hint={__(
          "McpMate never publishes. You review and publish drafts yourself.",
          "mcpmate",
        )}
      >
        <AlwaysOn />
      </SettingRow>
      <SettingRow
        label={__("What AI apps can do", "mcpmate")}
        hint={__("Choose tools on the Tools page.", "mcpmate")}
      >
        <span className="el-setting-inline">
          {tools.data &&
            sprintf(
              /* translators: 1: tools turned on, 2: all tools. */
              __("%1$s of %2$s tools on", "mcpmate"),
              formatNumber(tools.data.counts.enabled),
              formatNumber(tools.data.counts.total),
            )}
          <Button href="#/tools">{__("Manage tools", "mcpmate")}</Button>
        </span>
      </SettingRow>
      <SettingRow
        label={__("Token lifetimes and client registration", "mcpmate")}
        hint={__(
          "How long AI apps stay signed in, and whether they may register themselves. Set on the Connections page.",
          "mcpmate",
        )}
      >
        <span className="el-setting-inline">
          {sprintf(
            /* translators: 1: "15 minutes", 2: "30 days". */
            __("Tokens %1$s, approvals %2$s", "mcpmate"),
            minutesText(draft.access_token_minutes),
            daysText(draft.refresh_token_days),
          )}
          <Button href={SIGN_IN_HREF}>
            {__("Open Connections", "mcpmate")}
          </Button>
        </span>
      </SettingRow>
      <RateLimitRow
        value={draft.rate_limit}
        onChange={(limit) => change("rate_limit", limit)}
        error={errorFor("rate_limit")}
      />
    </Section>
  );

  const logging = draft && (
    <Section {...sectionProps("logging")}>
      <SettingRow
        label={__("Log write calls", "mcpmate")}
        hint={__(
          "Every change an AI app makes is logged. This can't be turned off.",
          "mcpmate",
        )}
      >
        <AlwaysOn />
      </SettingRow>
      <div className="el-setting-row">
        <Toggle
          label={__("Also log read calls", "mcpmate")}
          description={__(
            "Needed for the Dashboard numbers and recent activity.",
            "mcpmate",
          )}
          checked={draft.log_reads}
          onChange={(on) => change("log_reads", on)}
        />
      </div>
      <RetentionRow
        name="audit_retention_days"
        label={__("Keep the audit log for", "mcpmate")}
        value={draft.audit_retention_days}
        onChange={(days) => change("audit_retention_days", days)}
        error={errorFor("audit_retention_days")}
        fixedDays={filteredDays("audit_retention_days")}
      />
      <RetentionRow
        name="read_retention_days"
        label={__("Keep read calls for", "mcpmate")}
        hint={__("No longer than the audit log.", "mcpmate")}
        value={draft.read_retention_days}
        onChange={(days) => change("read_retention_days", days)}
        error={errorFor("read_retention_days")}
        fixedDays={filteredDays("read_retention_days")}
      />
      <div className="el-setting-row">
        <Toggle
          label={__("Change history", "mcpmate")}
          description={__(
            "Keeps a before and after copy of everything an AI app changes.",
            "mcpmate",
          )}
          checked={draft.change_history}
          onChange={(on) =>
            on ? change("change_history", true) : setConfirmOff(true)
          }
        />
      </div>
      {draft.change_history && (
        <div className="el-setting-row el-setting-nested">
          <CheckboxGroup
            id={controlId("change_history_types")}
            label={__("Record changes to", "mcpmate")}
            description={sprintf(
              /* translators: 1: kinds chosen, 2: all kinds. */
              __("%1$s of %2$s kinds", "mcpmate"),
              formatNumber(draft.change_history_types.length),
              formatNumber(types.length),
            )}
            expanded={typesOpen}
            onExpandedChange={setTypesOpen}
            error={errorFor("change_history_types")}
            items={types.map((type) => ({
              key: type,
              checked: draft.change_history_types.includes(type),
              label: typeLabel(type),
            }))}
            onChange={(next) =>
              change(
                "change_history_types",
                types.filter((type) =>
                  type in next
                    ? next[type]
                    : draft.change_history_types.includes(type),
                ),
              )
            }
          />
        </div>
      )}
      <RetentionRow
        name="change_retention_days"
        label={__("Keep change history for", "mcpmate")}
        value={draft.change_retention_days}
        onChange={(days) => change("change_retention_days", days)}
        error={errorFor("change_retention_days")}
        fixedDays={filteredDays("change_retention_days")}
      />
    </Section>
  );

  const advanced = draft && (
    <Section {...sectionProps("advanced")}>
      <SettingRow
        label={__("Setup", "mcpmate")}
        hint={__(
          "Walks you through checks, safety choices and adding your AI app. Changes nothing until you choose to.",
          "mcpmate",
        )}
      >
        <Button loading={rerun.isPending} onClick={() => rerun.mutate()}>
          {__("Run setup again", "mcpmate")}
        </Button>
      </SettingRow>
      {rerun.isError && (
        <Notice
          tone="danger"
          title={__("Couldn't start setup.", "mcpmate")}
          live
        >
          {requestErrorText(rerun.error)}
        </Notice>
      )}
      <div className="el-setting-row">
        <Toggle
          label={__("Remove all data on uninstall", "mcpmate")}
          description={__(
            "Deletes McpMate's settings, audit log, change history and AI app approvals from this site when you delete the plugin. Every AI app loses access. Theme drafts and theme backups stay in place.",
            "mcpmate",
          )}
          checked={draft.delete_data_on_uninstall}
          onChange={(on) => change("delete_data_on_uninstall", on)}
        />
      </div>
    </Section>
  );

  const body = () => {
    if (preferences.isError && !draft) {
      return (
        <Notice
          tone="danger"
          title={__("Couldn't load your settings.", "mcpmate")}
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
      );
    }

    return (
      <div className="el-stack">
        {access || (
          <Card title={SECTION_TITLES.access()} busy>
            {loading}
          </Card>
        )}
        {logging || (
          <Card title={SECTION_TITLES.logging()} busy>
            {loading}
          </Card>
        )}
        {advanced || (
          <Card title={SECTION_TITLES.advanced()} busy>
            {loading}
          </Card>
        )}
      </div>
    );
  };

  return (
    <>
      <PageHeader
        title={__("Settings", "mcpmate")}
        intro={__(
          "How McpMate protects your site, and what it keeps a record of.",
          "mcpmate",
        )}
      />
      <div ref={widthRef} className={cx("el-settings", wide && "is-wide")}>
        {wide && (
          <nav
            aria-label={__("On this page", "mcpmate")}
            className="el-settings-nav"
          >
            <p className="el-settings-nav-title" aria-hidden="true">
              {__("On this page", "mcpmate")}
            </p>
            <ul>
              {SECTIONS.map((id) => (
                <li key={id}>
                  <Link
                    to={{ search: `?section=${id}` }}
                    replace
                    aria-current={section === id ? "location" : undefined}
                    onClick={() => headings.current[id]?.focus()}
                  >
                    {SECTION_TITLES[id]()}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        )}
        <div className="el-settings-sections">{body()}</div>
      </div>

      <ConfirmDialog
        open={confirmOff}
        tone="danger"
        title={__("Turn off change history?", "mcpmate")}
        body={
          <p>
            {sprintf(
              /* translators: %s: "30 days". */
              __(
                "New changes won't be recorded. Snapshots you already have stay until they expire after %s.",
                "mcpmate",
              ),
              daysText(
                filteredDays("change_retention_days") ??
                  saved?.change_retention_days ??
                  30,
              ),
            )}
          </p>
        }
        confirmLabel={__("Turn off change history", "mcpmate")}
        cancelLabel={__("Keep it on", "mcpmate")}
        onConfirm={() => change("change_history", false)}
        onClose={() => setConfirmOff(false)}
      />
      <LeaveDialog count={allChanged.length} />
    </>
  );
}

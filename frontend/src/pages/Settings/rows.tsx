import type { FormEvent, ReactNode, Ref } from "react";
import { LockOutlined } from "@ant-design/icons";
import { __, _n, sprintf } from "@wordpress/i18n";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import Notice from "@components/ui/Notice";
import { formatNumber } from "@utils/format";
import { cx } from "@utils/classNames";
import {
  controlId,
  daysText,
  LIFETIME_CHOICES,
  minutesText,
  type LifetimeKey,
} from "./logic";

/**
 * The parts of a settings form (docs/ui.md §5.5): rows, the token lifetime
 * select, and a card that is one form with its own save bar. Settings uses
 * them for its sections; Connections for "Token lifetimes and client
 * registration".
 */

// ── Rows ────────────────────────────────────────

export function SettingRow({
  label,
  hint,
  htmlFor,
  hintId,
  children,
}: {
  label: string;
  hint?: ReactNode;
  /** The control the label names; without one the label is plain text. */
  htmlFor?: string;
  hintId?: string;
  children: ReactNode;
}) {
  return (
    <div className="el-setting-row">
      <div className="el-setting-text">
        {htmlFor ? (
          <label htmlFor={htmlFor} className="el-setting-label">
            {label}
          </label>
        ) : (
          <p className="el-setting-label">{label}</p>
        )}
        {hint && (
          <p id={hintId} className="el-setting-hint">
            {hint}
          </p>
        )}
      </div>
      <div className="el-setting-control">{children}</div>
    </div>
  );
}

export function AlwaysOn() {
  return (
    <span className="el-always-on">
      <LockOutlined aria-hidden /> {__("Always on", "mcpmate")}
    </span>
  );
}

export function FieldError({ id, text }: { id: string; text?: string }) {
  return text ? (
    <p id={id} className="el-field-error">
      {text}
    </p>
  ) : null;
}

/** A token lifetime select: minutes per access token, days per approval. */
export function LifetimeRow({
  name,
  label,
  hint,
  value,
  onChange,
  error,
}: {
  name: LifetimeKey;
  label: string;
  hint: string;
  value: number;
  onChange: (value: number) => void;
  error?: string;
}) {
  const id = controlId(name);
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const choices: readonly number[] = LIFETIME_CHOICES[name];
  const text = name === "access_token_minutes" ? minutesText : daysText;

  return (
    <SettingRow label={label} hint={hint} htmlFor={id} hintId={hintId}>
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
              {text(choice)}
            </option>
          ),
        )}
      </select>
      <FieldError id={errorId} text={error} />
    </SettingRow>
  );
}

// ── A card that is one form, with Save changes ──

export type SaveStatus =
  | { kind: "idle" }
  | { kind: "saving" }
  | { kind: "saved" }
  | { kind: "nothing" }
  | { kind: "invalid"; count: number }
  | { kind: "failed"; message: string };

export function SettingsForm({
  title,
  headingId,
  headingRef,
  busy,
  dirty,
  status,
  editable,
  onSave,
  onDiscard,
  className,
  children,
}: {
  title: string;
  headingId: string;
  headingRef?: Ref<HTMLHeadingElement>;
  busy: boolean;
  dirty: number;
  status: SaveStatus;
  /** Whether the form has anything Save changes can send. */
  editable: boolean;
  onSave: () => void;
  onDiscard: () => void;
  className?: string;
  children: ReactNode;
}) {
  const statusText =
    status.kind === "saved"
      ? __("Saved.", "mcpmate")
      : status.kind === "nothing"
        ? __("Nothing to save. Change a setting first.", "mcpmate")
        : dirty > 0
          ? sprintf(
              /* translators: %s: number of settings changed and not saved. */
              _n("%s unsaved change", "%s unsaved changes", dirty, "mcpmate"),
              formatNumber(dirty),
            )
          : __("No unsaved changes.", "mcpmate");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSave();
  };

  return (
    <Card
      title={title}
      headingRef={headingRef}
      headingId={headingId}
      busy={busy}
      className={className}
    >
      <form noValidate aria-labelledby={headingId} onSubmit={submit}>
        {children}
        {status.kind === "failed" && (
          <Notice
            tone="danger"
            title={__("Couldn't save. Nothing was changed.", "mcpmate")}
          >
            {status.message}
          </Notice>
        )}
        {status.kind === "invalid" && (
          <Notice
            tone="danger"
            title={sprintf(
              /* translators: %s: number of settings that can't be saved. */
              _n(
                "Fix %s setting to save.",
                "Fix %s settings to save.",
                status.count,
                "mcpmate",
              ),
              formatNumber(status.count),
            )}
          />
        )}
        {editable && (
          <div className={cx("el-save-bar", dirty > 0 && "is-dirty")}>
            <p className="el-save-bar-status">{statusText}</p>
            <div className="el-save-bar-actions">
              {dirty > 0 && (
                <Button
                  variant="secondary"
                  onClick={onDiscard}
                  disabled={status.kind === "saving"}
                >
                  {__("Discard", "mcpmate")}
                </Button>
              )}
              <Button
                variant="primary"
                htmlType="submit"
                loading={status.kind === "saving"}
              >
                {status.kind === "saving"
                  ? __("Saving…", "mcpmate")
                  : __("Save changes", "mcpmate")}
              </Button>
            </div>
          </div>
        )}
      </form>
    </Card>
  );
}

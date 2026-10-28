import type { ReactNode } from "react";
import { useId } from "react";
import { Switch, Typography } from "antd";
import { __, sprintf } from "@wordpress/i18n";

const { Text } = Typography;

export type SwitchRowStatus = "saving" | "saved" | "error" | null;

interface FeatureSwitchRowProps {
  /** Visible label. */
  label: string;
  /** One line on what the switch allows. */
  note?: ReactNode;
  checked: boolean;
  onChange: (on: boolean) => void;
  /** Accessible name of the switch; defaults to the label. */
  switchLabel?: string;
  loading?: boolean;
  disabled?: boolean;
  /** Shown as "Not available: {reason}" and disables the switch. */
  unavailable?: string;
  status?: SwitchRowStatus;
  /** Replaces the switch (e.g. "Custom (3 on)" with a link). */
  control?: ReactNode;
  children?: ReactNode;
}

/**
 * One feature switch with its label and note: the same row in Settings and
 * in the setup wizard. Every state is a word, never a colour only.
 */
export default function FeatureSwitchRow({
  label,
  note,
  checked,
  onChange,
  switchLabel,
  loading = false,
  disabled = false,
  unavailable,
  status = null,
  control,
  children,
}: FeatureSwitchRowProps) {
  const noteId = useId();

  return (
    <div className="flex items-start gap-3 py-2">
      <div className="pt-0.5">
        {control ?? (
          <Switch
            checked={checked}
            loading={loading}
            disabled={disabled || Boolean(unavailable)}
            onChange={onChange}
            aria-label={switchLabel ?? label}
            aria-describedby={note || unavailable ? noteId : undefined}
          />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <Text strong>{label}</Text>
        <div id={noteId}>
          {note && <Text type="secondary">{note}</Text>}
          {unavailable && (
            <div>
              <Text type="secondary">
                {sprintf(
                  /* translators: %s: why the feature cannot be used on this site. */
                  __("Not available: %s", "mcpmate"),
                  unavailable,
                )}
              </Text>
            </div>
          )}
        </div>
        {status === "saving" && (
          <Text type="secondary">{__("Saving…", "mcpmate")}</Text>
        )}
        {status === "saved" && (
          <Text type="success">{__("Saved", "mcpmate")}</Text>
        )}
        {status === "error" && (
          <Text type="danger">
            {__("Couldn't save. Try again.", "mcpmate")}
          </Text>
        )}
        {children}
      </div>
    </div>
  );
}

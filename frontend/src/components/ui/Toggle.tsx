import { useId, type ReactNode } from "react";
import { Switch } from "antd";
import { __ } from "@wordpress/i18n";
import { cx } from "@utils/classNames";

interface ToggleProps {
  label: string;
  description?: ReactNode;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  loading?: boolean;
}

/**
 * A switch with its label on the inline-start side and an optional
 * description under it (docs/ui.md §7). The label names the `role="switch"`
 * button; "On" or "Off" next to the knob says the state in words too.
 */
export default function Toggle({
  label,
  description,
  checked,
  onChange,
  disabled = false,
  loading = false,
}: ToggleProps) {
  const id = useId();
  const descriptionId = `${id}-description`;

  return (
    <div className={cx("el-toggle", disabled && "is-disabled")}>
      <div className="el-toggle-text">
        <label htmlFor={id} className="el-toggle-label">
          {label}
        </label>
        {description && (
          <p id={descriptionId} className="el-toggle-description">
            {description}
          </p>
        )}
      </div>
      <div className="el-toggle-control">
        <Switch
          id={id}
          checked={checked}
          onChange={onChange}
          disabled={disabled}
          loading={loading}
          aria-describedby={description ? descriptionId : undefined}
        />
        <span className="el-toggle-state" aria-hidden="true">
          {checked ? __("On", "mcpmate") : __("Off", "mcpmate")}
        </span>
      </div>
    </div>
  );
}

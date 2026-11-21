import { useEffect, useId, useState } from "react";
import { cx } from "@utils/classNames";

export interface SegmentOption<T extends string> {
  value: T;
  label: string;
}

interface SegmentedControlProps<T extends string> {
  /** Visible label of the group ("Show"). */
  label: string;
  options: SegmentOption<T>[];
  value: T;
  onChange: (value: T) => void;
}

/**
 * A radio group drawn as segments (docs/ui.md §7). Native radios, so arrow
 * keys move and select and the group is one Tab stop. antd `Segmented`
 * names its group "segmented control" in English, whatever the label.
 *
 * The choice is shown at once, before `value` catches up: a parent that
 * keeps it in the URL updates in a router transition, and until then React
 * would put the old radio back and miss the next arrow key's change.
 */
export default function SegmentedControl<T extends string>({
  label,
  options,
  value,
  onChange,
}: SegmentedControlProps<T>) {
  const id = useId();
  const labelId = `${id}-label`;
  const [shown, setShown] = useState(value);

  useEffect(() => setShown(value), [value]);

  return (
    <div className="el-segmented-field">
      <span id={labelId} className="el-segmented-label">
        {label}
      </span>
      <div role="radiogroup" aria-labelledby={labelId} className="el-segmented">
        {options.map((option) => (
          <label
            key={option.value}
            className={cx("el-segment", option.value === shown && "is-checked")}
          >
            <input
              type="radio"
              name={id}
              value={option.value}
              checked={option.value === shown}
              onChange={() => {
                setShown(option.value);
                onChange(option.value);
              }}
              className="el-segment-input"
            />
            <span className="el-segment-text">{option.label}</span>
          </label>
        ))}
      </div>
    </div>
  );
}

import { useId, type ReactNode } from "react";
import { DownOutlined } from "@ant-design/icons";
import { Checkbox } from "antd";
import { __ } from "@wordpress/i18n";
import { interpolate } from "@utils/i18n";
import {
  groupState,
  toggleGroup,
  type CheckboxGroupItem as ItemState,
} from "./checkboxGroupLogic";

export interface CheckboxGroupItem extends ItemState {
  label: ReactNode;
  /** After the label: the mono tool name, badges. */
  meta?: ReactNode;
  /** Visible reason for a disabled item ("Always on"). */
  note?: ReactNode;
  /** A sentence under the item; describes its checkbox. */
  description?: ReactNode;
}

interface CheckboxGroupProps {
  /** The group's name: the legend, and the group checkbox's label. */
  label: string;
  /** "12 of 14 on · 8 read, 6 write": describes the group checkbox. */
  description?: string;
  /** After the name, e.g. the mono tool name of a one-tool group. */
  extra?: ReactNode;
  items: CheckboxGroupItem[];
  /** Receives the new state of every item that changed. */
  onChange: (changes: Record<string, boolean>) => void;
  expanded: boolean;
  onExpandedChange: (expanded: boolean) => void;
  /** Settings of the group that are not tools (indented, under the items). */
  option?: ReactNode;
  /** No checkbox reacts (an unavailable group). */
  disabled?: boolean;
  /** Why the choice can't be saved; describes the group checkbox, which is then invalid. */
  error?: string;
  /** The group checkbox's id, for a page that moves focus to it. */
  id?: string;
}

/**
 * A group checkbox (all, mixed, none) over child checkboxes in a disclosure
 * (docs/ui.md §7). The group checkbox sits in the `<legend>`, so it names the
 * fieldset; Show and Hide is a separate button, never the same control.
 */
export default function CheckboxGroup({
  label,
  description,
  extra,
  items,
  onChange,
  expanded,
  onExpandedChange,
  option,
  disabled = false,
  error,
  id: inputId,
}: CheckboxGroupProps) {
  const id = useId();
  const listId = `${id}-items`;
  const descriptionId = `${id}-description`;
  const errorId = `${id}-error`;
  const describedBy =
    [description && descriptionId, error && errorId]
      .filter(Boolean)
      .join(" ") || undefined;
  const state = groupState(items);

  const changed = (next: Record<string, boolean>) =>
    Object.fromEntries(
      Object.entries(next).filter(
        ([key, on]) => items.find((item) => item.key === key)?.checked !== on,
      ),
    );

  return (
    <fieldset className="el-checkbox-group">
      <legend className="el-checkbox-group-legend">
        <Checkbox
          checked={state === "all"}
          indeterminate={state === "some"}
          aria-checked={state === "some" ? "mixed" : undefined}
          disabled={disabled}
          id={inputId}
          aria-describedby={describedBy}
          aria-invalid={error ? true : undefined}
          onChange={() => onChange(changed(toggleGroup(items)))}
        >
          <span className="el-checkbox-group-name">{label}</span>
        </Checkbox>
      </legend>
      {error && (
        <p id={errorId} className="el-field-error">
          {error}
        </p>
      )}
      <div className="el-checkbox-group-head">
        {extra}
        {description && (
          <span id={descriptionId} className="el-checkbox-group-counts">
            {description}
          </span>
        )}
        <button
          type="button"
          className="el-disclosure"
          aria-expanded={expanded}
          aria-controls={listId}
          onClick={() => onExpandedChange(!expanded)}
        >
          {interpolate(
            expanded
              ? /* translators: %s: group name, read by screen readers only. */
                __("Hide %s", "mcpmate")
              : /* translators: %s: group name, read by screen readers only. */
                __("Show %s", "mcpmate"),
            [
              <span className="sr-only" key="name">
                {label}
              </span>,
            ],
          )}
          <DownOutlined aria-hidden className="el-disclosure-icon" />
        </button>
      </div>
      <div id={listId} hidden={!expanded} className="el-checkbox-group-items">
        <ul>
          {items.map((item, index) => {
            const itemDescriptionId = `${id}-item-${index}-description`;

            return (
              <li key={item.key} className="el-checkbox-item">
                <Checkbox
                  checked={item.checked}
                  disabled={disabled || item.disabled}
                  aria-describedby={
                    item.description ? itemDescriptionId : undefined
                  }
                  onChange={(event) =>
                    onChange({ [item.key]: event.target.checked })
                  }
                >
                  {item.label}
                </Checkbox>
                {item.meta && (
                  <span className="el-checkbox-item-meta">{item.meta}</span>
                )}
                {item.note && (
                  <span className="el-checkbox-item-note">{item.note}</span>
                )}
                {item.description && (
                  <p
                    id={itemDescriptionId}
                    className="el-checkbox-item-description"
                  >
                    {item.description}
                  </p>
                )}
              </li>
            );
          })}
        </ul>
        {option && <div className="el-checkbox-group-option">{option}</div>}
      </div>
    </fieldset>
  );
}

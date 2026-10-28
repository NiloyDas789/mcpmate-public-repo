import { useEffect, useId, useRef, useState } from "react";
import { DownOutlined } from "@ant-design/icons";
import { Input, Select } from "antd";
import { __, _n, sprintf } from "@wordpress/i18n";
import {
  isNarrow,
  useContainerWidth,
  type Layout,
} from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import Button from "./Button";
import { cx } from "@utils/classNames";

export type FilterField =
  | { key: string; type: "search"; label: string }
  | {
      key: string;
      type: "select";
      label: string;
      options: { value: string; label: string }[];
      /** The empty choice; "Any" by default. */
      anyLabel?: string;
    }
  | { key: string; type: "date"; label: string };

export type FilterValues = Record<string, string>;

interface FilterBarProps {
  fields: FilterField[];
  /** '' (or missing) means the filter is off. */
  values: FilterValues;
  /** Write it to the hash query, then announce the result count. */
  onChange: (values: FilterValues) => void;
  /** Below 782 px the controls fold behind a "Filters (2)" button. */
  layout?: Layout;
}

const SEARCH_DELAY_MS = 400;

function SearchField({
  id,
  label,
  value,
  onApply,
}: {
  id: string;
  label: string;
  value: string;
  onApply: (value: string) => void;
}) {
  const [text, setText] = useState(value);
  const applied = useRef(value);
  const timer = useRef<number | undefined>(undefined);
  const cancel = () => window.clearTimeout(timer.current);

  // A value set from outside (Clear filters, Back) replaces the text and
  // whatever search was waiting; our own search coming back changes nothing.
  useEffect(() => {
    if (value === applied.current) {
      return;
    }
    window.clearTimeout(timer.current);
    setText(value);
    applied.current = value;
  }, [value]);

  useEffect(() => {
    if (text === applied.current) {
      return;
    }
    timer.current = window.setTimeout(() => {
      applied.current = text;
      onApply(text);
    }, SEARCH_DELAY_MS);

    return () => window.clearTimeout(timer.current);
  }, [text, onApply]);

  return (
    <div className="el-filter">
      <label htmlFor={id} className="el-field-label">
        {label}
      </label>
      <Input
        id={id}
        type="search"
        value={text}
        onChange={(event) => setText(event.target.value)}
        onPressEnter={() => {
          // Applied now: the pause must not apply it a second time later.
          cancel();
          applied.current = text;
          onApply(text);
        }}
      />
    </div>
  );
}

/**
 * Labelled filters over a list (docs/ui.md §7): selects apply on change,
 * search on Enter or after a 400 ms pause, and nothing moves focus. Shows
 * how many filters are on, with Clear filters.
 */
export default function FilterBar({
  fields,
  values,
  onChange,
  layout = "auto",
}: FilterBarProps) {
  const id = useId();
  const [widthRef, width] = useContainerWidth();
  const narrow = isNarrow(layout, width, breakpoints.md);
  const [open, setOpen] = useState(false);
  const active = fields.filter((field) => values[field.key]).length;
  const controlsId = `${id}-controls`;

  const set = (key: string, value: string) =>
    onChange({ ...values, [key]: value });
  // SearchField keeps its callback in an effect; a stable one per key.
  const setRef = useRef(set);
  setRef.current = set;
  const searchAppliers = useRef<Record<string, (value: string) => void>>({});
  const applierFor = (key: string) =>
    (searchAppliers.current[key] ??= (value) => setRef.current(key, value));

  const controls = fields.map((field) => {
    const fieldId = `${id}-${field.key}`;

    if (field.type === "search") {
      return (
        <SearchField
          key={field.key}
          id={fieldId}
          label={field.label}
          value={values[field.key] ?? ""}
          onApply={applierFor(field.key)}
        />
      );
    }

    if (field.type === "date") {
      return (
        <div key={field.key} className="el-filter">
          <label htmlFor={fieldId} className="el-field-label">
            {field.label}
          </label>
          <input
            id={fieldId}
            type="date"
            className="el-input"
            value={values[field.key] ?? ""}
            onChange={(event) => set(field.key, event.target.value)}
          />
        </div>
      );
    }

    return (
      <div key={field.key} className="el-filter">
        <label htmlFor={fieldId} className="el-field-label">
          {field.label}
        </label>
        <Select
          id={fieldId}
          value={values[field.key] ?? ""}
          onChange={(value: string) => set(field.key, value)}
          options={[
            { value: "", label: field.anyLabel ?? __("Any", "mcpmate") },
            ...field.options,
          ]}
          popupMatchSelectWidth={false}
          className="el-select"
        />
      </div>
    );
  });

  const summary = active > 0 && (
    <div className="el-filter-summary">
      <span>
        {sprintf(
          /* translators: %d: number of filters that are on. */
          _n("%d filter active", "%d filters active", active, "mcpmate"),
          active,
        )}
      </span>
      <Button
        variant="link"
        size="sm"
        onClick={() =>
          onChange(Object.fromEntries(fields.map((field) => [field.key, ""])))
        }
      >
        {__("Clear filters", "mcpmate")}
      </Button>
    </div>
  );

  return (
    <div ref={widthRef} className={cx("el-filter-bar", narrow && "is-narrow")}>
      {narrow && (
        <button
          type="button"
          className="el-disclosure el-filter-toggle"
          aria-expanded={open}
          aria-controls={controlsId}
          onClick={() => setOpen(!open)}
        >
          {active > 0
            ? sprintf(
                /* translators: %d: number of filters that are on. */
                __("Filters (%d)", "mcpmate"),
                active,
              )
            : __("Filters", "mcpmate")}
          <DownOutlined aria-hidden className="el-disclosure-icon" />
        </button>
      )}
      <div
        id={controlsId}
        className="el-filter-controls"
        hidden={narrow && !open}
      >
        {controls}
      </div>
      {summary}
    </div>
  );
}

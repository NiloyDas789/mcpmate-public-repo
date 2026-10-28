import { useEffect, useId, useRef, useState } from "react";
import { CheckOutlined, CopyOutlined } from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  isNarrow,
  useContainerWidth,
  type Layout,
} from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import { copyText, selectContents } from "@utils/clipboard";
import Button from "./Button";
import { cx } from "@utils/classNames";

interface CopyFieldProps {
  value: string;
  /** Visible label, and the start of the announcement ("MCP URL copied."). */
  label: string;
  /** Accessible name of the button; "Copy <label>" by default. */
  copyLabel?: string;
  mono?: boolean;
  /** Below 600 px the value wraps in a textarea instead of being cut off. */
  layout?: Layout;
}

const COPIED_MS = 3000;

/**
 * A read-only value with a Copy button (docs/ui.md §7). On success the
 * button reads "Copied" for 3 s and the live region says so; without the
 * Clipboard API (plain http) the text is selected for Ctrl+C instead.
 */
export default function CopyField({
  value,
  label,
  copyLabel,
  mono = true,
  layout = "auto",
}: CopyFieldProps) {
  const id = useId();
  const announce = useAnnounce();
  const field = useRef<HTMLInputElement & HTMLTextAreaElement>(null);
  const [copied, setCopied] = useState(false);
  // No Clipboard API (plain http): the text is selected, and this says what to press.
  const [manual, setManual] = useState(false);
  const [widthRef, width] = useContainerWidth();
  const narrow = isNarrow(layout, width, breakpoints.sm);

  useEffect(() => {
    if (!copied) {
      return;
    }
    const timer = window.setTimeout(() => setCopied(false), COPIED_MS);

    return () => window.clearTimeout(timer);
  }, [copied]);

  const copy = async () => {
    if (await copyText(value)) {
      setManual(false);
      setCopied(true);
      announce(
        sprintf(
          /* translators: %s: what was copied, e.g. "MCP URL". */
          __("%s copied.", "mcpmate"),
          label,
        ),
      );
      return;
    }

    selectContents(field.current);
    setManual(true);
    announce(__("Press Ctrl+C (⌘C on Mac) to copy.", "mcpmate"));
  };

  const fieldProps = {
    id,
    ref: field,
    readOnly: true,
    value,
    dir: mono ? ("ltr" as const) : undefined,
    className: `el-input el-copy-input${mono ? " el-mono" : ""}`,
    "aria-describedby": manual ? `${id}-hint` : undefined,
    onFocus: (event: { target: HTMLInputElement | HTMLTextAreaElement }) =>
      event.target.select(),
  };

  return (
    <div ref={widthRef} className="el-copy">
      <label htmlFor={id} className="el-field-label">
        {label}
      </label>
      <div className={cx("el-copy-row", narrow && "is-narrow")}>
        {narrow ? (
          <textarea
            {...fieldProps}
            rows={Math.min(6, Math.max(1, Math.ceil(value.length / 28)))}
          />
        ) : (
          <input type="text" {...fieldProps} />
        )}
        <Button
          variant="secondary"
          icon={
            copied ? (
              <CheckOutlined aria-hidden />
            ) : (
              <CopyOutlined aria-hidden />
            )
          }
          label={
            copied
              ? __("Copied", "mcpmate")
              : (copyLabel ??
                sprintf(
                  /* translators: %s: what the button copies, e.g. "MCP URL". */
                  __("Copy %s", "mcpmate"),
                  label,
                ))
          }
          onClick={() => void copy()}
        >
          {copied ? __("Copied", "mcpmate") : __("Copy", "mcpmate")}
        </Button>
      </div>
      {manual && (
        <p id={`${id}-hint`} className="el-setting-hint">
          {__(
            "Your browser didn't let McpMate copy it. The text is selected: press Ctrl+C (⌘C on Mac) to copy.",
            "mcpmate",
          )}
        </p>
      )}
    </div>
  );
}

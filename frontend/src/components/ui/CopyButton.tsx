import { useEffect, useState, type RefObject } from "react";
import { CheckOutlined, CopyOutlined } from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import { useAnnounce } from "@/hooks/useAnnounce";
import { copyText, selectContents } from "@utils/clipboard";
import Button from "./Button";

interface CopyButtonProps {
  /** The text to copy. */
  value: string;
  /** What is copied, for the button's name and the announcement ("Playbook"). */
  label: string;
  /** Accessible name of the button; "Copy <label>" by default. */
  copyLabel?: string;
  /** Where the text is shown: selected for Ctrl+C when the Clipboard API is missing. */
  target?: RefObject<HTMLElement>;
}

const COPIED_MS = 3000;

/**
 * A Copy button for text shown elsewhere on the page (a playbook, a code
 * block). As CopyField: "Copied" for 3 s and announced; without the
 * Clipboard API (plain http) the target is selected and the live region
 * says what to press.
 */
export default function CopyButton({
  value,
  label,
  copyLabel,
  target,
}: CopyButtonProps) {
  const announce = useAnnounce();
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) {
      return;
    }
    const timer = window.setTimeout(() => setCopied(false), COPIED_MS);

    return () => window.clearTimeout(timer);
  }, [copied]);

  const copy = async () => {
    if (await copyText(value)) {
      setCopied(true);
      announce(
        sprintf(
          /* translators: %s: what was copied, e.g. "Playbook". */
          __("%s copied.", "mcpmate"),
          label,
        ),
      );
      return;
    }

    selectContents(target?.current ?? null);
    announce(__("Press Ctrl+C (⌘C on Mac) to copy.", "mcpmate"));
  };

  return (
    <Button
      variant="secondary"
      size="sm"
      icon={
        copied ? <CheckOutlined aria-hidden /> : <CopyOutlined aria-hidden />
      }
      label={
        copied
          ? __("Copied", "mcpmate")
          : (copyLabel ??
            sprintf(
              /* translators: %s: what the button copies, e.g. "Playbook". */
              __("Copy %s", "mcpmate"),
              label,
            ))
      }
      onClick={() => void copy()}
    >
      {copied ? __("Copied", "mcpmate") : __("Copy", "mcpmate")}
    </Button>
  );
}

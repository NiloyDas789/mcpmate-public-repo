import { useRef } from "react";
import { CopyOutlined } from "@ant-design/icons";
import { Button, message } from "antd";
import { __ } from "@wordpress/i18n";
import { copyText, selectContents } from "@utils/clipboard";

interface CopyCodeProps {
  code: string;
  /** Accessible name of the Copy button, e.g. "Copy command". */
  label?: string;
  onCopied?: (text: string) => void;
}

/**
 * A command or snippet to copy, with its own Copy button (selects the text
 * when the Clipboard API is missing).
 */
export default function CopyCode({ code, label, onCopied }: CopyCodeProps) {
  const block = useRef<HTMLElement>(null);

  const copy = async () => {
    const copied = await copyText(code);

    if (!copied) {
      selectContents(block.current);
    }

    const text = copied
      ? __("Copied.", "mcpmate")
      : __("Press Ctrl+C (⌘C on Mac) to copy.", "mcpmate");
    message[copied ? "success" : "info"](text);
    onCopied?.(text);
  };

  return (
    <div className="mt-1 flex items-start gap-2">
      <code
        ref={block}
        className="block flex-1 overflow-x-auto whitespace-pre-wrap break-all rounded bg-[#f6f7f7] px-2 py-1 text-xs"
      >
        {code}
      </code>
      <Button
        size="small"
        icon={<CopyOutlined aria-hidden />}
        onClick={copy}
        aria-label={label ?? __("Copy command", "mcpmate")}
      >
        {__("Copy", "mcpmate")}
      </Button>
    </div>
  );
}

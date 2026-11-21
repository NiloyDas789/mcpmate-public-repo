import { useRef } from "react";
import { CopyOutlined } from "@ant-design/icons";
import { Button, Input, Space, message } from "antd";
import type { InputRef } from "antd";
import { __ } from "@wordpress/i18n";
import { copyText, selectContents } from "@utils/clipboard";

interface McpUrlProps {
  url: string;
  /** `large`: the setup wizard's bigger input and button. */
  size?: "middle" | "large";
  /** Also told the toast's text, for a page's live region. */
  onCopied?: (text: string) => void;
}

/**
 * The site's MCP URL with a Copy button. The URL is not a secret: it only
 * leads to the approval flow, which ends on this site's wp-admin. Without
 * the Clipboard API (plain http is not a secure context) the URL is
 * selected for Ctrl+C instead.
 */
export default function McpUrl({
  url,
  size = "middle",
  onCopied,
}: McpUrlProps) {
  const input = useRef<InputRef>(null);

  const copy = async () => {
    if (await copyText(url)) {
      const text = __("MCP URL copied.", "mcpmate");
      message.success(text);
      onCopied?.(text);
      return;
    }

    selectContents(input.current?.input ?? null);
    const text = __("Press Ctrl+C (⌘C on Mac) to copy.", "mcpmate");
    message.info(text);
    onCopied?.(text);
  };

  return (
    <Space.Compact className="w-full" size={size}>
      <Input
        ref={input}
        readOnly
        value={url}
        size={size}
        aria-label={__("MCP URL", "mcpmate")}
        onFocus={(event) => event.target.select()}
      />
      <Button
        type="primary"
        size={size}
        icon={<CopyOutlined aria-hidden />}
        onClick={copy}
        aria-label={__("Copy MCP URL", "mcpmate")}
      >
        {__("Copy", "mcpmate")}
      </Button>
    </Space.Compact>
  );
}

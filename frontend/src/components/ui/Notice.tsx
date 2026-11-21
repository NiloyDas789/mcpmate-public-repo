import type { ReactNode } from "react";
import {
  CheckCircleOutlined,
  CloseOutlined,
  CloseCircleOutlined,
  ExclamationCircleOutlined,
  InfoCircleOutlined,
} from "@ant-design/icons";
import { __, sprintf } from "@wordpress/i18n";
import Button from "./Button";

export type NoticeTone = "info" | "success" | "warning" | "danger";

const ICONS: Record<NoticeTone, ReactNode> = {
  info: <InfoCircleOutlined aria-hidden />,
  success: <CheckCircleOutlined aria-hidden />,
  warning: <ExclamationCircleOutlined aria-hidden />,
  danger: <CloseCircleOutlined aria-hidden />,
};

const TONE_WORDS: Record<NoticeTone, () => string> = {
  info: () => __("Information:", "mcpmate"),
  success: () => __("Success:", "mcpmate"),
  warning: () => __("Warning:", "mcpmate"),
  danger: () => __("Error:", "mcpmate"),
};

interface NoticeProps {
  tone: NoticeTone;
  title: string;
  children?: ReactNode;
  /** One button or link at the inline end. */
  action?: ReactNode;
  /** Adds a close button named "Dismiss: <title>". */
  onDismiss?: () => void;
  /**
   * Shown after a user action: `role="status"` (info, success) or
   * `role="alert"` (warning, danger). Notices present on load stay quiet.
   */
  live?: boolean;
}

/**
 * An inline message with an icon and a tone word for screen readers
 * (docs/ui.md §7). Not antd `Alert`, which adds its own roles unevenly.
 */
export default function Notice({
  tone,
  title,
  children,
  action,
  onDismiss,
  live = false,
}: NoticeProps) {
  const role = live
    ? tone === "info" || tone === "success"
      ? "status"
      : "alert"
    : undefined;

  return (
    <div className={`el-notice el-notice-${tone}`} role={role}>
      <span className="el-notice-icon">{ICONS[tone]}</span>
      <div className="el-notice-content">
        <p className="el-notice-title">
          <span className="sr-only">{TONE_WORDS[tone]()} </span>
          {title}
        </p>
        {children && <div className="el-notice-body">{children}</div>}
      </div>
      {action && <div className="el-notice-action">{action}</div>}
      {onDismiss && (
        <Button
          variant="link"
          size="sm"
          icon={<CloseOutlined aria-hidden />}
          label={sprintf(
            /* translators: %s: the notice's title. */
            __("Dismiss: %s", "mcpmate"),
            title,
          )}
          onClick={onDismiss}
          className="el-notice-dismiss"
        />
      )}
    </div>
  );
}

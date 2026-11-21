import type { ReactNode } from "react";
import { SafetyOutlined } from "@ant-design/icons";
import { __ } from "@wordpress/i18n";

export type BadgeVariant =
  | "read"
  | "write"
  | "approval"
  | "neutral"
  | "unavailable";

const DEFAULT_TEXT: Partial<Record<BadgeVariant, () => string>> = {
  read: () => __("Read", "mcpmate"),
  write: () => __("Write", "mcpmate"),
  approval: () => __("Asks you first", "mcpmate"),
  unavailable: () => __("Not installed", "mcpmate"),
};

/**
 * A short word in a tinted box: Read, Write, Asks you first, Not installed
 * (docs/ui.md §7). Always text; the shield on "Asks you first" is decorative.
 */
export default function Badge({
  variant,
  children,
}: {
  variant: BadgeVariant;
  children?: ReactNode;
}) {
  return (
    <span className={`el-badge el-badge-${variant}`}>
      {variant === "approval" && <SafetyOutlined aria-hidden />}
      {children ?? DEFAULT_TEXT[variant]?.()}
    </span>
  );
}

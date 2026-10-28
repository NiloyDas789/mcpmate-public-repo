import type { ReactNode } from "react";
import { Drawer as AntDrawer } from "antd";
import { __ } from "@wordpress/i18n";
import {
  TITLE_ATTRIBUTE,
  useDialogFocus,
  type InitialFocus,
} from "@/hooks/useDialogFocus";
import { isRtl } from "@utils/format";
import { portalContainer } from "@utils/portal";

interface DrawerProps {
  title: string;
  open: boolean;
  onClose: () => void;
  /** `md` 480 px, `lg` 720 px (diffs); full screen below 600 px. */
  size?: "md" | "lg";
  footer?: ReactNode;
  initialFocus?: InitialFocus;
  children?: ReactNode;
}

/**
 * A panel from the inline-end side (docs/ui.md §7), with Modal's focus
 * rules. Keep its open state in the URL (`?entry=<id>`) so Back closes it.
 */
export default function Drawer({
  title,
  open,
  onClose,
  size = "md",
  footer,
  initialFocus = "title",
  children,
}: DrawerProps) {
  const setPanel = useDialogFocus(open, initialFocus);

  return (
    <AntDrawer
      open={open}
      onClose={onClose}
      title={
        <h2
          className="el-dialog-title"
          tabIndex={-1}
          {...{ [TITLE_ATTRIBUTE]: "" }}
        >
          {title}
        </h2>
      }
      placement={isRtl() ? "left" : "right"}
      width={size === "lg" ? 720 : 480}
      footer={footer}
      getContainer={portalContainer}
      rootClassName={`el-drawer el-drawer-${size}`}
      closable={{ "aria-label": __("Close", "mcpmate") }}
      autoFocus={false}
      destroyOnHidden
      panelRef={setPanel}
    >
      {children}
    </AntDrawer>
  );
}

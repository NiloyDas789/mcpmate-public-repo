import { useCallback, useEffect, useState, type ReactNode } from "react";
import { Modal as AntModal } from "antd";
import { __ } from "@wordpress/i18n";
import {
  TITLE_ATTRIBUTE,
  useDialogFocus,
  type InitialFocus,
} from "@/hooks/useDialogFocus";
import { portalContainer } from "@utils/portal";

export interface ModalProps {
  title: string;
  open: boolean;
  /** Esc, the close button, and the mask. */
  onClose: () => void;
  footer?: ReactNode;
  /** `md` 560 px, `lg` 720 px; full screen below 600 px. */
  size?: "md" | "lg";
  initialFocus?: InitialFocus;
  /** `alertdialog` for a confirmation (ConfirmDialog). */
  role?: "dialog" | "alertdialog";
  /** Id of the element that describes the dialog. */
  describedBy?: string;
  /** False while an action runs: Esc and the mask do nothing. */
  closable?: boolean;
  children?: ReactNode;
}

/**
 * A dialog over the page (docs/ui.md §7): labelled by its `<h2>` title,
 * focus moved in on open and trapped, Esc closes it, the app behind is
 * inert, and focus returns to the opener on close. Renders into the portal
 * container, so the root's tokens and styles reach it.
 */
export default function Modal({
  title,
  open,
  onClose,
  footer,
  size = "md",
  initialFocus = "auto",
  role = "dialog",
  describedBy,
  closable = true,
  children,
}: ModalProps) {
  const setPanel = useDialogFocus(open, initialFocus);
  const [panel, setPanelNode] = useState<HTMLDivElement | null>(null);
  const panelRef = useCallback(
    (element: HTMLDivElement | null) => {
      setPanel(element);
      setPanelNode(element);
    },
    [setPanel],
  );

  // rc-dialog sets role="dialog" and no description; adjust its element.
  useEffect(() => {
    const dialog = panel?.closest<HTMLElement>("[aria-modal]");
    if (!dialog) {
      return;
    }
    dialog.setAttribute("role", role);
    if (describedBy) {
      dialog.setAttribute("aria-describedby", describedBy);
    } else {
      dialog.removeAttribute("aria-describedby");
    }
  }, [panel, role, describedBy]);

  return (
    <AntModal
      open={open}
      onCancel={onClose}
      title={
        <h2
          className="el-dialog-title"
          tabIndex={-1}
          {...{ [TITLE_ATTRIBUTE]: "" }}
        >
          {title}
        </h2>
      }
      footer={footer ?? null}
      width={size === "lg" ? 720 : 560}
      getContainer={portalContainer}
      rootClassName="el-modal"
      closable={closable ? { "aria-label": __("Close", "mcpmate") } : false}
      keyboard={closable}
      maskClosable={closable}
      destroyOnHidden
      focusTriggerAfterClose
      modalRender={(node) => (
        <div ref={panelRef} className="el-dialog-panel">
          {node}
        </div>
      )}
    >
      {children}
    </AntModal>
  );
}

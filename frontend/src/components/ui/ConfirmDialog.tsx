import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { __ } from "@wordpress/i18n";
import Button from "./Button";
import Modal from "./Modal";
import Notice from "./Notice";

interface ConfirmDialogProps {
  open: boolean;
  /** A question: "Revoke access for Claude?" */
  title: string;
  /** What happens, and what stays. */
  body: ReactNode;
  /** Names the action: "Revoke access for Claude". */
  confirmLabel: string;
  /** Names what is kept: "Keep access". */
  cancelLabel: string;
  /** Shown on the confirm button while `onConfirm` runs ("Revoking…"). */
  busyLabel?: string;
  tone?: "default" | "danger";
  /** May be async; the dialog closes when it resolves, and stays open with the error when it throws. */
  onConfirm: () => void | Promise<void>;
  /** Cancel, Esc, the close button, and after a successful confirm. */
  onClose: () => void;
}

/**
 * A yes-or-no question before an action (docs/ui.md §7): `role="alertdialog"`,
 * the confirm button names the action and the cancel button what is kept.
 * A danger dialog opens with focus on Cancel, so a stray Enter does nothing.
 */
export default function ConfirmDialog({
  open,
  title,
  body,
  confirmLabel,
  cancelLabel,
  busyLabel,
  tone = "default",
  onConfirm,
  onClose,
}: ConfirmDialogProps) {
  const bodyId = useId();
  const cancel = useRef<HTMLButtonElement>(null);
  const confirm = useRef<HTMLButtonElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
    }
  }, [open]);

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      await onConfirm();
      setBusy(false);
      onClose();
    } catch (caught) {
      setBusy(false);
      setError(caught instanceof Error ? caught.message : String(caught));
    }
  };

  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      role="alertdialog"
      describedBy={bodyId}
      initialFocus={tone === "danger" ? cancel : confirm}
      closable={!busy}
      footer={
        <div className="el-dialog-actions">
          <Button
            ref={confirm}
            variant={tone === "danger" ? "danger" : "primary"}
            loading={busy}
            onClick={() => void run()}
          >
            {busy && busyLabel ? busyLabel : confirmLabel}
          </Button>
          <Button
            ref={cancel}
            variant="secondary"
            onClick={onClose}
            disabled={busy}
          >
            {cancelLabel}
          </Button>
        </div>
      }
    >
      <div id={bodyId} className="el-dialog-body">
        {body}
      </div>
      {error && (
        <Notice tone="danger" title={__("That didn't work.", "mcpmate")} live>
          {error}
        </Notice>
      )}
    </Modal>
  );
}

import { Tag } from "antd";
import type { ApprovalStatus } from "../../types/api";
import { statusLabel } from "./labels";

const STATUS_COLORS: Record<ApprovalStatus, string> = {
  pending: "gold",
  approved: "blue",
  executed: "green",
  rejected: "red",
  expired: "default",
};

export default function StatusTag({ status }: { status: ApprovalStatus }) {
  return <Tag color={STATUS_COLORS[status]}>{statusLabel(status)}</Tag>;
}

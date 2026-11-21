import type { ReactNode } from "react";
import Button from "./Button";

export interface EmptyStateAction {
  label: string;
  onClick?: () => void;
  href?: string;
}

interface EmptyStateProps {
  title: string;
  body?: ReactNode;
  /** The one next step. */
  action?: EmptyStateAction;
  headingLevel?: 2 | 3 | 4;
  /** Decorative. */
  icon?: ReactNode;
}

/** Nothing here yet, and the one thing to do about it (docs/ui.md §7). */
export default function EmptyState({
  title,
  body,
  action,
  headingLevel = 3,
  icon,
}: EmptyStateProps) {
  const Heading = `h${headingLevel}` as "h2" | "h3" | "h4";

  return (
    <div className="el-empty">
      {icon && (
        <span className="el-empty-icon" aria-hidden="true">
          {icon}
        </span>
      )}
      <Heading className="el-empty-title">{title}</Heading>
      {body && <p className="el-empty-body">{body}</p>}
      {action && (
        <Button
          variant="secondary"
          href={action.href}
          onClick={action.onClick}
          className="el-empty-action"
        >
          {action.label}
        </Button>
      )}
    </div>
  );
}

import { useId, type ReactNode, type Ref } from "react";
import { cx } from "@utils/classNames";

interface CardProps {
  /** Shown as a small uppercase label, in an `<h2>` or `<h3>`. */
  title?: string;
  headingLevel?: 2 | 3;
  /** At the inline end of the title row. */
  actions?: ReactNode;
  /** Sets aria-busy while the card's content loads. */
  busy?: boolean;
  /** On the title, which can then take focus (`?section=` on Settings). */
  headingRef?: Ref<HTMLHeadingElement>;
  /** The title's id, for a form inside that it names. */
  headingId?: string;
  className?: string;
  children: ReactNode;
}

/**
 * A bordered section (docs/ui.md §7). Not antd `Card`, whose title is not
 * a heading. Never clickable as a whole: put links and buttons inside.
 */
export default function Card({
  title,
  headingLevel = 2,
  actions,
  busy,
  headingRef,
  headingId,
  className,
  children,
}: CardProps) {
  const ownId = useId();
  const titleId = headingId ?? ownId;
  const Heading = headingLevel === 3 ? "h3" : "h2";

  return (
    <section
      className={cx("el-card", className)}
      aria-labelledby={title ? titleId : undefined}
      aria-busy={busy || undefined}
    >
      {(title || actions) && (
        <div className="el-card-head">
          {title && (
            <Heading
              id={titleId}
              ref={headingRef}
              tabIndex={headingRef ? -1 : undefined}
              className="el-card-title"
            >
              {title}
            </Heading>
          )}
          {actions && <div className="el-card-actions">{actions}</div>}
        </div>
      )}
      {children}
    </section>
  );
}

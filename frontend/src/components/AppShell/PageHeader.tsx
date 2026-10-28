import { useContext, useEffect, useRef, type ReactNode } from "react";
import { ShellContext } from "./ShellContext";

// "McpMate ‹ My Site — WordPress", as WordPress rendered it.
const BASE_TITLE = typeof document !== "undefined" ? document.title : "";

/**
 * A page's `<h1>` and one-line intro. It names the browser tab ("Activity
 * ‹ McpMate ‹ My Site — WordPress") and takes focus when the user
 * arrives from another page.
 */
export default function PageHeader({
  title,
  intro,
  actions,
}: {
  title: string;
  intro?: ReactNode;
  actions?: ReactNode;
}) {
  const { navigated } = useContext(ShellContext);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    document.title = BASE_TITLE ? `${title} ‹ ${BASE_TITLE}` : title;
  }, [title]);

  useEffect(() => {
    if (navigated()) {
      heading.current?.focus();
    }
  }, [navigated]);

  return (
    <div className="el-page-header">
      <div className="el-page-header-text">
        <h1 ref={heading} tabIndex={-1} className="el-page-title">
          {title}
        </h1>
        {intro && <p className="el-page-intro">{intro}</p>}
      </div>
      {actions && <div className="el-page-actions">{actions}</div>}
    </div>
  );
}

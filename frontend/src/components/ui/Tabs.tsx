import { useId, useRef, type KeyboardEvent, type ReactNode } from "react";
import { Link, useNavigate } from "react-router";
import { isRtl } from "@utils/format";
import { cx } from "@utils/classNames";

export interface TabItem {
  key: string;
  label: string;
  /** The tab's route: each panel is its own page, so Back and Reload work. */
  to: string;
  /** Shown after the label ("2", "24/38"). */
  count?: string;
  /** How the count is read, joined to the label: "Approvals, 2 waiting". */
  countLabel?: string;
}

interface TabsProps {
  /** Names the tab list. */
  label: string;
  items: TabItem[];
  activeKey: string;
  /** The active tab's panel. */
  children: ReactNode;
}

/**
 * In-page tabs over routes (docs/ui.md §7): `role="tablist"` with a roving
 * tabindex. Left and Right move and activate (activation follows focus,
 * since a panel is one route away), Home and End jump to the ends, and
 * right-to-left reverses Left and Right. Our own rather than antd `Tabs`,
 * whose arrow keys only move focus and wait for Enter.
 */
export default function Tabs({ label, items, activeKey, children }: TabsProps) {
  const navigate = useNavigate();
  const id = useId();
  const tabs = useRef<Record<string, HTMLAnchorElement | null>>({});
  const tabId = (key: string) => `${id}-tab-${key}`;
  const panelId = `${id}-panel`;

  const activate = (index: number) => {
    const item = items[(index + items.length) % items.length];
    tabs.current[item.key]?.focus();
    if (item.key !== activeKey) {
      navigate(item.to);
    }
  };

  const onKeyDown = (
    event: KeyboardEvent<HTMLAnchorElement>,
    index: number,
  ) => {
    const forward = isRtl() ? "ArrowLeft" : "ArrowRight";
    const back = isRtl() ? "ArrowRight" : "ArrowLeft";
    const moves: Record<string, number> = {
      [forward]: index + 1,
      [back]: index - 1,
      Home: 0,
      End: items.length - 1,
    };

    if (event.key in moves) {
      event.preventDefault();
      activate(moves[event.key]);
    } else if (event.key === " ") {
      event.preventDefault();
      activate(index);
    }
  };

  return (
    <div className="el-tabs">
      <div role="tablist" aria-label={label} className="el-tablist">
        {items.map((item, index) => {
          const active = item.key === activeKey;

          return (
            <Link
              key={item.key}
              ref={(node) => {
                tabs.current[item.key] = node;
              }}
              to={item.to}
              role="tab"
              id={tabId(item.key)}
              aria-selected={active}
              aria-controls={active ? panelId : undefined}
              tabIndex={active ? 0 : -1}
              className={cx("el-tab", active && "is-active")}
              onKeyDown={(event) => onKeyDown(event, index)}
            >
              {item.count ? (
                <>
                  <span className="sr-only">
                    {item.label}, {item.countLabel ?? item.count}
                  </span>
                  <span aria-hidden="true">{item.label}</span>
                  <span className="el-tab-count" aria-hidden="true">
                    {item.count}
                  </span>
                </>
              ) : (
                <span>{item.label}</span>
              )}
            </Link>
          );
        })}
      </div>
      <div
        role="tabpanel"
        id={panelId}
        aria-labelledby={tabId(activeKey)}
        tabIndex={0}
        className="el-tabpanel"
      >
        {children}
      </div>
    </div>
  );
}

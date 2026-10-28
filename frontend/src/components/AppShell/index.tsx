import {
  Suspense,
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type MouseEvent,
  type ReactNode,
} from "react";
import { ExportOutlined, MenuOutlined } from "@ant-design/icons";
import { NavLink, Outlet, useLocation } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import Logo from "@components/ui/Logo";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import StatusPill from "@components/ui/StatusPill";
import Button from "@components/ui/Button";
import { useContainerWidth } from "@/hooks/useContainerWidth";
import { breakpoints } from "@config/tokens";
import {
  useAccessState,
  useLiveGrantCount,
  usePendingApprovalCount,
} from "@services/queries";
import { ShellContext, useShellContextValue } from "./ShellContext";
import { pillState, shellData, shellNotices } from "./status";
import { cx } from "@utils/classNames";

const MAIN_ID = "mcpmate-main";

function navItems() {
  return [
    { to: "/", end: true, label: __("Dashboard", "mcpmate") },
    { to: "/connections", label: __("Connections", "mcpmate") },
    { to: "/tools", label: __("Tools", "mcpmate") },
    { to: "/activity", label: __("Activity", "mcpmate") },
    { to: "/settings", label: __("Settings", "mcpmate") },
  ];
}

/** "Skip to McpMate content": focuses `<main>` (a hash link would change the route). */
function SkipLink() {
  const skip = (event: MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault();
    document.getElementById(MAIN_ID)?.focus();
  };

  return (
    <a href={`#${MAIN_ID}`} className="el-skip-link" onClick={skip}>
      {__("Skip to McpMate content", "mcpmate")}
    </a>
  );
}

function Brand({ link }: { link: boolean }) {
  const content = (
    <>
      <Logo />
      <span className="el-brand-name">
        {SERVER_VARIABLES.pluginName || "McpMate"}
      </span>
    </>
  );

  return link ? (
    <a href="#/" className="el-brand">
      {content}
    </a>
  ) : (
    <span className="el-brand">{content}</span>
  );
}

function Main({ children }: { children: ReactNode }) {
  return (
    <main id={MAIN_ID} tabIndex={-1} className="el-main">
      <Suspense fallback={<Skeleton count={4} />}>{children}</Suspense>
    </main>
  );
}

/**
 * Keep a focused element clear of the sticky top bar (WCAG 2.4.11):
 * scroll padding is the admin bar plus the top bar, while it is sticky.
 */
function useScrollPadding(topBar: HTMLElement | null) {
  useEffect(() => {
    if (!topBar) {
      return;
    }

    const root = document.documentElement;
    const update = () => {
      const sticky = window.getComputedStyle(topBar).position === "sticky";
      root.style.scrollPaddingTop = sticky
        ? `calc(var(--wp-admin--admin-bar--height, 32px) + ${topBar.offsetHeight}px)`
        : "";
    };
    update();

    const observer =
      typeof ResizeObserver !== "undefined" ? new ResizeObserver(update) : null;
    observer?.observe(topBar);

    return () => {
      observer?.disconnect();
      root.style.scrollPaddingTop = "";
    };
  }, [topBar]);
}

function Footer() {
  const links = [
    { key: "docs", label: __("Documentation", "mcpmate") },
    { key: "support", label: __("Support", "mcpmate") },
    { key: "review", label: __("Rate the plugin", "mcpmate") },
    { key: "feedback", label: __("Share feedback", "mcpmate") },
  ] as const;
  const configured = links.filter((link) => SERVER_VARIABLES.links?.[link.key]);

  if (configured.length === 0 && !SERVER_VARIABLES.debug) {
    return null;
  }

  return (
    <footer className="el-footer">
      <ul className="el-footer-links">
        {configured.map((link) => (
          <li key={link.key}>
            <a
              href={SERVER_VARIABLES.links[link.key]}
              target="_blank"
              rel="noopener noreferrer"
            >
              {link.label}
              <ExportOutlined aria-hidden className="el-external-icon" />
              <span className="sr-only">
                {__("(opens in a new tab)", "mcpmate")}
              </span>
            </a>
          </li>
        ))}
        {SERVER_VARIABLES.debug && (
          <li>
            <a href="#/ui-kit">{__("UI kit (WP_DEBUG)", "mcpmate")}</a>
          </li>
        )}
      </ul>
    </footer>
  );
}

/**
 * The admin app's frame (docs/ui.md §3): skip link, top bar (name, page
 * navigation, status pill, version), at most two notices, `<main>`, and the
 * footer. Below 600 px the navigation folds behind a Menu button.
 */
export default function AppShell() {
  const shell = useShellContextValue();
  const { pathname } = useLocation();
  const navId = useId();
  const [widthRef, width] = useContainerWidth<HTMLElement>();
  const [topBar, setTopBar] = useState<HTMLElement | null>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const narrow = width !== undefined && width < breakpoints.sm;
  const medium = !narrow && width !== undefined && width < breakpoints.lg;
  const access = useAccessState();
  const grantsLive = useLiveGrantCount();
  const pendingApprovals = usePendingApprovalCount();
  const data = shellData({
    paused: access.paused,
    grantsLive,
    pendingApprovals,
  });
  const pill = pillState(data);
  const notices = shellNotices(data, pathname);
  const version = SERVER_VARIABLES.version;
  const lastPath = useRef(pathname);
  // Stable, or React would detach and re-attach it on every render.
  const topBarRef = useCallback(
    (node: HTMLElement | null) => {
      widthRef(node);
      setTopBar(node);
    },
    [widthRef],
  );

  useScrollPadding(topBar);

  // A page change closes the menu.
  useEffect(() => {
    if (pathname !== lastPath.current) {
      lastPath.current = pathname;
      setMenuOpen(false);
    }
  }, [pathname]);

  return (
    <ShellContext.Provider value={shell}>
      <div className="el-app">
        <SkipLink />
        <header
          ref={topBarRef}
          className={cx(
            "el-topbar",
            narrow && "is-narrow",
            medium && "is-medium",
          )}
        >
          <div className="el-topbar-inner">
            <Brand link />
            {narrow && (
              <Button
                variant="secondary"
                size="sm"
                icon={<MenuOutlined aria-hidden />}
                aria-expanded={menuOpen}
                aria-controls={navId}
                onClick={() => setMenuOpen(!menuOpen)}
                className="el-menu-button"
              >
                {__("Menu", "mcpmate")}
              </Button>
            )}
            <nav
              id={navId}
              aria-label={__("McpMate", "mcpmate")}
              className="el-nav"
              hidden={narrow && !menuOpen}
            >
              <ul>
                {navItems().map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.end}
                      className="el-nav-link"
                    >
                      {item.label}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </nav>
            <div className="el-topbar-meta">
              <StatusPill {...pill} href="#/connections" />
              <span className="el-version">
                <span aria-hidden="true">v{version}</span>
                <span className="sr-only">
                  {sprintf(
                    /* translators: %s: plugin version number. */
                    __("Version %s", "mcpmate"),
                    version,
                  )}
                </span>
              </span>
            </div>
          </div>
        </header>

        <Main>
          {/* In <main>, so the skip link lands on them first. */}
          {notices.length > 0 && (
            <div className="el-shell-notices">
              {notices.map((notice) => (
                <Notice
                  key={notice.key}
                  tone={notice.tone}
                  title={notice.title}
                  action={
                    notice.action && (
                      <Button
                        variant="secondary"
                        size="sm"
                        href={notice.action.href}
                      >
                        {notice.action.label}
                      </Button>
                    )
                  }
                />
              ))}
            </div>
          )}
          <Outlet />
        </Main>

        <Footer />
      </div>
    </ShellContext.Provider>
  );
}

/**
 * The frame for Setup and the AI-app approval screen (docs/ui.md §3.6): the
 * name only, nothing that pulls attention from the decision.
 */
export function MinimalShell() {
  const shell = useShellContextValue();

  return (
    <ShellContext.Provider value={shell}>
      <div className="el-app el-app-minimal">
        <SkipLink />
        <header className="el-topbar">
          <div className="el-topbar-inner">
            <Brand link={false} />
          </div>
        </header>
        <Main>
          <Outlet />
        </Main>
      </div>
    </ShellContext.Provider>
  );
}

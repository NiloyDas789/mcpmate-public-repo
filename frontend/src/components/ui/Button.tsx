import {
  forwardRef,
  useLayoutEffect,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type MouseEvent,
  type ReactNode,
} from "react";
import { LoadingOutlined } from "@ant-design/icons";
import { Button as AntButton } from "antd";
import type { ButtonProps as AntButtonProps } from "antd";

export type ButtonVariant =
  | "primary"
  | "secondary"
  | "danger"
  | "danger-secondary"
  | "link";

export interface ButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  "type" | "color"
> {
  variant?: ButtonVariant;
  /** `md` is 36 px tall, `sm` 32 px. */
  size?: "md" | "sm";
  icon?: ReactNode;
  iconPosition?: "start" | "end";
  /** Keeps the label (or a progress word you pass), sets aria-disabled, keeps the width. */
  loading?: boolean;
  /** Accessible name; required when there is no visible text. */
  label?: string;
  /** Renders a link (`#/route` for a page of this app). */
  href?: string;
  target?: string;
  rel?: string;
  htmlType?: "button" | "submit" | "reset";
}

const ANT_TYPE: Record<ButtonVariant, AntButtonProps["type"]> = {
  primary: "primary",
  secondary: "default",
  danger: "primary",
  "danger-secondary": "default",
  link: "link",
};

/**
 * A button in one of five variants (docs/ui.md §7). A destructive label
 * says what happens ("Revoke access for Claude"); a disabled button gets
 * its reason as visible text nearby, never only in a tooltip.
 */
const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = "secondary",
    size = "md",
    icon,
    loading = false,
    label,
    href,
    htmlType = "button",
    className,
    children,
    onClick,
    style,
    ...rest
  },
  ref,
) {
  const own = useRef<HTMLElement | null>(null);
  const [lockedWidth, setLockedWidth] = useState<number>();

  // Keep the width while loading, so the row does not jump.
  useLayoutEffect(() => {
    setLockedWidth(loading ? own.current?.offsetWidth || undefined : undefined);
  }, [loading]);

  const iconOnly =
    children === undefined || children === null || children === "";
  const classes = [
    "el-btn",
    `el-btn-${variant}`,
    `el-btn-${size}`,
    iconOnly ? "el-btn-icon" : "",
    loading ? "is-loading" : "",
    className ?? "",
  ]
    .filter(Boolean)
    .join(" ");

  const handleClick = (event: MouseEvent<HTMLButtonElement>) => {
    if (loading) {
      event.preventDefault();
      return;
    }
    onClick?.(event);
  };

  return (
    <AntButton
      {...rest}
      ref={(node: HTMLButtonElement | HTMLAnchorElement | null) => {
        own.current = node;
        if (typeof ref === "function") {
          ref(node as HTMLButtonElement | null);
        } else if (ref) {
          ref.current = node as HTMLButtonElement | null;
        }
      }}
      type={ANT_TYPE[variant]}
      danger={variant === "danger" || variant === "danger-secondary"}
      htmlType={htmlType}
      href={href}
      className={classes}
      icon={loading ? <LoadingOutlined spin aria-hidden /> : icon}
      aria-label={label}
      aria-disabled={loading || undefined}
      aria-busy={loading || undefined}
      style={lockedWidth ? { ...style, minWidth: lockedWidth } : style}
      onClick={handleClick as AntButtonProps["onClick"]}
    >
      {children}
    </AntButton>
  );
});

export default Button;

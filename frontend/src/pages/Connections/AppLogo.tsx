import { cx } from "@utils/classNames";

/**
 * An AI app's logo on its square, or its initial when it has none we can
 * vouch for. Decorative: the app's name is always written beside it.
 */
export default function AppLogo({
  src,
  name,
  size = "md",
}: {
  src?: string;
  name: string;
  size?: "sm" | "md";
}) {
  const initial = Array.from(name.trim())[0]?.toLocaleUpperCase() ?? "";

  return (
    <span
      className={cx("el-app-logo", size === "sm" && "el-app-logo-sm")}
      aria-hidden="true"
    >
      {src ? (
        <img src={src} alt="" width={20} height={20} />
      ) : (
        <span className="el-app-logo-initial">{initial}</span>
      )}
    </span>
  );
}

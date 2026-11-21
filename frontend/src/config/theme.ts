import type { ThemeConfig } from "antd";
import { colors, fontFamily, radius } from "./tokens";

/**
 * Ant Design theme tokens, generated from config/tokens.ts (docs/ui.md
 * §6) so antd's own parts (inputs, selects, checkboxes, switches) match ours.
 * Inputs and checkboxes take `--el-border-strong`, which meets 3:1.
 */
export const lightThemeConfig: ThemeConfig["token"] = {
  colorPrimary: colors.brand,
  colorPrimaryHover: colors["brand-hover"],
  colorPrimaryActive: colors["brand-hover"],
  colorInfo: colors["info-text"],
  colorSuccess: colors["success-dot"],
  colorWarning: colors["warning-dot"],
  colorError: colors.danger,
  colorErrorHover: colors["danger-hover"],
  colorLink: colors.brand,
  colorLinkHover: colors["brand-hover"],
  colorLinkActive: colors["brand-hover"],
  colorText: colors.text,
  colorTextSecondary: colors["text-secondary"],
  colorTextTertiary: colors["text-secondary"],
  colorTextDisabled: colors["text-disabled"],
  colorTextPlaceholder: colors["text-secondary"],
  colorBorder: colors["border-strong"],
  colorBorderSecondary: colors.border,
  colorBgContainer: colors["bg-surface"],
  colorBgLayout: colors["bg-page"],
  colorBgContainerDisabled: colors["bg-subtle"],
  colorFillAlter: colors["bg-subtle"],
  controlItemBgActive: colors["bg-brand-tint"],
  borderRadius: parseInt(radius.DEFAULT, 10),
  borderRadiusSM: parseInt(radius.sm, 10),
  borderRadiusLG: parseInt(radius.lg, 10),
  fontFamily: fontFamily.sans,
  fontFamilyCode: fontFamily.mono,
  fontSize: 14,
  controlHeight: 36,
  controlHeightSM: 32,
  zIndexPopupBase: 1000,
};

/**
 * Kept for `$isDarkTheme`, which nothing sets: version 1 is light only
 * (docs/ui.md §12, question 11).
 */
export const darkThemeConfig: ThemeConfig["token"] = {
  colorPrimary: "#586BA4",
  colorInfo: "#586BA4",
  colorLink: "#95A4D4",
  colorLinkHover: "#B4C0E6",
  colorLinkActive: "#95A4D4",
  colorBgContainer: "#141B33",
  colorBgLayout: "#0B1020",
  borderRadius: 8,
  fontFamily: fontFamily.sans,
};

import { atom } from "jotai";

/**
 * Global Jotai atoms for app-wide state.
 */

// Theme preference
export const $isDarkTheme = atom<boolean>(false);

// Navigation state (for programmatic navigation)
export const $navigateUrl = atom<string>("");

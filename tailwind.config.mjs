import plugin from 'tailwindcss/plugin'

// One source for every token (docs/ui.md §6). Tailwind loads this config
// through jiti, which compiles the TypeScript import.
import {
  breakpoints,
  colors,
  cssVariables,
  fontFamily,
  fontSize,
  radius,
} from './frontend/src/config/tokens.ts'

const px = (value) => `${value}px`

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./frontend/**/*.{html,js,jsx,ts,tsx}'],
  corePlugins: {
    preflight: false, // Avoid conflicts with WordPress admin styles
  },
  darkMode: 'selector',
  // Utilities apply inside the app only (wp-admin has its own `.hidden`, `.fixed`…).
  // The portal container sits inside the root, so overlays get them too.
  important: '#mcpmate-root',
  plugins: [
    plugin(({ addBase }) => {
      addBase({ '#mcpmate-root, #mcpmate-portal': cssVariables() })
    }),
  ],
  theme: {
    borderRadius: { ...radius, none: '0' },
    screens: {
      sm: px(breakpoints.sm),
      md: px(breakpoints.md),
      lg: px(breakpoints.lg),
      xl: px(breakpoints.xl),
    },
    extend: {
      // `bg-el-bg-surface`, `text-el-text-secondary`… read the CSS custom properties.
      colors: {
        el: Object.fromEntries(Object.keys(colors).map((name) => [name, `var(--el-${name})`])),
      },
      fontFamily: {
        mono: [fontFamily.mono],
        sans: [fontFamily.sans],
      },
      fontSize: Object.fromEntries(
        Object.entries(fontSize).map(([name, [size, lineHeight]]) => [`el-${name}`, [size, { lineHeight }]])
      ),
    },
  },
}

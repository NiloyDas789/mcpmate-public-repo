import js from "@eslint/js";
import tsPlugin from "@typescript-eslint/eslint-plugin";
import prettier from "eslint-config-prettier";
import react from "eslint-plugin-react";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import globals from "globals";

export default [
  { ignores: ["assets/**", "build/**", "coverage/**", "vendor/**", "node_modules/**"] },

  js.configs.recommended,
  ...tsPlugin.configs["flat/recommended"],

  {
    files: ["frontend/src/**/*.{ts,tsx,js,jsx}"],
    languageOptions: {
      globals: {
        ...globals.browser,
        // Injected by PHP (Head::createFrontendConfig) and wired up in vite.config.ts.
        SERVER_VARIABLES: "readonly",
      },
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    plugins: {
      react,
      "react-hooks": reactHooks,
      "react-refresh": reactRefresh,
    },
    settings: { react: { version: "detect" } },
    rules: {
      ...react.configs.flat.recommended.rules,
      ...react.configs.flat["jsx-runtime"].rules,
      ...reactHooks.configs.recommended.rules,
      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],
      "no-console": ["warn", { allow: ["warn", "error"] }],
      "@typescript-eslint/no-unused-vars": ["error", { argsIgnorePattern: "^_" }],
    },
  },

  {
    files: ["frontend/src/**/*.test.{ts,tsx}", "frontend/src/config/test.setup.ts"],
    languageOptions: { globals: { ...globals.node, ...globals.vitest } },
  },

  // Turn off rules that conflict with Prettier formatting (keep last).
  prettier,
];

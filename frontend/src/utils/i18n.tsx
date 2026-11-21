/**
 * Frontend internationalization bootstrap.
 *
 * Translations are extracted from the TS/TSX sources into
 * `languages/mcpmate-{locale}.po`, then compiled into a
 * per-locale PHP array under `languages/frontend/`. PHP hands that array to
 * the SPA through `wp_localize_script` (see `Views\Head::createFrontendConfig`),
 * and `setLocaleData` loads it into the bundled `@wordpress/i18n` runtime.
 *
 * Call sites use the standard WordPress API so the strings stay familiar and
 * portable:
 *
 *   import { __ } from '@wordpress/i18n'
 *   __( 'Save changes', 'mcpmate' )
 */
import { createElement, Fragment, type ReactNode } from "react";
import { setLocaleData } from "@wordpress/i18n";

export const TEXT_DOMAIN = "mcpmate";

/**
 * Load the locale data injected by PHP into the `@wordpress/i18n` runtime.
 *
 * No-ops for `en_US` (and any locale with no compiled catalog), where the
 * source strings are already the correct output.
 */
export function bootstrapI18n(): void {
  const localeData = SERVER_VARIABLES?.translations;

  if (localeData) {
    setLocaleData(localeData, TEXT_DOMAIN);
  }
}

/**
 * Substitute React nodes into a translated string's `%s` / `%1$s` placeholders.
 *
 * `sprintf` can only produce a string, so a sentence that wraps part of itself
 * in markup would otherwise have to be split into fragments — which leaves
 * translators unable to reorder it. This keeps the sentence a single message:
 *
 *   interpolate(
 *     // translators: %s: platform name.
 *     __( 'Paste it into your %s settings.', 'mcpmate' ),
 *     [ <strong key="p">{ platform }</strong> ]
 *   )
 *
 * Positional (`%1$s`) and sequential (`%s`) placeholders may be mixed; a
 * placeholder with no matching value is dropped.
 */
export function interpolate(
  template: string,
  values: ReactNode[],
): ReactNode[] {
  let next = 0;

  return template.split(/(%\d+\$s|%s)/g).map((part, index) => {
    const positional = /^%(\d+)\$s$/.exec(part);
    const value = positional
      ? values[Number(positional[1]) - 1]
      : part === "%s"
        ? values[next++]
        : undefined;

    if (value === undefined) {
      return positional || part === "%s" ? null : part;
    }

    return createElement(Fragment, { key: index }, value);
  });
}

/**
 * Vite HMR helper — prevents full page reload during dev.
 * Loaded only in development mode.
 */
if (import.meta.hot) {
  import.meta.hot.on("vite:beforeFullReload", () => {
    // eslint-disable-next-line no-console
    console.log("[mcpmate] HMR full reload");
  });
}

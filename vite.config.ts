import fs from 'node:fs'
import path from 'node:path'

import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'
import tsconfigPaths from 'vite-tsconfig-paths'

// Must match Config::SLUG and Config::VAR_PREFIX in backend/app/Config.php
const SLUG = 'mcpmate'
const VAR_PREFIX = 'MCPMATE_'
const ASSETS_DIR = 'assets'

export default defineConfig(({ mode }) => {
  const { DEV_SSL, DEV_SSL_CERT_PATH, DEV_SSL_KEY_PATH } = loadEnv(mode, process.cwd(), '')

  const isDevelopment = mode === 'development' || mode === 'test'
  const isTest = mode === 'test'
  const folderName = path.basename(process.cwd())

  // Simple deterministic code name for cache busting
  const codeName = Math.random().toString(36).substring(2, 10)

  return {
    assetsDir: 'assets',
    base: isDevelopment ? `/wp-content/plugins/${folderName}/frontend/` : '',

    build: {
      emptyOutDir: true,
      outDir: `../${ASSETS_DIR}`,
      rollupOptions: {
        input: path.resolve(import.meta.dirname, 'frontend/src/main.tsx'),
        output: {
          assetFileNames: (fInfo) => {
            const fileName = fInfo?.name?.split('/').at(-1)
            if (fileName === 'main.css') return `main-${SLUG}-ba-assets-${codeName}.css`
            if (fileName === 'logo.svg') return 'logo.svg'
            return `${SLUG}-ba-assets-${hash()}.[ext]`
          },
          chunkFileNames: (fInfo) => {
            const name = typeof fInfo.name === 'string' ? fInfo.name.slice(0, 8).toLowerCase() : ''
            return `${name}-${hash()}.js`
          },
          entryFileNames: `main-${codeName}.js`,
          generatedCode: {
            arrowFunctions: true,
            constBindings: true,
            objectShorthand: true,
            preset: 'es2015',
          },
        },
      },
    },

    define: {
      // In tests, frontend/src/config/test.setup.ts defines SERVER_VARIABLES itself
      // (Vitest would evaluate a define here before the setup file runs).
      ...(!isTest && { SERVER_VARIABLES: `window.${VAR_PREFIX}` }),
    },

    plugins: [
      react({ jsxRuntime: 'automatic' }),
      tsconfigPaths(),
      // Write build code name to file for PHP to read
      {
        name: 'write-build-code-name',
        closeBundle() {
          if (!isDevelopment) {
            fs.mkdirSync(path.resolve(import.meta.dirname, ASSETS_DIR), { recursive: true })
            fs.writeFileSync(
              path.resolve(import.meta.dirname, ASSETS_DIR, 'build-code-name.txt'),
              codeName
            )
          }
        },
      },
    ],

    root: 'frontend',

    server: {
      ...(DEV_SSL === 'true' && {
        https: {
          cert: DEV_SSL_CERT_PATH,
          key: DEV_SSL_KEY_PATH,
        },
      }),
      cors: true,
      hmr: { host: 'localhost' },
      port: 3000,
      strictPort: true,
    },

    ssr: {
      noExternal: isTest ? ['@vitejs/plugin-react'] : [],
    },

    test: {
      environment: 'happy-dom',
      globals: true,
      include: ['frontend/src/**/*.test.{tsx,ts}'],
      root: './',
      setupFiles: ['./frontend/src/config/test.setup.ts'],
      testTimeout: 10_000,
    },
  }
})

function hash() {
  return Math.round(Math.random() * 998 + 1)
}

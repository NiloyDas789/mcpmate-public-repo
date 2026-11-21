/**
 * Check the admin UI kit page (`#/ui-kit`), then the Dashboard,
 * Connections, Tools, Activity and Settings pages, with axe-core in a real
 * browser.
 *
 *   pnpm test:a11y            # everything
 *   pnpm test:a11y pages      # the pages only
 *   pnpm test:a11y kit        # the UI kit only
 *   pnpm test:a11y tools      # the Tools page only
 *   pnpm test:a11y activity   # the Activity page only
 *   pnpm test:a11y settings   # the Settings page only
 *   pnpm test:a11y approvals  # Activity → Approvals, list and request
 *   pnpm test:a11y queue      # Activity → Block editor queue, list and batch (MCPMate Pro)
 *   pnpm test:a11y design     # Tools → Design: list, library, a design open (MCPMate Pro)
 *   pnpm test:a11y screens    # Not found, Setup, Connect
 *   pnpm test:a11y rtl        # every page right to left
 *   pnpm test:a11y zoom       # 200 % zoom (640 px), 200 % text, text spacing
 *   pnpm test:a11y motion     # nothing animates under prefers-reduced-motion
 *   pnpm test:a11y slow       # every answer 1.5 s late: busy states, progress words
 *
 * Starts the Vite dev server with one extra page that mounts the admin app
 * the way Views\Body does (app and portal inside #mcpmate-root), inside
 * wp-admin's own stylesheets when this plugin sits in a WordPress install
 * (with a stand-in for the fixed admin bar),
 * and with `debug: true` as WP_DEBUG would set it. No WordPress login or
 * database is needed. Then, in Chromium (Playwright's own, else the
 * installed Google Chrome):
 *
 *   - axe on the whole page, at 1280 px and at 320 px (menu closed and open);
 *   - axe again with each dialog open (Modal, ConfirmDialog with its error,
 *     Drawer);
 *   - Tab through the page: focus must never fall back to <body>, and every
 *     stop must show a 2 px focus ring;
 *   - at 320 px, no sideways scrolling.
 *
 * The pages run against `frontend/src/test/fakeSite.ts` (the same fake
 * backend as their Vitest tests), loaded through Vite: admin-ajax and REST
 * calls are answered by it, with 0, 1 and 12 AI apps, a paused site, a site
 * that is not connected, and every request failing or never answering.
 * Each page and each dialog is checked with axe, walked with Tab, checked
 * for sideways scrolling at 320 px, and its main actions are driven with
 * the keyboard alone. Screenshots go to `A11Y_SCREENSHOTS` when it is set.
 *
 * Exits 1 on any finding. In a browser, axe also checks colour contrast,
 * which the Vitest run (happy-dom, no layout) cannot.
 */
import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import process from 'node:process'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

const require = createRequire(import.meta.url)
const ROOT = path.resolve(import.meta.dirname, '..')
const WP_ROOT = path.resolve(ROOT, '../../..')
const HARNESS = '__ui-kit.html'
const FAKE_SITE = '/src/test/fakeSite.ts'
const ONLY = process.argv[2] ?? 'all'
const SHOTS = process.env.A11Y_SCREENSHOTS ?? ''

const WP_STYLES = [
  'wp-includes/css/dashicons.min.css',
  'wp-admin/css/common.min.css',
  'wp-admin/css/forms.min.css',
].filter((file) => fs.existsSync(path.join(WP_ROOT, file)))

/** What Head::createFrontendConfig() would print, for a connected site. */
const SERVER_VARIABLES = {
  nonce: 'ui-kit',
  restNonce: 'ui-kit',
  rootURL: '',
  siteURL: '',
  siteBaseURL: '',
  assetsURL: '',
  pluginAdminURL: '',
  ajaxURL: '/wp-admin/admin-ajax.php',
  apiURL: { base: '/wp-json/mcpmate/v1', separator: '?' },
  routePrefix: 'MCPMATE_',
  dateFormat: 'F j, Y',
  timeFormat: 'g:i a',
  timeZone: 'UTC',
  pluginSlug: 'mcpmate',
  pluginName: 'MCPMate',
  version: '1.0.0',
  connection: {
    mcp_url: 'https://example.com/wp-json/mcpmate/mcp',
    issuer: 'https://example.com/mcpmate',
    home_url: 'https://example.com',
    public_url: '',
    open_registration: true,
  },
  connectRequest: null,
  onboarding: { status: 'completed', step: 'first_call' },
  lang: 'en_US',
  grants_live: 3,
  pending_approvals: 2,
  rtl: false,
  links: {
    docs: 'https://example.com/docs',
    support: 'https://example.com/support',
    review: 'https://example.com/review',
    feedback: 'https://example.com/feedback',
    upgrade: 'https://example.com/pro',
  },
  pro: { installed: true, active: true },
  debug: true,
}

/** What Head::connectRequest() prints for a valid `?connect=` request. */
const CONNECT_REQUEST = {
  ok: true,
  request: 'Aq3vR9x2Lk0pW7mZ5tY1uB8nC4dE6fG2hJ9kL3sQ0wE',
  client: {
    name: 'Claude',
    uri: 'https://claude.ai',
    vouched_by: 'cimd:claude.ai',
    redirect_host: 'claude.ai',
    localhost_redirect: false,
  },
  scopes: ['site:read', 'site:write'],
  expires_at: new Date(Date.now() + 10 * 60_000).toISOString().replace(/\.\d{3}Z$/, 'Z'),
  site: 'example.com',
  user: { login: 'admin', display_name: 'Niloy', roles: ['administrator'] },
  redirect_origin: 'https://claude.ai',
}

/**
 * The scenario's own connection, pause and app count, as PHP would print
 * them; `rtl` for a right-to-left locale, `connect` for a pending AI app
 * approval, `setup` for the onboarding step.
 */
function serverVariables(site, { rtl = false, connect = false, setup = '' } = {}) {
  const base = {
    ...SERVER_VARIABLES,
    rtl,
    lang: rtl ? 'ar' : SERVER_VARIABLES.lang,
    ...(connect && { connectRequest: CONNECT_REQUEST }),
    ...(setup && { onboarding: { status: 'in_progress', step: setup } }),
  }
  if (!site) {
    return base
  }
  return {
    ...base,
    connection: site.connection(),
    access: site.access(),
    grants_live: site.grants().filter((grant) => grant.status !== 'revoked').length,
    pending_approvals: site.approvals().filter((row) => row.status === 'pending').length,
  }
}

function harnessHtml(site, flags = {}) {
  const rtl = Boolean(flags.rtl)
  const files = rtl
    ? WP_STYLES.map((file) => {
        const flipped = file.replace(/\.min\.css$/, '-rtl.min.css')
        return fs.existsSync(path.join(WP_ROOT, flipped)) ? flipped : file
      })
    : WP_STYLES
  const styles = files.map(
    (file) => `<style data-wp="${file}">${fs.readFileSync(path.join(WP_ROOT, file), 'utf8')}</style>`,
  ).join('\n')

  return `<!doctype html>
<html lang="${rtl ? 'ar' : 'en-US'}" dir="${rtl ? 'rtl' : 'ltr'}"${flags.text ? ` style="font-size: ${flags.text}%"` : ''}>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCPMate ‹ UI kit — WordPress</title>
${styles}
<style>
  /* What admin-bar.css does: the fixed bar, and room for it above the page. */
  html { --wp-admin--admin-bar--height: 32px; margin-top: 32px; }
  #wpadminbar { position: fixed; inset: 0 0 auto; height: 32px; background: #1d2327; z-index: 99999; }
  @media (width <= 782px) { html { --wp-admin--admin-bar--height: 46px; margin-top: 46px; } #wpadminbar { height: 46px; } }
  @media (width <= 600px) { #wpadminbar { position: absolute; } }
</style>
<script>window.MCPMATE_ = ${JSON.stringify(serverVariables(site, flags))};</script>
</head>
<body class="wp-admin wp-core-ui auto-fold toplevel_page_mcpmate${rtl ? ' rtl' : ''}">
<div id="wpadminbar" aria-hidden="true"></div>
<div id="wpwrap"><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">
<div id="mcpmate-root"><div id="mcpmate-app"></div><div id="mcpmate-portal"></div></div>
</div></div></div></div>
<script type="module" src="/src/main.tsx"></script>
</body>
</html>`
}

async function startServer() {
  process.chdir(ROOT)

  const server = await createServer({
    configFile: path.join(ROOT, 'vite.config.ts'),
    mode: 'development',
    logLevel: 'error',
    server: { port: 3177, strictPort: false, hmr: false },
    plugins: [
      {
        name: 'mcpmate-ui-kit-harness',
        configureServer(viteServer) {
          // One fake site per harness load: `?scenario=&fail=&hang=` (comma
          // lists); `&slow=<ms>` delays every answer.
          let site = null
          let slow = 0

          viteServer.middlewares.use(async (req, res, next) => {
            const url = new URL(req.url ?? '/', 'http://localhost')
            const answer = (response) => {
              if (response.hang) {
                return
              }
              setTimeout(() => {
                res.statusCode = response.status
                res.setHeader('Content-Type', 'application/json')
                res.end(JSON.stringify(response.body))
              }, slow)
            }

            if (url.pathname.endsWith('/wp-admin/admin-ajax.php') && site) {
              const chunks = []
              for await (const chunk of req) chunks.push(chunk)
              const form = await new Request('http://localhost', {
                method: 'POST',
                headers: { 'content-type': req.headers['content-type'] ?? '' },
                body: Buffer.concat(chunks),
              }).formData()
              return answer(site.handle(url.href, Object.fromEntries(form)))
            }

            if (url.pathname.includes('/wp-json/mcpmate/v1/') && site) {
              const chunks = []
              for await (const chunk of req) chunks.push(chunk)
              return answer(
                site.handle(url.href, null, {
                  method: req.method,
                  body: chunks.length ? Buffer.concat(chunks).toString('utf8') : null,
                }),
              )
            }

            if (!url.pathname.endsWith(HARNESS)) {
              return next()
            }

            const scenario = url.searchParams.get('scenario')
            const list = (key) => (url.searchParams.get(key) ?? '').split(',').filter(Boolean)
            site = scenario
              ? (await viteServer.ssrLoadModule(FAKE_SITE)).createFakeSite({
                  scenario,
                  fail: list('fail'),
                  hang: list('hang'),
                })
              : null
            slow = Number(url.searchParams.get('slow') ?? 0)
            const flags = {
              rtl: url.searchParams.has('rtl'),
              connect: url.searchParams.has('connect'),
              setup: url.searchParams.get('setup') ?? '',
              // The browser's text size, in percent (WCAG 1.4.4).
              text: Number(url.searchParams.get('text') ?? 0),
            }
            const html = await viteServer.transformIndexHtml(req.url, harnessHtml(site, flags))
            res.setHeader('Content-Type', 'text/html; charset=utf-8')
            res.end(html)
          })
        },
      },
    ],
  })
  await server.listen()

  let loads = 0
  const origin = server.resolvedUrls.local[0].replace(/\/+$/, '')
  const page = `${origin.replace(/\/wp-content.*$/, '')}${server.config.base}${HARNESS}`
  return {
    server,
    url: `${page}#/ui-kit`,
    /** A page of the app against a fake site: `pageUrl('many', '/connections')`. */
    // A fresh load each time (`&load=`): a hash-only change would keep the fake site's state.
    pageUrl: (scenario, route, extra = '') => `${page}?scenario=${scenario}${extra}&load=${++loads}#${route}`,
  }
}

async function launch() {
  try {
    return await chromium.launch()
  } catch {
    return chromium.launch({ channel: 'chrome' })
  }
}

// ── Checks ─────────────────────────────────────

const findings = []

async function axe(page, label) {
  const result = await page.evaluate(() => window.axe.run(document))
  for (const violation of result.violations) {
    findings.push(
      `[${label}] axe ${violation.id} (${violation.impact}): ${violation.help}\n` +
        violation.nodes.map((node) => `    ${node.target.join(' ')}`).join('\n'),
    )
  }
  console.log(`  axe ${label}: ${result.violations.length} violations, ${result.passes.length} rules passed`)

  // A `hidden` element that a display rule shows anyway (a collapsed disclosure that is not).
  const shown = await page.evaluate(() =>
    [...document.querySelectorAll('#mcpmate-root [hidden]')]
      .filter((el) => getComputedStyle(el).display !== 'none')
      .map((el) => `${el.tagName.toLowerCase()}.${String(el.className).split(' ').join('.')}`),
  )
  for (const element of shown) {
    findings.push(`[${label}] ${element} has the hidden attribute but is displayed`)
  }
}

/** Tab through everything; each stop needs a visible 2 px ring and must not be <body>. */
async function tabWalk(page, label) {
  await page.evaluate(() => {
    document.activeElement?.blur()
    window.scrollTo(0, 0)
  })

  const seen = []
  for (let i = 0; i < 400; i++) {
    await page.keyboard.press('Tab')
    const stop = await page.evaluate((index) => {
      const element = document.activeElement
      if (!element || element === document.body) {
        const stops = [
          ...document.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
          ),
        ].filter((el) => el.getClientRects().length > 0 && !el.closest('[inert], [aria-hidden="true"]'))
        return {
          lost: true,
          atEnd: window.__lastStop === stops[stops.length - 1],
        }
      }
      window.__lastStop = element
      // antd and our segments draw the ring on a neighbour of the real control.
      const ringOf = (el) =>
        el.matches('.ant-checkbox-input')
          ? el.nextElementSibling
          : el.matches('.el-segment-input')
            ? el.closest('.el-segment')
            : el.matches('.ant-select-selection-search-input')
              ? el.closest('.ant-select')?.querySelector('.ant-select-selector')
              : el
      const ring = ringOf(element)
      const style = ring ? getComputedStyle(ring) : null
      const visible = Boolean(style && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2)
      const name = (element.getAttribute('aria-label') || element.textContent || element.id || element.className || '')
        .trim()
        .slice(0, 60)
      return {
        key: `${element.tagName.toLowerCase()}#${index}`,
        tag: element.tagName.toLowerCase(),
        name,
        visible,
        first: element.matches('.el-skip-link'),
      }
    }, i)

    if (stop.lost) {
      // Past the document's last stop, Tab leaves for the browser: fine.
      if (!stop.atEnd) {
        findings.push(`[${label}] focus fell back to <body> after ${i + 1} Tab presses`)
      }
      break
    }
    if (stop.first && seen.length > 0) {
      break
    }
    if (!stop.visible) {
      findings.push(`[${label}] no visible focus ring on <${stop.tag}> "${stop.name}"`)
    }
    seen.push(stop.key)
  }
  console.log(`  tab ${label}: ${seen.length} stops`)
}

async function noSidewaysScroll(page, label) {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
  if (overflow > 1) {
    const wide = await page.evaluate(() =>
      [...document.querySelectorAll('#mcpmate-root *')]
        .filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1)
        .filter((el) => !el.parentElement?.closest('.el-json, .el-diff-lines'))
        .slice(0, 5)
        .map(
          (el) =>
            `${el.tagName.toLowerCase()}.${String(el.className).split(' ').join('.')} (${Math.round(el.getBoundingClientRect().right)}px)`,
        ),
    )
    findings.push(`[${label}] the page scrolls sideways by ${overflow}px: ${wide.join(', ')}`)
  }
}

async function open(page, url, width) {
  await page.setViewportSize({ width, height: 900 })
  await page.goto(url)
  await page.getByRole('heading', { level: 1, name: 'UI kit' }).waitFor()
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') })
}

async function closeDialog(page) {
  await page.keyboard.press('Escape')
  await page.waitForFunction(() => !document.querySelector('[aria-modal="true"]'))
}

async function checkUiKit(page, url) {
  await open(page, url, 1280)
  await axe(page, '1280px')
  await tabWalk(page, '1280px')

  const button = (name) => page.getByRole('button', { name, exact: true })

  await button('Open modal').click()
  await page.getByRole('dialog', { name: 'Connect an AI app' }).waitFor()
  await axe(page, 'modal')
  await closeDialog(page)

  await button('Open large modal').click()
  await page.getByRole('dialog').waitFor()
  await axe(page, 'large modal')
  await closeDialog(page)

  await button('Confirm (danger)').click()
  await page.getByRole('alertdialog').waitFor()
  await axe(page, 'confirm dialog (danger)')
  await closeDialog(page)

  await button('Confirm that fails').click()
  await page.getByRole('alertdialog').waitFor()
  await page.getByRole('button', { name: 'Revoke access for Claude' }).click()
  await page.getByRole('alertdialog').getByRole('alert').waitFor()
  await axe(page, 'confirm dialog (error)')
  await closeDialog(page)

  await button('Open drawer').click()
  await page.getByRole('dialog').waitFor()
  await axe(page, 'drawer')
  await closeDialog(page)

  await open(page, url, 320)
  await axe(page, '320px')
  await noSidewaysScroll(page, '320px')
  await button('Menu').click()
  await axe(page, '320px, menu open')
  await noSidewaysScroll(page, '320px, menu open')
}

// ── Pages against the fake site ─────────────

/** Open a page of the app and wait until nothing is loading (unless told it never will). */
async function openPage(page, pageUrl, scenario, route, width, { title, extra = '', settle = true, level = 1 } = {}) {
  await page.setViewportSize({ width, height: 900 })
  await page.goto(pageUrl(scenario, route, extra))
  await page.getByRole('heading', { level, name: title }).first().waitFor()
  if (settle) {
    await page.waitForFunction(() => !document.querySelector('#mcpmate-root [aria-busy="true"]'))
  }
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') })
}

async function shot(page, name) {
  if (SHOTS) {
    fs.mkdirSync(SHOTS, { recursive: true })
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true })
  }
}

/** The focused element's accessible name, roughly as a screen reader reads it. */
function focusedName(page) {
  return page.evaluate(() => {
    const el = document.activeElement
    if (!el || el === document.body) {
      return ''
    }
    const labelled = el.getAttribute('aria-labelledby')
    return (
      el.getAttribute('aria-label') ||
      (labelled && document.getElementById(labelled)?.textContent) ||
      el.closest('label')?.textContent ||
      el.labels?.[0]?.textContent ||
      el.textContent ||
      ''
    )
      .replace(/\s+/g, ' ')
      .trim()
  })
}

/** Press Tab until the focused element is named `name` (keyboard only, like a user). */
async function tabTo(page, name, label, { back = false, limit = 250 } = {}) {
  for (let i = 0; i < limit; i++) {
    await page.keyboard.press(back ? 'Shift+Tab' : 'Tab')
    const current = await focusedName(page)
    if (typeof name === 'string' ? current === name : name.test(current)) {
      return true
    }
  }
  throw new Error(`[${label}] Tab never reached "${name}"`)
}

async function expectFocus(page, name, label) {
  await page
    .waitForFunction(
      (expected) => {
        const el = document.activeElement
        const text = (
          el?.getAttribute('aria-label') ||
          el?.closest('label')?.textContent ||
          el?.labels?.[0]?.textContent ||
          el?.textContent ||
          ''
        )
          .replace(/\s+/g, ' ')
          .trim()
        return text === expected || text.startsWith(expected)
      },
      name,
      { timeout: 3000 },
    )
    .catch(async () => findings.push(`[${label}] focus is on "${await focusedName(page)}", expected "${name}"`))
}

async function expectAnnounced(page, text, label) {
  await page
    .waitForFunction(
      (expected) => [...document.querySelectorAll('[role="status"], [role="alert"]')].some((el) => el.textContent?.includes(expected)),
      text,
      { timeout: 5000 },
    )
    .catch(() => findings.push(`[${label}] "${text}" was not announced`))
}

/** axe at 1280 and 320 px, Tab walk at 1280, no sideways scroll at 320. */
async function pageStates(page, pageUrl, route, title, scenarios) {
  for (const [scenario, extra = '', settle = true] of scenarios) {
    const label = `${title} ${scenario}${extra ? ` ${extra}` : ''}`
    await openPage(page, pageUrl, scenario, route, 1280, { title, extra, settle })
    await axe(page, `${label} 1280px`)
    await tabWalk(page, `${label} 1280px`)
    await shot(page, `${title}-${scenario}${extra ? '-loading' : ''}-1280`.toLowerCase())
    await openPage(page, pageUrl, scenario, route, 320, { title, extra, settle })
    await axe(page, `${label} 320px`)
    await noSidewaysScroll(page, `${label} 320px`)
    await shot(page, `${title}-${scenario}${extra ? '-loading' : ''}-320`.toLowerCase())
  }
}

const LOADING = ['&hang=grants/list,stats,audit-log,connection/status', false]

async function checkDashboard(page, pageUrl) {
  await pageStates(page, pageUrl, '/', 'Dashboard', [
    ['none'],
    ['one'],
    ['many'],
    ['paused'],
    ['errors'],
    ['one', ...LOADING],
  ])

  // Pause and resume with the keyboard alone.
  const label = 'Dashboard keyboard'
  await openPage(page, pageUrl, 'one', '/', 1280, { title: 'Dashboard' })
  await tabTo(page, 'Pause AI access', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Pause AI access?' }).waitFor()
  await expectFocus(page, 'Cancel', `${label}: pause dialog opens on Cancel`)
  await axe(page, `${label} pause dialog`)
  await page.keyboard.press('Shift+Tab')
  await expectFocus(page, 'Pause AI access', `${label}: Shift+Tab reaches the confirm button`)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'AI access paused.', label)
  await page.getByText('AI access is paused. AI apps get an error until you resume it.').waitFor()
  await page.getByRole('link', { name: 'AI access paused' }).waitFor()
  await expectFocus(page, 'Resume AI access', `${label}: focus stays on the pause/resume button`)
  await axe(page, `${label} paused`)
  await shot(page, 'dashboard-paused-after-keyboard-1280')
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'AI access resumed.', label)

  // Site checks: run, then open the list.
  await tabTo(page, 'Run checks', label)
  await page.keyboard.press('Enter')
  await page.getByText('7 of 9 passed').first().waitFor()
  await expectAnnounced(page, '7 of 9 passed', label)
  await tabTo(page, 'Show all checks (10)', label)
  await page.keyboard.press('Enter')
  await page.getByText('Sign-in details reach WordPress').waitFor()
  await axe(page, `${label} checks open`)
  await shot(page, 'dashboard-checks-open-1280')
}

async function checkConnections(page, pageUrl) {
  await pageStates(page, pageUrl, '/connections', 'Connections', [
    ['none'],
    ['one'],
    ['many'],
    ['errors'],
    ['one', ...LOADING],
  ])

  const label = 'Connections keyboard'
  await openPage(page, pageUrl, 'many', '/connections', 1280, { title: 'Connections' })

  // Edit access: Custom with the arrow keys, change one group, save.
  await tabTo(page, 'Edit access for Claude', label)
  await page.keyboard.press('Enter')
  const access = page.getByRole('dialog', { name: 'Access for Claude' })
  await access.waitFor()
  await expectFocus(page, 'Full access', `${label}: Edit access opens on the checked level`)
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('ArrowDown')
  await access.getByRole('group', { name: 'Site overview' }).waitFor()
  if (!(await access.getByRole('radio', { name: /Custom/ }).isChecked())) {
    findings.push(`[${label}] arrow keys did not select Custom`)
  }
  await axe(page, `${label} edit access, custom`)
  await shot(page, 'connections-edit-access-custom-1280')
  await tabTo(page, 'WP-CLI commands', label)
  await page.keyboard.press('Space')
  await tabTo(page, 'Show WP-CLI commands', label)
  await page.keyboard.press('Enter')
  await tabTo(page, 'Save access', label)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Access for Claude saved.', label)
  await expectFocus(page, 'Edit access for Claude', `${label}: focus returns to Edit access`)
  await page.getByRole('cell', { name: /Custom, \d+ tools/ }).first().waitFor()

  // Revoke one: opens on Keep access, confirm with Shift+Tab, focus goes to the caption.
  await tabTo(page, 'Revoke access for Claude', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Revoke access for Claude?' }).waitFor()
  await expectFocus(page, 'Keep access', `${label}: revoke dialog opens on Keep access`)
  await axe(page, `${label} revoke dialog`)
  await page.keyboard.press('Shift+Tab')
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Claude can no longer use this site.', label)
  await expectFocus(page, 'AI apps that can use this site', `${label}: focus goes to the table caption after a revoke`)

  // Revoke all: look, then keep access with Esc.
  await tabTo(page, 'Revoke all 11 apps', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Revoke access for all AI apps?' }).waitFor()
  await axe(page, `${label} revoke all dialog`)
  await page.keyboard.press('Escape')
  await expectFocus(page, 'Revoke all 11 apps', `${label}: Esc returns focus to Revoke all`)

  // Revoked list disclosure.
  await tabTo(page, 'Revoked in the last 30 days (3)', label, { back: true })
  await page.keyboard.press('Enter')
  await page.getByText('Old Claude').waitFor()
  await axe(page, `${label} revoked list open`)

  // Connect an AI app: the tab is kept in the hash; Esc returns focus.
  await tabTo(page, 'Connect an AI app', label, { back: true })
  await page.keyboard.press('Enter')
  await page.getByRole('dialog', { name: 'Connect an AI app' }).waitFor()
  await axe(page, `${label} connect modal`)
  await shot(page, 'connections-connect-modal-1280')
  await tabTo(page, 'Claude', label)
  await page.keyboard.press('ArrowRight')
  await page.waitForFunction(() => window.location.hash.includes('connect-app=chatgpt'))
  await page.keyboard.press('Escape')
  await expectFocus(page, 'Connect an AI app', `${label}: Esc returns focus to Connect an AI app`)

  // Registered clients: revoke one (opens on Keep it), then allow it again.
  await tabTo(page, 'Revoke ChatGPT', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Revoke ChatGPT?' }).waitFor()
  await expectFocus(page, 'Keep it', `${label}: client revoke dialog opens on Keep it`)
  await axe(page, `${label} client revoke dialog`)
  await shot(page, 'connections-client-revoke-1280')
  await page.keyboard.press('Shift+Tab')
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'ChatGPT is revoked.', label)
  await expectFocus(page, 'Allow ChatGPT again', `${label}: focus stays on the row's button, now Allow again`)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'ChatGPT can connect again.', label)
  await axe(page, `${label} clients after allow again`)

  // Token lifetimes and client registration: change one, save with the keyboard.
  await tabTo(page, 'Access tokens last', label)
  await page.keyboard.type('60')
  await page.getByText('1 unsaved change').waitFor()
  await tabTo(page, 'Save changes', label)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Saved.', label)
  await axe(page, `${label} sign-in settings saved`)
  await shot(page, 'connections-sign-in-settings-1280')

  // Settings' link lands on the card's heading.
  await openPage(page, pageUrl, 'one', '/connections?section=sign-in', 1280, { title: 'Connections' })
  await expectFocus(page, 'Token lifetimes and client registration', `${label}: ?section=sign-in focuses the card`)

  // 320 px: the table is cards, the modal is full screen.
  await openPage(page, pageUrl, 'many', '/connections', 320, { title: 'Connections' })
  await page.getByRole('button', { name: 'Edit access for Claude', exact: true }).click()
  await page.getByRole('dialog', { name: 'Access for Claude' }).waitFor()
  await page.getByRole('radio', { name: /Custom/ }).check()
  await page.getByRole('group', { name: 'Site overview' }).waitFor()
  await axe(page, `${label} 320px edit access`)
  await noSidewaysScroll(page, `${label} 320px edit access`)
  await shot(page, 'connections-edit-access-320')
}

/** The checked state of a checkbox as assistive tech gets it: true, false or "mixed". */
function checkboxState(page, name) {
  return page.evaluate((label) => {
    const box = [...document.querySelectorAll('#mcpmate-root input[type="checkbox"]')].find(
      (input) => input.closest('label')?.textContent?.replace(/\s+/g, ' ').trim() === label,
    )
    if (!box) {
      return null
    }
    return box.getAttribute('aria-checked') === 'mixed' ? 'mixed' : box.checked
  }, name)
}

async function expectCheckbox(page, name, expected, label) {
  await page
    .waitForFunction(
      ([boxName, want]) => {
        const box = [...document.querySelectorAll('#mcpmate-root input[type="checkbox"]')].find(
          (input) => input.closest('label')?.textContent?.replace(/\s+/g, ' ').trim() === boxName,
        )
        const state = box ? (box.getAttribute('aria-checked') === 'mixed' ? 'mixed' : box.checked) : null
        return state === want
      },
      [name, expected],
      { timeout: 5000 },
    )
    .catch(async () =>
      findings.push(`[${label}] "${name}" is ${JSON.stringify(await checkboxState(page, name))}, expected ${JSON.stringify(expected)}`),
    )
}

async function checkTools(page, pageUrl) {
  for (const route of ['/tools', '/tools/integrations', '/tools/abilities']) {
    await pageStates(page, pageUrl, route, 'Tools', [['one']])
  }
  await pageStates(page, pageUrl, '/tools', 'Tools', [['errors'], ['one', '&hang=tools', false]])
  // Skills: saved skills beside the playbooks ("many" has three and one in the trash), the trash, none.
  await pageStates(page, pageUrl, '/tools/skills', 'Tools', [['many'], ['one']])
  await pageStates(page, pageUrl, '/tools/skills?status=trash', 'Tools', [['many']])

  const label = 'Tools keyboard'
  await openPage(page, pageUrl, 'one', '/tools', 1280, { title: 'Tools' })

  // The tri-state group checkbox: mixed → all on → none, each saved and announced.
  await expectCheckbox(page, 'Theme drafts', 'mixed', `${label}: Theme drafts starts mixed`)
  await tabTo(page, 'Theme drafts', label)
  await page.keyboard.press('Space')
  await expectAnnounced(page, 'Theme drafts: 5 of 5 on. Saved.', label)
  await expectCheckbox(page, 'Theme drafts', true, `${label}: Space on a mixed group turns it all on`)
  await page.getByText('Saved. Connected AI apps may need to reconnect to see the change.').waitFor()
  await expectFocus(page, 'Theme drafts', `${label}: focus stays on the group checkbox`)
  await axe(page, `${label} saved`)
  await shot(page, 'tools-saved-1280')
  await page.keyboard.press('Space')
  await expectAnnounced(page, 'Theme drafts: 0 of 5 on. Saved.', label)
  await expectCheckbox(page, 'Theme drafts', false, `${label}: Space on a checked group turns it all off`)

  // One tool.
  await tabTo(page, 'Upload an image or PDF', label)
  await page.keyboard.press('Space')
  await expectAnnounced(page, 'Upload an image or PDF turned on. Saved.', label)
  await expectCheckbox(page, 'Upload an image or PDF', true, `${label}: Space turns one tool on`)

  // Disclosure: Hide and Show a group.
  await tabTo(page, 'Hide WP-CLI commands', label)
  await page.keyboard.press('Enter')
  await expectFocus(page, 'Show WP-CLI commands', `${label}: the disclosure keeps focus`)
  await page.keyboard.press('Enter')

  // Filter with the arrow keys, then search.
  await tabTo(page, 'All', label, { back: true })
  await page.keyboard.press('ArrowRight')
  await expectAnnounced(page, '12 tools match.', label)
  await page.keyboard.press('ArrowLeft')
  await expectAnnounced(page, '20 tools', label)
  await page.keyboard.press('Tab')
  await page
    .waitForFunction(() => document.activeElement?.matches('input[type="search"]'), null, { timeout: 3000 })
    .catch(() => findings.push(`[${label}] Tab from the filter did not reach Search tools`))
  await page.keyboard.type('draft')
  await expectAnnounced(page, '5 tools match.', label)
  await page.getByRole('link', { name: '1 more in Abilities' }).waitFor()
  await axe(page, `${label} search`)
  await shot(page, 'tools-search-1280')
  await page.keyboard.press('ControlOrMeta+A')
  await page.keyboard.type('zzz')
  await page.getByRole('heading', { name: 'No tools match \u201czzz\u201d.' }).waitFor()
  await tabTo(page, 'Clear search', label)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, '20 tools', label)

  // Descriptions.
  await tabTo(page, 'Show descriptions', label)
  await page.keyboard.press('Space')
  await page.getByText('Site name, versions, theme, plugins and settings.').waitFor()
  await axe(page, `${label} descriptions`)

  // Tabs: arrow keys move between sources and keep the page's state.
  // Back to the panel (named by its tab), then to the tab itself.
  await tabTo(page, /^Core, 11 of 20 on/, label, { back: true })
  await page.keyboard.press('Shift+Tab')
  if ((await page.evaluate(() => document.activeElement?.getAttribute('role'))) !== 'tab') {
    findings.push(`[${label}] Shift+Tab from the panel did not reach the Core tab`)
  }
  await page.keyboard.press('ArrowRight')
  await page.waitForFunction(() => window.location.hash.startsWith('#/tools/integrations'))
  await page.getByRole('button', { name: 'Contact Form 7, 2 of 4 on' }).waitFor()
  // The next tab (End goes to the last one, Skills or Sandbox).
  await page.keyboard.press('ArrowRight')
  await page.waitForFunction(() => window.location.hash.startsWith('#/tools/abilities'))
  await page.getByRole('navigation', { name: 'Ability sources' }).waitFor()
  await axe(page, `${label} abilities`)

  // The abilities ledger: a source opened by keyboard (`?source=`), then all of it turned on.
  const ledger = `${label} abilities ledger`
  await tabTo(page, /^Shop, 0 of 2 on/, ledger)
  await page.keyboard.press('Enter')
  await page.waitForFunction(() => window.location.hash.includes('source=abilities%3AShop'))
  await page.getByRole('heading', { level: 2, name: 'Shop' }).waitFor()
  await tabTo(page, 'All on', ledger)
  await page.keyboard.press('Space')
  await expectAnnounced(page, 'Shop: 2 of 2 on. Saved.', ledger)
  await page
    .getByRole('checkbox', { name: 'Export orders', checked: true })
    .waitFor({ timeout: 5000 })
    .catch(() => findings.push(`[${ledger}] the rows did not follow All on`))
  await axe(page, `${ledger} saved`)
  await shot(page, 'tools-abilities-ledger-1280')

  // A plugin's dialog: opened from its tile (`?plugin=`), its switches saved, Esc back to the tile.
  const plugin = `${label} plugin`
  await openPage(page, pageUrl, 'one', '/tools/integrations', 1280, { title: 'Tools' })
  await shot(page, 'tools-integrations-tiles-1280')
  await tabTo(page, 'Contact Form 7, 2 of 4 on', plugin)
  await page.keyboard.press('Enter')
  await page.getByRole('dialog', { name: 'Contact Form 7' }).waitFor()
  await page.waitForFunction(() => window.location.hash.includes('plugin=cf7'))
  await axe(page, `${plugin} dialog`)
  await shot(page, 'tools-plugin-dialog-1280')
  await tabTo(page, "Change a form's fields", plugin)
  await page.keyboard.press('Space')
  await expectAnnounced(page, "Change a form's fields turned on. Saved.", plugin)
  await page.keyboard.press('Escape')
  await page
    .waitForFunction(() => !window.location.hash.includes('plugin='), null, { timeout: 3000 })
    .catch(() => findings.push(`[${plugin}] Esc did not close the dialog`))
  await expectFocus(page, 'Contact Form 7, 3 of 4 on', `${plugin}: focus returns to the tile`)
  await openPage(page, pageUrl, 'one', '/tools/integrations?plugin=yoast', 320, { title: 'Tools' })
  await page.getByRole('dialog', { name: 'Yoast SEO' }).waitFor()
  await axe(page, `${plugin} dialog 320px`)
  await noSidewaysScroll(page, `${plugin} dialog 320px`)
  await shot(page, 'tools-plugin-dialog-320')

  // A save that fails: the error has Retry, and Retry works once the site answers.
  await openPage(page, pageUrl, 'one', '/tools', 1280, { title: 'Tools', extra: '&fail=POST%20tools' })
  await tabTo(page, 'Upload an image or PDF', `${label} failing`)
  await page.keyboard.press('Space')
  await page.getByText("Couldn't save your change.").waitFor()
  await expectCheckbox(page, 'Upload an image or PDF', false, `${label} failing: the change is taken back`)
  await expectFocus(page, 'Upload an image or PDF', `${label} failing: focus stays on the checkbox`)
  await tabTo(page, 'Retry', `${label} failing`)
  await axe(page, `${label} save failed`)
  await shot(page, 'tools-save-failed-1280')
}

async function checkActivity(page, pageUrl) {
  await pageStates(page, pageUrl, '/activity', 'Activity', [
    ['none'],
    ['one'],
    ['many'],
    ['errors'],
    ['one', '&hang=audit-log,tools,grants/list,preferences', false],
  ])
  await pageStates(page, pageUrl, '/activity/changes', 'Activity', [['none'], ['one'], ['errors']])

  const label = 'Activity keyboard'
  await openPage(page, pageUrl, 'many', '/activity', 1280, {
    title: 'Activity',
  })

  // Search: applies after a pause, announces the count, focus stays in the field.
  await tabTo(page, 'Search', label)
  await page.keyboard.type('home')
  await expectAnnounced(page, 'match', label)
  await expectFocus(page, 'Search', `${label}: focus stays in Search`)
  await page.waitForFunction(() => window.location.hash.includes('q=home'))
  await page.keyboard.press('ControlOrMeta+A')
  await page.keyboard.press('Backspace')
  await page.keyboard.press('Enter')
  await page.waitForFunction(() => !window.location.hash.includes('q='))

  // An entry: the drawer opens on its title and Esc gives focus back.
  await tabTo(page, /^View details: elementor_save · /, label)
  const opener = await focusedName(page)
  await page.keyboard.press('Enter')
  await page.getByRole('dialog').getByRole('region', { name: 'Arguments' }).waitFor()
  await page.waitForFunction(() => window.location.hash.includes('entry='))
  await axe(page, `${label} entry drawer`)
  await shot(page, 'activity-entry-1280')
  await page.keyboard.press('Escape')
  await page.waitForFunction(() => !document.querySelector('[aria-modal="true"]'))
  await expectFocus(page, opener, `${label}: Esc returns focus to View details`)

  // Sort by duration, then page 2: focus goes to the caption.
  await tabTo(page, /^Duration, not sorted/, label, { back: true })
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Sorted by Duration, longest first.', label)
  await page.waitForFunction(() => window.location.hash.includes('sort=duration'))
  await tabTo(page, 'Page 2', label)
  await page.keyboard.press('Enter')
  await expectFocus(page, 'Audit log, longest first.', `${label}: a page change focuses the caption`)
  await expectAnnounced(page, 'Page 2 of 2.', label)

  // Remove old entries: the dialog opens on Keep entries; confirm with Shift+Tab.
  await tabTo(page, 'Remove entries older than 90 days now', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Remove old entries now?' }).waitFor()
  await expectFocus(page, 'Keep entries', `${label}: the purge dialog opens on Keep entries`)
  await axe(page, `${label} purge dialog`)
  await page.keyboard.press('Shift+Tab')
  await expectFocus(page, 'Remove 1,240 entries', `${label}: Shift+Tab reaches the confirm button`)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Removed 1,240 entries.', label)

  // The tabs: back to the panel (named by its tab), then the tab; arrow keys move to Change history.
  await tabTo(page, 'Audit log', label, { back: true })
  await page.keyboard.press('Shift+Tab')
  if ((await page.evaluate(() => document.activeElement?.getAttribute('role'))) !== 'tab') {
    findings.push(`[${label}] Shift+Tab from the panel did not reach the Audit log tab`)
  }
  await page.keyboard.press('ArrowRight')
  await page.waitForFunction(() => window.location.hash.startsWith('#/activity/changes'))

  // Change history: the before-and-after drawer, the folded lines, Esc.
  await openPage(page, pageUrl, 'one', '/activity/changes', 1280, {
    title: 'Activity',
  })
  await tabTo(page, 'View before and after: Page "Home" (#41)', label)
  await page.keyboard.press('Enter')
  const diff = page.getByRole('dialog', {
    name: 'Before and after: Page "Home" (#41)',
  })
  await diff.getByRole('region', { name: 'Changes to Content' }).waitFor()
  await axe(page, `${label} diff drawer`)
  await shot(page, 'activity-diff-1280')
  await tabTo(page, 'Show 10 unchanged lines', label)
  await page.keyboard.press('Enter')
  await expectFocus(page, 'Hide 10 unchanged lines', `${label}: the fold keeps focus`)
  await tabTo(page, 'Originating call', label)
  await page.keyboard.press('Escape')
  await expectFocus(page, 'View before and after: Page "Home" (#41)', `${label}: Esc returns focus`)

  // 360 and 320 px: cards instead of the table, nothing scrolls sideways, drawers full screen.
  for (const width of [360, 320]) {
    await openPage(page, pageUrl, 'many', '/activity?entry=99', width, {
      title: 'Activity',
    })
    await page.getByRole('dialog').getByRole('region', { name: 'Arguments' }).waitFor()
    await axe(page, `${label} ${width}px entry drawer`)
    await noSidewaysScroll(page, `${label} ${width}px entry drawer`)
    await closeDialog(page)
    if (!(await page.locator('.el-table-cards').count())) {
      findings.push(`[${label} ${width}px] the audit log is not shown as cards`)
    }
    await tabTo(page, 'Filters', `${label} ${width}px`)
    await page.keyboard.press('Enter')
    await page.getByRole('combobox', { name: 'Period' }).waitFor()
    await axe(page, `${label} ${width}px filters open`)
    await noSidewaysScroll(page, `${label} ${width}px filters open`)
    await shot(page, `activity-filters-${width}`)
    await openPage(page, pageUrl, 'one', '/activity/changes?change=12', width, {
      title: 'Activity',
    })
    await page.getByRole('dialog').getByRole('region', { name: 'Changes to Content' }).waitFor()
    await axe(page, `${label} ${width}px diff drawer`)
    await noSidewaysScroll(page, `${label} ${width}px diff drawer`)
    await shot(page, `activity-diff-${width}`)
  }
}

async function checkSettings(page, pageUrl) {
  await pageStates(page, pageUrl, '/settings', 'Settings', [
    ['one'],
    ['errors'],
    ['one', '&hang=preferences,tools', false],
  ])

  const label = 'Settings keyboard'
  await openPage(page, pageUrl, 'one', '/settings', 1280, {
    title: 'Settings',
  })

  // A value the server would refuse: the error is shown at once, Save focuses it.
  await tabTo(page, 'Keep the audit log for', label)
  await page.keyboard.type('60')
  await tabTo(page, 'Keep read calls for', label)
  await page.keyboard.type('90')
  await page.getByText("Read calls can't be kept longer than the audit log (60 days).").waitFor()
  await axe(page, `${label} inline error`)
  await shot(page, 'settings-inline-error-1280')
  await tabTo(page, 'Save changes', label)
  await page.keyboard.press('Enter')
  await page.getByText('Fix 1 setting to save.').waitFor()
  await expectFocus(page, 'Keep read calls for', `${label}: Save moves focus to the invalid setting`)

  // Fix it and save.
  await page.keyboard.type('30')
  await tabTo(page, 'Save changes', label)
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Saved.', label)

  // Unsaved, then leave: the dialog opens on Stay on this page.
  await tabTo(page, 'Also log read calls', label, { back: true })
  await page.keyboard.press('Space')
  await page.getByText('1 unsaved change').waitFor()
  await tabTo(page, 'Tools', label, { back: true })
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Leave without saving?' }).waitFor()
  await expectFocus(page, 'Stay on this page', `${label}: the leave dialog opens on Stay on this page`)
  await axe(page, `${label} leave dialog`)
  await shot(page, 'settings-leave-1280')
  await page.keyboard.press('Enter')
  await page.waitForFunction(() => !document.querySelector('[aria-modal="true"]'))
  if (!(await page.evaluate(() => window.location.hash.startsWith('#/settings')))) {
    findings.push(`[${label}] Stay on this page left the page`)
  }

  // Turning change history off asks first; Keep it on keeps it on.
  await tabTo(page, 'Change history', label)
  await page.keyboard.press('Space')
  await page.getByRole('alertdialog', { name: 'Turn off change history?' }).waitFor()
  await expectFocus(page, 'Keep it on', `${label}: the dialog opens on Keep it on`)
  await page.keyboard.press('Enter')
  await expectFocus(page, 'Change history', `${label}: focus returns to the switch`)

  // ?section= focuses the section heading.
  await openPage(page, pageUrl, 'one', '/settings?section=advanced', 1280, {
    title: 'Settings',
  })
  await expectFocus(page, 'Advanced', `${label}: ?section= focuses the section`)

  for (const width of [360, 320]) {
    await openPage(page, pageUrl, 'one', '/settings', width, {
      title: 'Settings',
    })
    await page.keyboard.press('Tab')
    await tabTo(page, 'Keep read calls for', `${label} ${width}px`)
    await page.keyboard.type('90')
    await axe(page, `${label} ${width}px unsaved`)
    await noSidewaysScroll(page, `${label} ${width}px unsaved`)
    await shot(page, `settings-unsaved-${width}`)
  }
}

/** MCPMate Pro's design system: the list, the starter library, a design open, a new one. */
async function checkDesign(page, pageUrl) {
  await pageStates(page, pageUrl, '/tools/design', 'Tools', [['one'], ['none'], ['one', '&fail=designs/list']])
  // A design open in its drawer (focus is trapped there, so no page-wide Tab walk).
  for (const [route, name] of [['/tools/design?design=12', 'open'], ['/tools/design?design=new', 'new']]) {
    for (const width of [1280, 320]) {
      await openPage(page, pageUrl, 'one', route, width, { title: 'Tools' })
      await page.getByRole('dialog').waitFor()
      await axe(page, `Design drawer ${name} ${width}px`)
      await noSidewaysScroll(page, `Design drawer ${name} ${width}px`)
      await shot(page, `design-drawer-${name}-${width}`)
    }
  }
}

/** MCPMate Pro's block editor queue: the list, a batch waiting, a finalized one. */
async function checkBlockQueue(page, pageUrl) {
  await pageStates(page, pageUrl, '/activity/block-queue', 'Activity', [
    ['one'],
    ['none'],
    ['one', '&fail=block-queue'],
    ['one', '&hang=block-queue', false],
  ])
  await pageStates(page, pageUrl, '/activity/block-queue/7', 'Activity', [['one'], ['one', '&hang=block-queue/7', false]])
  await pageStates(page, pageUrl, '/activity/block-queue/5', 'Activity', [['one']])
}

async function checkApprovals(page, pageUrl) {
  await pageStates(page, pageUrl, '/activity/approvals', 'Activity', [
    ['one'],
    ['none'],
    ['errors'],
    ['one', '&hang=approvals', false],
  ])
  await pageStates(page, pageUrl, '/activity/approvals/11', 'Activity', [['one'], ['errors'], ['one', '&hang=approvals/show', false]])

  const label = 'Approvals keyboard'
  await openPage(page, pageUrl, 'one', '/activity/approvals', 1280, { title: 'Activity' })
  if (!(await page.getByRole('tab', { name: /Approvals, 1 waiting/ }).count())) {
    findings.push(`[${label}] the Approvals tab does not say how many requests are waiting`)
  }
  await page.locator('#mcpmate-root caption').first().waitFor()

  // The filter is a labelled radio group; arrow keys move it.
  await tabTo(page, 'Waiting', label)
  await page.keyboard.press('ArrowRight')
  await expectAnnounced(page, 'request', label)
  await page.keyboard.press('ArrowLeft')

  // Open the pending request, then Reject: a dialog that opens on Cancel.
  await tabTo(page, /^Review request #12/, label)
  await page.keyboard.press('Enter')
  await page.getByRole('heading', { name: /Approval request #12/ }).waitFor()
  await expectFocus(page, 'Approval request #12', `${label}: opening a request focuses its heading`)
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') })
  await axe(page, `${label} detail pending`)
  await shot(page, 'approvals-detail-pending-1280')
  await tabTo(page, 'Reject request', label)
  await page.keyboard.press('Enter')
  await page.getByRole('alertdialog', { name: 'Reject this request?' }).waitFor()
  await expectFocus(page, 'Cancel', `${label}: the reject dialog opens on Cancel`)
  await axe(page, `${label} reject dialog`)
  await page.keyboard.press('Escape')
  await expectFocus(page, 'Reject request', `${label}: Esc returns focus to Reject request`)

  // Approve: names what runs, the outcome is announced and focus is not lost.
  await tabTo(page, /^Approve and run /, label, { back: true })
  await page.keyboard.press('Enter')
  await expectAnnounced(page, 'Approved and run.', label)
  await page.waitForFunction(() => document.activeElement && document.activeElement !== document.body, null, { timeout: 3000 })
    .catch(() => findings.push(`[${label}] focus fell back to <body> after Approve`))
  await axe(page, `${label} detail run`)
  await shot(page, 'approvals-detail-run-1280')

  // A decision the server refuses: the error stays on the page, not in a toast.
  await openPage(page, pageUrl, 'one', '/activity/approvals/12', 1280, { title: 'Activity', extra: '&fail=approvals/approve' })
  await tabTo(page, /^Approve and run /, `${label} failing`)
  await page.keyboard.press('Enter')
  await page.locator('#mcpmate-root [role="alert"]', { hasText: "Couldn't approve this request." }).waitFor({ timeout: 5000 })
    .catch(() => findings.push(`[${label} failing] a failed approval is not shown on the page`))

  for (const width of [360, 320]) {
    await openPage(page, pageUrl, 'many', '/activity/approvals?status=all', width, { title: 'Activity' })
    await axe(page, `${label} ${width}px list`)
    await noSidewaysScroll(page, `${label} ${width}px list`)
    await openPage(page, pageUrl, 'one', '/activity/approvals/12', width, { title: 'Activity' })
    await axe(page, `${label} ${width}px detail`)
    await noSidewaysScroll(page, `${label} ${width}px detail`)
    await shot(page, `approvals-detail-${width}`)
  }
}

/** Screens outside the navigation: Not found, Setup, Connect (approve an AI app). */
async function checkScreens(page, pageUrl) {
  for (const width of [1280, 320]) {
    await openPage(page, pageUrl, 'one', '/no-such-page', width, { title: 'Page not found' })
    await axe(page, `Not found ${width}px`)
    await noSidewaysScroll(page, `Not found ${width}px`)

    await openPage(page, pageUrl, 'one', '/setup', width, { title: /./, level: 2, extra: '&setup=welcome' })
    if (!(await page.getByRole('heading', { level: 1 }).count())) {
      findings.push(`[Setup ${width}px] the page has no <h1>`)
    }
    await axe(page, `Setup welcome ${width}px`)
    await noSidewaysScroll(page, `Setup welcome ${width}px`)
    if (width === 1280) {
      await tabWalk(page, 'Setup welcome 1280px')
    }
    await shot(page, `setup-welcome-${width}`)

    await openPage(page, pageUrl, 'one', '/connect', width, { title: /Claude/, extra: '&connect=1' })
    await axe(page, `Connect ${width}px`)
    await noSidewaysScroll(page, `Connect ${width}px`)
    if (width === 1280) {
      await tabWalk(page, 'Connect 1280px')
    }
    await shot(page, `connect-${width}`)
  }
}

const MAIN_ROUTES = [
  ['/', 'Dashboard'],
  ['/connections', 'Connections'],
  ['/tools', 'Tools'],
  ['/activity', 'Activity'],
  ['/activity/changes', 'Activity'],
  ['/activity/approvals/12', 'Activity'],
  ['/settings', 'Settings'],
]

/** Right to left: axe, no sideways scroll, the nav and drawers mirrored. */
async function checkRtl(page, pageUrl) {
  for (const [route, title] of MAIN_ROUTES) {
    for (const width of [1280, 320]) {
      const label = `RTL ${route} ${width}px`
      await openPage(page, pageUrl, 'many', route, width, { title, extra: '&rtl=1' })
      await axe(page, label)
      await noSidewaysScroll(page, label)
      await shot(page, `rtl${route.replace(/\//g, '-')}-${width}`)
    }
  }

  const label = 'RTL layout'
  await openPage(page, pageUrl, 'many', '/', 1280, { title: 'Dashboard', extra: '&rtl=1' })
  const [first, last] = await page.evaluate(() => {
    const links = [...document.querySelectorAll('.el-nav a')]
    return [links[0], links[links.length - 1]].map((el) => el?.getBoundingClientRect().left ?? 0)
  })
  if (!(first > last)) {
    findings.push(`[${label}] the navigation does not run right to left`)
  }
  await tabWalk(page, `${label} Dashboard`)

  await openPage(page, pageUrl, 'many', '/activity?entry=99', 1280, { title: 'Activity', extra: '&rtl=1' })
  const drawer = page.getByRole('dialog')
  await drawer.getByRole('region', { name: 'Arguments' }).waitFor()
  const box = await drawer.boundingBox()
  if (!box || box.x > 10) {
    findings.push(`[${label}] the entry drawer does not open from the left in RTL (x = ${box?.x})`)
  }
  await axe(page, `${label} entry drawer`)

  // Arrow keys follow the reading direction in the tabs.
  await openPage(page, pageUrl, 'one', '/tools', 1280, { title: 'Tools', extra: '&rtl=1' })
  await tabTo(page, /^Core, /, label)
  await page.keyboard.press('ArrowLeft')
  await page.waitForFunction(() => window.location.hash.startsWith('#/tools/integrations'), null, { timeout: 3000 })
    .catch(() => findings.push(`[${label}] ArrowLeft in RTL did not move to the next tab`))
}

/** Text that its box cuts off: overflow hidden and content larger than the box. */
async function clippedText(page, label) {
  const clipped = await page.evaluate(() =>
    [...document.querySelectorAll('#mcpmate-root *')]
      .filter((el) => {
        if (el.closest('.sr-only, [aria-hidden="true"], .el-skeleton, input, textarea, select')) {
          return false
        }
        if (!el.childNodes.length || ![...el.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim())) {
          return false
        }
        const style = getComputedStyle(el)
        const hides = (value) => value === 'hidden' || value === 'clip'
        return (
          (hides(style.overflowX) && el.scrollWidth > el.clientWidth + 1) ||
          (hides(style.overflowY) && el.scrollHeight > el.clientHeight + 1)
        )
      })
      .slice(0, 5)
      .map((el) => `${el.tagName.toLowerCase()}.${String(el.className).split(' ').join('.')} "${el.textContent.trim().slice(0, 40)}"`),
  )
  for (const element of clipped) {
    findings.push(`[${label}] text is cut off: ${element}`)
  }
}

/** 200 % zoom on a 1280 px screen (640 px), and 200 % text size at 1280 px. */
async function checkZoom(page, pageUrl) {
  for (const [route, title] of [...MAIN_ROUTES, ['/activity/approvals', 'Activity']]) {
    const label = `Zoom 200% ${route}`
    await openPage(page, pageUrl, 'many', route, 640, { title })
    await axe(page, `${label} (640px)`)
    await noSidewaysScroll(page, `${label} (640px)`)
    await clippedText(page, `${label} (640px)`)
    await shot(page, `zoom${route.replace(/\//g, '-')}-640`)

    await openPage(page, pageUrl, 'many', route, 1280, { title, extra: '&text=200' })
    await noSidewaysScroll(page, `Text 200% ${route}`)
    await clippedText(page, `Text 200% ${route}`)
    // WCAG 1.4.12: text spacing overrides clip nothing.
    await openPage(page, pageUrl, 'many', route, 1280, { title })
    await page.evaluate(() => {
      const style = document.createElement('style')
      style.textContent =
        '#mcpmate-root * { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; } #mcpmate-root p { margin-bottom: 2em !important; }'
      document.head.append(style)
    })
    await page.waitForTimeout(200)
    await clippedText(page, `Text spacing ${route}`)
    await shot(page, `text-spacing${route.replace(/\//g, '-')}-1280`)
  }
}

/** Under prefers-reduced-motion, nothing animates for longer than a frame. */
async function checkMotion(page, pageUrl) {
  const loading = '&hang=grants/list,stats,audit-log,connection/status,tools,changes/list,preferences,approvals,approvals/show'
  for (const [route, title] of MAIN_ROUTES) {
    await openPage(page, pageUrl, 'one', route, 1280, { title, extra: loading, settle: false })
    await page.waitForTimeout(300)
    const moving = await page.evaluate(() =>
      document
        .getAnimations()
        .filter((animation) => animation.playState === 'running')
        .map((animation) => {
          const timing = animation.effect?.getComputedTiming?.() ?? {}
          const target = animation.effect?.target
          return { long: timing.iterations === Infinity || (timing.duration ?? 0) > 50, name: animation.animationName ?? '', target: target ? `${target.tagName?.toLowerCase()}.${String(target.className?.baseVal ?? target.className ?? '').split(' ').join('.')}` : '' }
        })
        .filter((item) => item.long),
    )
    for (const item of moving.slice(0, 3)) {
      findings.push(`[Reduced motion ${route}] still animating: ${item.name} on ${item.target}`)
    }
    console.log(`  motion ${route}: ${moving.length} long animations under reduce`)
  }
}

/** Every answer 1.5 s late: busy states show, buttons say what they are doing, nothing is sent twice. */
async function checkSlow(page, pageUrl) {
  const label = 'Slow network'
  await page.setViewportSize({ width: 1280, height: 900 })
  await page.goto(pageUrl('one', '/', '&slow=1500'))
  await page.getByRole('heading', { level: 1, name: 'Dashboard' }).waitFor()
  if (!(await page.locator('#mcpmate-root [aria-busy="true"]').count())) {
    findings.push(`[${label}] nothing is marked busy while the Dashboard loads`)
  }
  await page.waitForFunction(() => !document.querySelector('#mcpmate-root [aria-busy="true"]'), null, { timeout: 15000 })
  await page.getByRole('button', { name: 'Pause AI access', exact: true }).click()
  const dialog = page.getByRole('alertdialog', { name: 'Pause AI access?' })
  await dialog.getByRole('button', { name: 'Pause AI access' }).click()
  await dialog.getByRole('button', { name: 'Pausing…' }).waitFor({ timeout: 1000 })
    .catch(() => findings.push(`[${label}] the pause button does not say "Pausing…" while it waits`))
  await expectAnnounced(page, 'AI access paused.', label)

  await page.goto(pageUrl('one', '/settings', '&slow=1500'))
  await page.getByRole('heading', { level: 1, name: 'Settings' }).waitFor()
  await page.waitForFunction(() => !document.querySelector('#mcpmate-root [aria-busy="true"]'), null, { timeout: 15000 })
  await page.getByRole('switch', { name: 'Also log read calls' }).click()
  await page.getByRole('button', { name: 'Save changes' }).first().click()
  await page.getByRole('button', { name: /Saving…/ }).first().waitFor({ timeout: 1000 })
    .catch(() => findings.push(`[${label}] Settings' save button does not say "Saving…"`))
  await expectAnnounced(page, 'Saved.', label)

  await page.goto(pageUrl('one', '/activity/approvals/12', '&slow=1500'))
  await page.getByRole('heading', { level: 1, name: 'Activity' }).waitFor()
  if (!(await page.locator('#mcpmate-root [aria-busy="true"]').count())) {
    findings.push(`[${label}] nothing is marked busy while an approval request loads`)
  }
  await page.getByRole('button', { name: /^Approve and run/ }).click({ timeout: 15000 })
  await page.getByRole('button', { name: /Approving…|Running…/ }).waitFor({ timeout: 1000 })
    .catch(() => findings.push(`[${label}] the approve button does not show progress`))
  await expectAnnounced(page, 'Approved and run.', label)
}

// ── Run ────────────────────────────────────────

const { server, url, pageUrl } = await startServer()
const browser = await launch()
const page = await browser.newPage({ reducedMotion: 'reduce' })
page.on('pageerror', (error) => findings.push(`[page] ${error.message}`))

try {
  console.log(`UI kit: ${url}`)
  console.log(`wp-admin styles: ${WP_STYLES.length ? WP_STYLES.join(', ') : 'none found'}`)

  const PARTS = {
    approvals: checkApprovals,
    queue: checkBlockQueue,
    design: checkDesign,
    screens: checkScreens,
    rtl: checkRtl,
    zoom: checkZoom,
    motion: checkMotion,
    slow: checkSlow,
  }
  if (!['pages', 'tools', 'activity', 'settings', ...Object.keys(PARTS)].includes(ONLY)) {
    await checkUiKit(page, url)
  }
  if (PARTS[ONLY]) {
    await PARTS[ONLY](page, pageUrl)
  } else if (ONLY === 'tools') {
    await checkTools(page, pageUrl)
  } else if (ONLY === 'activity') {
    await checkActivity(page, pageUrl)
  } else if (ONLY === 'settings') {
    await checkSettings(page, pageUrl)
  } else if (ONLY !== 'kit') {
    await checkDashboard(page, pageUrl)
    await checkConnections(page, pageUrl)
    await checkTools(page, pageUrl)
    await checkActivity(page, pageUrl)
    await checkSettings(page, pageUrl)
    for (const part of Object.values(PARTS)) {
      await part(page, pageUrl)
    }
  }
} catch (error) {
  findings.push(`[run] ${error.message.split('\n')[0]}`)
} finally {
  await browser.close()
  await server.close()
}

if (findings.length) {
  console.error(`\n${findings.length} finding(s):\n${findings.join('\n')}`)
  process.exit(1)
}

console.log('\nNo axe violations, focus always visible, no sideways scroll.')

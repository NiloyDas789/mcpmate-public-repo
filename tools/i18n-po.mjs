/**
 * Build per-locale .po/.mo catalogs and the SPA's locale-data files.
 *
 * The POT is the single source of truth for which strings exist. Translations
 * live in `languages/src/<locale>.json` as an array positionally aligned with
 * `languages/src/msgids.json`, so the English text is stored once instead of
 * being repeated in every locale file.
 *
 *   node tools/i18n-po.mjs msgids   # regenerate msgids.json from the POT
 *   node tools/i18n-po.mjs build    # write .po files + languages/frontend/*.php
 *
 * An entry is a string for singular messages, or an array of forms for plurals
 * (as many forms as the locale's nplurals). Empty strings are left untranslated
 * and fall back to English at runtime.
 */
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const ROOT = path.resolve(import.meta.dirname, '..')
const LANG_DIR = path.join(ROOT, 'languages')
const SRC_DIR = path.join(LANG_DIR, 'src')
const POT = path.join(LANG_DIR, 'mcpmate.pot')
const TEXT_DOMAIN = 'mcpmate'

/**
 * Locales shipped with the plugin, with their gettext plural rules.
 *
 * Add an entry here and a matching `languages/src/<locale>.json` file, e.g.:
 *
 *   de_DE: { name: 'German', plurals: 'nplurals=2; plural=(n != 1);' },
 */
export const LOCALES = {}

// ---------------------------------------------------------------------------
// PO parsing / writing
// ---------------------------------------------------------------------------

const unquote = (line) =>
  line
    .slice(line.indexOf('"') + 1, line.lastIndexOf('"'))
    .replace(/\\"/g, '"')
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, '\t')
    .replace(/\\\\/g, '\\')

const quote = (value) =>
  value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t')

/** Parse a POT/PO file into ordered entries. The header entry is dropped. */
function parsePo(file) {
  const entries = []
  let current = null
  let field = null

  const push = () => {
    if (current && current.msgid !== '') entries.push(current)
    current = null
  }

  for (const raw of fs.readFileSync(file, 'utf8').split('\n')) {
    const line = raw.trim()

    if (line === '') {
      push()
      field = null
      continue
    }

    if (line.startsWith('#.')) {
      current ??= { comments: [], references: [], msgid: null }
      current.comments.push(line.slice(2).trim())
    } else if (line.startsWith('#:')) {
      current ??= { comments: [], references: [], msgid: null }
      current.references.push(line.slice(2).trim())
    } else if (line.startsWith('#')) {
      current ??= { comments: [], references: [], msgid: null }
    } else if (line.startsWith('msgctxt "')) {
      current ??= { comments: [], references: [], msgid: null }
      current.msgctxt = unquote(line)
      field = 'msgctxt'
    } else if (line.startsWith('msgid_plural "')) {
      current.msgidPlural = unquote(line)
      field = 'msgidPlural'
    } else if (line.startsWith('msgid "')) {
      current ??= { comments: [], references: [], msgid: null }
      current.msgid = unquote(line)
      field = 'msgid'
    } else if (line.startsWith('msgstr')) {
      field = 'msgstr'
    } else if (line.startsWith('"') && field && current) {
      // Continuation line of the previous field.
      if (field !== 'msgstr') current[field] += unquote(line)
    }
  }

  push()
  return entries
}

function readJson(file, fallback) {
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : fallback
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

/** Write the ordered msgid list that every locale file is aligned against. */
function writeMsgids() {
  const entries = parsePo(POT)
  fs.mkdirSync(SRC_DIR, { recursive: true })

  const payload = entries.map((entry) => {
    const item = { id: entry.msgid }
    if (entry.msgidPlural) item.plural = entry.msgidPlural
    if (entry.msgctxt) item.context = entry.msgctxt
    if (entry.comments.length) item.note = entry.comments.join(' ')
    return item
  })

  fs.writeFileSync(path.join(SRC_DIR, 'msgids.json'), `${JSON.stringify(payload, null, 2)}\n`)
  console.log(`wrote languages/src/msgids.json (${payload.length} entries)`)
}

/** Normalise one locale entry into the list of msgstr forms gettext expects. */
function forms(value, entry, nplurals) {
  if (entry.msgidPlural) {
    const list = Array.isArray(value) ? value : value ? [value] : []
    return Array.from({ length: nplurals }, (_, i) => list[i] ?? '')
  }
  return [Array.isArray(value) ? (value[0] ?? '') : (value ?? '')]
}

function buildLocale(locale, meta, entries, msgids) {
  const translations = readJson(path.join(SRC_DIR, `${locale}.json`), null)

  if (!translations) {
    console.log(`  ${locale}: no source file, skipped`)
    return null
  }

  if (translations.length !== msgids.length) {
    throw new Error(
      `${locale}.json has ${translations.length} entries but msgids.json has ${msgids.length} — they must align positionally`
    )
  }

  const nplurals = Number(/nplurals=(\d+)/.exec(meta.plurals)[1])
  const lines = [
    '# Copyright (C) 2026 MCPMate',
    '# This file is distributed under the GPL-2.0-or-later.',
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: MCPMate\\n"',
    '"Report-Msgid-Bugs-To: https://example.com\\n"',
    `"PO-Revision-Date: ${new Date().toISOString().replace(/\.\d+Z$/, '+00:00')}\\n"`,
    '"Last-Translator: MCPMate\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    `"Language: ${locale}\\n"`,
    `"Language-Team: ${meta.name}\\n"`,
    `"Plural-Forms: ${meta.plurals}\\n"`,
    `"X-Domain: ${TEXT_DOMAIN}\\n"`,
    '',
  ]

  let translated = 0

  entries.forEach((entry, index) => {
    const values = forms(translations[index], entry, nplurals)
    if (values.some(Boolean)) translated += 1

    for (const comment of entry.comments) lines.push(`#. ${comment}`)
    for (const reference of entry.references) lines.push(`#: ${reference}`)
    if (entry.msgctxt) lines.push(`msgctxt "${quote(entry.msgctxt)}"`)

    lines.push(`msgid "${quote(entry.msgid)}"`)

    if (entry.msgidPlural) {
      lines.push(`msgid_plural "${quote(entry.msgidPlural)}"`)
      values.forEach((value, i) => lines.push(`msgstr[${i}] "${quote(value)}"`))
    } else {
      lines.push(`msgstr "${quote(values[0])}"`)
    }

    lines.push('')
  })

  const poFile = path.join(LANG_DIR, `${TEXT_DOMAIN}-${locale}.po`)
  fs.writeFileSync(poFile, lines.join('\n'))

  // Locale data for the SPA: only the strings the frontend actually renders.
  const localeData = {
    '': { domain: TEXT_DOMAIN, lang: locale, plural_forms: meta.plurals },
  }

  entries.forEach((entry, index) => {
    if (!entry.references.some((reference) => reference.startsWith('frontend/'))) return

    const values = forms(translations[index], entry, nplurals)
    if (!values.some(Boolean)) return

    const key = entry.msgctxt ? `${entry.msgctxt}${entry.msgid}` : entry.msgid
    localeData[key] = values
  })

  fs.mkdirSync(path.join(LANG_DIR, 'frontend'), { recursive: true })
  fs.writeFileSync(
    path.join(LANG_DIR, 'frontend', `${locale}.php`),
    `<?php\n\n/**\n * Locale data for the React frontend (${meta.name}).\n *\n * Generated by tools/i18n-po.mjs — do not edit by hand.\n */\n\nif (!defined('ABSPATH')) {\n    exit;\n}\n\nreturn ${phpArray(localeData, 0)};\n`
  )

  const jsCount = Object.keys(localeData).length - 1
  console.log(
    `  ${locale}: ${translated}/${entries.length} translated, ${jsCount} frontend strings`
  )

  return poFile
}

/** Render a JS value as a PHP array literal. */
function phpArray(value, depth) {
  const pad = '    '.repeat(depth + 1)
  const closePad = '    '.repeat(depth)

  if (Array.isArray(value)) {
    return `[\n${value.map((v) => `${pad}${phpArray(v, depth + 1)},`).join('\n')}\n${closePad}]`
  }

  if (value !== null && typeof value === 'object') {
    const rows = Object.entries(value).map(
      ([k, v]) => `${pad}${phpString(k)} => ${phpArray(v, depth + 1)},`
    )
    return `[\n${rows.join('\n')}\n${closePad}]`
  }

  return phpString(String(value))
}

const phpString = (value) => `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`

function build() {
  const entries = parsePo(POT)
  const msgids = readJson(path.join(SRC_DIR, 'msgids.json'), [])

  if (entries.length !== msgids.length) {
    throw new Error(
      `POT has ${entries.length} entries but msgids.json has ${msgids.length} — run "node tools/i18n-po.mjs msgids" first`
    )
  }

  console.log(`building ${Object.keys(LOCALES).length} locales from ${entries.length} strings`)

  for (const [locale, meta] of Object.entries(LOCALES)) {
    buildLocale(locale, meta, entries, msgids)
  }
}

/**
 * Re-align every locale file to a regenerated POT.
 *
 * Locale files are positionally indexed, so adding or removing a source
 * string would silently shift every translation after it. This rebuilds
 * msgids.json from the current POT and rewrites each locale in the new
 * order, carrying translations across by msgid and leaving new strings
 * empty. Run it after any `i18n:pot`.
 */
function remap() {
  fs.mkdirSync(SRC_DIR, { recursive: true })

  const entries = parsePo(POT)
  const oldMsgids = readJson(path.join(SRC_DIR, 'msgids.json'), [])

  const key = (id, context) => `${context ?? ''}\u0000${id}`

  for (const file of fs.readdirSync(SRC_DIR)) {
    if (!file.endsWith('.json') || file === 'msgids.json') continue

    const locale = file.replace(/\.json$/, '')
    const previous = readJson(path.join(SRC_DIR, file), [])

    if (previous.length !== oldMsgids.length) {
      throw new Error(
        `${file} has ${previous.length} entries but the previous msgids.json had ${oldMsgids.length}; fix the mismatch before remapping`
      )
    }

    const byMsgid = new Map(
      oldMsgids.map((entry, index) => [key(entry.id, entry.context), previous[index]])
    )

    let carried = 0
    let added = 0

    const next = entries.map((entry) => {
      const value = byMsgid.get(key(entry.msgid, entry.msgctxt))
      const hasValue = Array.isArray(value) ? value.some(Boolean) : Boolean(value)

      if (hasValue) carried += 1
      else added += 1

      return value ?? ''
    })

    fs.writeFileSync(path.join(SRC_DIR, file), `${JSON.stringify(next, null, 2)}\n`)
    console.log(`  ${locale}: ${carried} carried over, ${added} awaiting translation`)
  }

  writeMsgids()
}

const command = process.argv[2]

if (command === 'msgids') writeMsgids()
else if (command === 'remap') remap()
else if (command === 'build') build()
else {
  console.error('usage: node tools/i18n-po.mjs <msgids|remap|build>')
  process.exit(1)
}

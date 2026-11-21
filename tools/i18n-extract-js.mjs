/**
 * Extract translatable strings from the TypeScript/TSX frontend into a POT file.
 *
 * `wp i18n make-pot` cannot parse TypeScript — it silently skips every .ts/.tsx
 * file — so the JavaScript half of the catalog is extracted here with the
 * TypeScript compiler API and appended to the POT that WP-CLI produced for PHP.
 *
 *   node tools/i18n-extract-js.mjs <src-dir> <pot-file>
 *
 * Recognises the standard WordPress calls (__, _x, _n, _nx) that pass the
 * plugin's text domain as their final argument. A `translators:` comment
 * attached before a call is carried into the POT.
 */
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import ts from 'typescript'

const TEXT_DOMAIN = 'mcpmate'

/** Argument positions per translation function. */
const SIGNATURES = {
  __: { domain: 1, single: 0 },
  _x: { domain: 2, single: 0, context: 1 },
  _n: { domain: 3, single: 0, plural: 1 },
  _nx: { domain: 4, single: 0, plural: 1, context: 3 },
}

/** @returns {string|null} the literal value, or null when not statically known. */
function literal(node) {
  if (!node) return null

  if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
    return node.text
  }

  // Adjacent string concatenation ("a" + "b") is still statically knowable.
  if (ts.isBinaryExpression(node) && node.operatorToken.kind === ts.SyntaxKind.PlusToken) {
    const left = literal(node.left)
    const right = literal(node.right)
    return left !== null && right !== null ? left + right : null
  }

  return null
}

/** Pull a `translators:` note out of the comments attached before a node. */
function translatorComment(sourceFile, node) {
  const text = sourceFile.getFullText()
  const ranges = ts.getLeadingCommentRanges(text, node.getFullStart()) ?? []

  for (const range of [...ranges].reverse()) {
    const comment = text.slice(range.pos, range.end)
    const match = /translators:\s*([\s\S]*?)(?:\*\/|$)/i.exec(comment)

    if (match) {
      return match[1]
        .split('\n')
        .map((line) => line.replace(/^\s*\*?\s?/, '').trim())
        .filter(Boolean)
        .join(' ')
    }
  }

  return null
}

function collect(file, entries) {
  const source = ts.createSourceFile(
    file,
    fs.readFileSync(file, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS
  )

  const visit = (node) => {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression)) {
      const signature = SIGNATURES[node.expression.text]

      if (signature && literal(node.arguments[signature.domain]) === TEXT_DOMAIN) {
        const msgid = literal(node.arguments[signature.single])
        const { line } = source.getLineAndCharacterOfPosition(node.getStart())
        const where = `${path.relative('.', file).split(path.sep).join('/')}:${line + 1}`

        if (msgid === null) {
          console.warn(`  warn: non-literal ${node.expression.text}() at ${where} - skipped`)
        } else {
          entries.push({
            msgid,
            msgidPlural:
              signature.plural === undefined ? null : literal(node.arguments[signature.plural]),
            msgctxt:
              signature.context === undefined ? null : literal(node.arguments[signature.context]),
            comment: translatorComment(source, node),
            reference: where,
          })
        }
      }
    }

    ts.forEachChild(node, visit)
  }

  visit(source)
}

function walk(dir, files = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)

    if (entry.isDirectory()) walk(full, files)
    else if (/\.tsx?$/.test(entry.name) && !entry.name.endsWith('.d.ts')) files.push(full)
  }

  return files
}

const escape = (value) =>
  value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t')

function main() {
  const [srcDir, potFile] = process.argv.slice(2)

  if (!srcDir || !potFile) {
    console.error('usage: node tools/i18n-extract-js.mjs <src-dir> <pot-file>')
    process.exit(1)
  }

  const entries = []
  for (const file of walk(srcDir)) collect(file, entries)

  // One POT entry per (context, msgid); references and notes are combined.
  const merged = new Map()

  for (const entry of entries) {
    const key = `${entry.msgctxt ?? ''}${entry.msgid}`
    const existing = merged.get(key)

    if (existing) {
      existing.references.add(entry.reference)
      existing.msgidPlural ??= entry.msgidPlural
      existing.comment ??= entry.comment
    } else {
      merged.set(key, { ...entry, references: new Set([entry.reference]) })
    }
  }

  const blocks = [...merged.values()]
    .sort((a, b) => a.msgid.localeCompare(b.msgid))
    .map((entry) => {
      const lines = []

      if (entry.comment) lines.push(`#. translators: ${entry.comment}`)
      for (const reference of [...entry.references].sort()) lines.push(`#: ${reference}`)
      if (entry.msgctxt) lines.push(`msgctxt "${escape(entry.msgctxt)}"`)

      lines.push(`msgid "${escape(entry.msgid)}"`)

      if (entry.msgidPlural) {
        lines.push(`msgid_plural "${escape(entry.msgidPlural)}"`)
        lines.push('msgstr[0] ""')
        lines.push('msgstr[1] ""')
      } else {
        lines.push('msgstr ""')
      }

      return lines.join('\n')
    })

  const existing = fs.existsSync(potFile) ? fs.readFileSync(potFile, 'utf8').trimEnd() : ''
  fs.writeFileSync(potFile, `${existing}\n\n${blocks.join('\n\n')}\n`)

  console.log(`  extracted ${merged.size} JS strings from ${entries.length} call sites`)
}

main()

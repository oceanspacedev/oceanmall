#!/usr/bin/env node
/**
 * Colour gate: every colour in the app must come from the design tokens.
 *
 * Fails (exit 1) when app/, components/ or lib/ contain
 *   - a hex colour literal (#rgb, #rgba, #rrggbb, #rrggbbaa), or
 *   - a raw Tailwind palette class (bg-zinc-200, text-slate-500, ...),
 * anywhere except lib/theme.ts, which is where COLORS and THEME live.
 *
 * Use instead:
 *   className   om-* and semantic tokens (bg-primary, text-muted-foreground, bg-om-sale ...)
 *   inline      COLORS / IMAGE_PLACEHOLDER from '@/lib/theme'
 *
 * Usage:   node scripts/check-colors.js
 *          node scripts/check-colors.js --strict   (also flags the rest of the Tailwind palette)
 *
 * This is the integration gate. It fails while packages are still migrating, so
 * run it once everything is merged. A line can opt out with `check-colors:ignore`
 * in a comment on that line (use sparingly, with a reason).
 */
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const SCAN_DIRS = ['app', 'components', 'lib'];
const EXTENSIONS = new Set(['.ts', '.tsx', '.js', '.jsx']);
const ALLOWED_FILES = new Set([path.join('lib', 'theme.ts')]);
const IGNORE_MARKER = 'check-colors:ignore';

const strict = process.argv.includes('--strict');

const PALETTES = ['zinc', 'slate', 'sky', 'gray', 'rose', 'amber'];
const STRICT_PALETTES = [
  'neutral',
  'stone',
  'red',
  'orange',
  'yellow',
  'lime',
  'green',
  'emerald',
  'teal',
  'cyan',
  'blue',
  'indigo',
  'violet',
  'purple',
  'fuchsia',
  'pink',
];

// '#' followed by 3-8 hex digits, not part of a longer word (so `#fff` matches but
// `#features` and `#12345678901` do not) and not preceded by a word character.
const HEX = /(?<![\w&])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![\w-])/g;

const palette = (strict ? [...PALETTES, ...STRICT_PALETTES] : PALETTES).join('|');
// `-zinc-` followed by a digit, e.g. bg-zinc-200, dark:text-slate-500/80, border-t-gray-100.
const PALETTE_CLASS = new RegExp(`(?<![\\w])[\\w:/\\[\\]-]*?-(?:${palette})-\\d[\\w/.%-]*`, 'g');

function* walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
      continue;
    }
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      yield* walk(full);
    } else if (EXTENSIONS.has(path.extname(entry.name))) {
      yield full;
    }
  }
}

function isCommentOnly(line) {
  const trimmed = line.trim();
  return trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*');
}

const findings = [];

for (const dirName of SCAN_DIRS) {
  const dir = path.join(ROOT, dirName);
  if (!fs.existsSync(dir)) {
    continue;
  }

  for (const file of walk(dir)) {
    const relative = path.relative(ROOT, file);
    if (ALLOWED_FILES.has(relative)) {
      continue;
    }

    fs.readFileSync(file, 'utf8')
      .split('\n')
      .forEach((line, index) => {
        if (line.includes(IGNORE_MARKER) || isCommentOnly(line)) {
          return;
        }
        for (const match of line.matchAll(HEX)) {
          findings.push({
            file: relative,
            line: index + 1,
            column: match.index + 1,
            text: match[0],
            hint: 'hex colour: use COLORS from @/lib/theme or an om-* / semantic class',
          });
        }
        for (const match of line.matchAll(PALETTE_CLASS)) {
          findings.push({
            file: relative,
            line: index + 1,
            column: match.index + 1,
            text: match[0],
            hint: 'raw Tailwind palette class: use an om-* or semantic token',
          });
        }
      });
  }
}

if (findings.length === 0) {
  console.log(`check-colors: ok (${SCAN_DIRS.join(', ')} use design tokens only)`);
  process.exit(0);
}

findings.sort((a, b) => a.file.localeCompare(b.file) || a.line - b.line || a.column - b.column);
for (const finding of findings) {
  console.error(
    `${finding.file}:${finding.line}:${finding.column}  ${finding.text}  (${finding.hint})`
  );
}
const files = new Set(findings.map((finding) => finding.file)).size;
console.error(`\ncheck-colors: ${findings.length} violation(s) in ${files} file(s)`);
process.exit(1);

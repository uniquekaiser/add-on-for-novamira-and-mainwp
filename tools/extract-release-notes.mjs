#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const version = process.argv[2];
const target = process.argv[3];

if (!/^\d+\.\d+\.\d+$/.test(version || '')) {
  throw new Error('Usage: node tools/extract-release-notes.mjs X.Y.Z output.md');
}
if (!target) {
  throw new Error('A release-notes output path is required.');
}

const changelog = fs.readFileSync(path.join(root, 'CHANGELOG.md'), 'utf8');
const lines = changelog.split(/\r?\n/);
const escaped = version.replaceAll('.', '\\.');
const start = lines.findIndex((line) => new RegExp(`^## ${escaped}(?:\\s+-.*)?$`).test(line));
const end = start < 0 ? -1 : lines.findIndex((line, index) => index > start && line.startsWith('## '));
const body = start < 0 ? '' : lines.slice(start + 1, end < 0 ? lines.length : end).join('\n').trim();
if (!body) {
  throw new Error(`CHANGELOG.md has no release notes for ${version}.`);
}

const notes = `# ${version}\n\n${body}\n`;
const output = path.resolve(root, target);
fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, notes, 'utf8');
console.log(output);

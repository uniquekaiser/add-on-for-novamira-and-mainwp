#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dist = path.join(root, 'dist');

const packages = [
  {
    slug: 'mainwp-novamira-addon',
    source: root,
    main: 'mainwp-novamira-addon.php',
    include: [
      'mainwp-novamira-addon.php',
      'includes',
      'assets',
      'CHANGELOG.md',
      'LICENSE',
      'README.md',
      'readme.txt',
      'vendor/yahnis-elsts/plugin-update-checker',
    ],
  },
];

const pluginVersion = (source, main) => {
  const body = fs.readFileSync(path.join(source, main), 'utf8');
  const match = body.match(/^\s*\*\s*Version:\s*([^\r\n]+)/mi);
  if (!match) throw new Error(`Version header not found in ${path.join(source, main)}`);
  return match[1].trim();
};

const archive = (workingDirectory, slug, target) => {
  if (process.platform === 'win32') {
    execFileSync('tar.exe', ['-a', '-c', '-f', target, slug], {
      cwd: workingDirectory,
      stdio: 'inherit',
    });
    return;
  }
  execFileSync('zip', ['-q', '-r', target, slug], {
    cwd: workingDirectory,
    stdio: 'inherit',
  });
};

const inspect = (target, slug, main) => {
  const tar = process.platform === 'win32' ? 'tar.exe' : 'tar';
  const entries = execFileSync(tar, ['-tf', target], { encoding: 'utf8' })
    .split(/\r?\n/)
    .filter(Boolean);
  if (!entries.includes(`${slug}/${main}`)) {
    throw new Error(`${target} does not contain ${slug}/${main}`);
  }
  const requiredRuntime = [
    `${slug}/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php`,
    `${slug}/vendor/yahnis-elsts/plugin-update-checker/license.txt`,
    `${slug}/assets/icon.svg`,
    `${slug}/LICENSE`,
  ];
  for (const required of requiredRuntime) {
    if (!entries.includes(required)) {
      throw new Error(`${target} is missing required release file: ${required}`);
    }
  }
  for (const entry of entries) {
    if (!entry.startsWith(`${slug}/`) || entry.includes('\\') || entry.includes('../')) {
      throw new Error(`Unsafe or unexpected archive entry: ${entry}`);
    }
    const relative = entry.slice(slug.length + 1);
    if (/^(tests?|tools?|dist|\.git|\.github|composer\.(json|lock))(\/|$)/i.test(relative)) {
      throw new Error(`Development-only archive entry: ${entry}`);
    }
    if (/^vendor\/yahnis-elsts\/plugin-update-checker\/(composer\.json|README\.md)$/i.test(relative)) {
      throw new Error(`Updater source-only archive entry: ${entry}`);
    }
  }
  return entries.length;
};

fs.mkdirSync(dist, { recursive: true });
for (const entry of fs.readdirSync(dist)) {
  if (/^(mainwp-novamira-addon|novamira)-.*\.zip$/i.test(entry)) {
    fs.rmSync(path.join(dist, entry), { force: true });
  }
}
const output = [];

for (const item of packages) {
  const version = pluginVersion(item.source, item.main);
  const staging = fs.mkdtempSync(path.join(os.tmpdir(), `${item.slug}-build-`));
  const packageRoot = path.join(staging, item.slug);
  const target = path.join(dist, `${item.slug}-${version}.zip`);
  try {
    fs.mkdirSync(packageRoot, { recursive: true });
    for (const relative of item.include) {
      const source = path.join(item.source, relative);
      if (!fs.existsSync(source)) throw new Error(`Required distribution item missing: ${source}`);
      fs.cpSync(source, path.join(packageRoot, relative), { recursive: true });
    }
    const updaterRoot = path.join(packageRoot, 'vendor/yahnis-elsts/plugin-update-checker');
    for (const sourceOnly of ['composer.json', 'README.md']) {
      fs.rmSync(path.join(updaterRoot, sourceOnly), { force: true });
    }
    fs.rmSync(target, { force: true });
    archive(staging, item.slug, target);
    const bytes = fs.readFileSync(target);
    output.push({
      file: target,
      version,
      bytes: bytes.length,
      entries: inspect(target, item.slug, item.main),
      sha256: crypto.createHash('sha256').update(bytes).digest('hex').toUpperCase(),
    });
  } finally {
    fs.rmSync(staging, { recursive: true, force: true });
  }
}

console.log(JSON.stringify(output, null, 2));

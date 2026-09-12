#!/usr/bin/env node

// Deliberately supports this workflow's explicit YAML shape, not arbitrary YAML.
// Reject unfamiliar privileged-job syntax so policy changes receive review.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const requirePolicy = (condition, message) => {
  if (!condition) throw new Error(message);
};
const stripComment = (line) => line.replace(/\s+#.*$/, '').trimEnd();

function section(lines, key, indent) {
  const prefix = ' '.repeat(indent);
  const starts = lines.map((line, index) => line === `${prefix}${key}:` ? index : -1).filter((index) => index >= 0);
  requirePolicy(starts.length === 1, `Expected one explicit ${key} mapping at indentation ${indent}.`);
  const start = starts[0] + 1;
  let end = start;
  while (end < lines.length && (!lines[end].trim() || lines[end].search(/\S/) > indent)) ++end;
  return lines.slice(start, end);
}

function permissions(lines, indent) {
  const map = new Map();
  for (const line of section(lines, 'permissions', indent).filter((value) => value.trim())) {
    const match = line.match(new RegExp(`^ {${indent + 2}}([a-z-]+): (read|write|none)$`));
    requirePolicy(match && !map.has(match[1]), 'Permissions must use unique, explicit read/write/none entries.');
    map.set(match[1], match[2]);
  }
  return map;
}

function steps(lines) {
  const body = section(lines, 'steps', 4);
  const output = [];
  for (const line of body) {
    if (!line.trim()) continue;
    if (/^ {6}- name: .+$/.test(line)) output.push([]);
    else {
      requirePolicy(output.length > 0 && /^ {8}\S|^ {9,}\S/.test(line), 'Steps must use named, explicit mappings.');
      output[output.length - 1].push(line);
    }
  }
  return output;
}

function script(step) {
  const header = step.findIndex((line) => /^ {8}run:/.test(line));
  requirePolicy(header >= 0, 'Missing run script.');
  const first = step[header].slice(13);
  const raw = first === '|' ? step.slice(header + 1).map((line) => {
    requirePolicy(/^ {10}/.test(line), 'Run block must be the last step field and use explicit indentation.');
    return line.slice(10);
  }).join('\n') : first;
  return raw.replace(/\\\n\s*/g, ' ').split('\n').filter((line) => line.trim() && !line.trimStart().startsWith('#')).map((line) => line.trim().replace(/\s+/g, ' '));
}

function artifactDownload(step) {
  const content = step.join('\n');
  requirePolicy(/^ {8}uses: actions\/download-artifact@[a-f0-9]{40}$/m.test(content), 'Privileged jobs may only download their tested artifact.');
  const remaining = step.filter((line) => !/^ {8}uses:/.test(line));
  requirePolicy(remaining.join('\n') === '        with:\n          name: distribution\n          path: dist', 'Artifact downloads must use this run\'s distribution with no repository/run overrides.');
}

export function validateReleaseWorkflow(source) {
  requirePolicy(!source.includes('\t'), 'Workflow indentation must use spaces.');
  const lines = source.replace(/\r\n/g, '\n').split('\n').map(stripComment);
  requirePolicy(!lines.some((line) => /^\s*(?:<<:|[&*][\w-]+)/.test(line)), 'YAML aliases and merged mappings need explicit policy review.');
  for (const line of lines) {
    if (/^\s+(?:- )?uses:/.test(line)) {
      requirePolicy(/^\s+(?:- )?uses: [\w.-]+\/[\w./-]+@[a-f0-9]{40}$/.test(line), 'Every action must be pinned to a full commit SHA.');
    }
    if (/^\S/.test(line)) requirePolicy(/^(?:name: .+|on:|permissions:|jobs:)$/.test(line), 'Unexpected workflow-level field; review its effect on privileged jobs.');
  }
  requirePolicy(JSON.stringify([...permissions(lines, 0)]) === JSON.stringify([['contents', 'read']]), 'Default permissions must be contents: read only.');
  requirePolicy(section(lines, 'on', 0).filter((line) => line.trim()).join('\n') === "  push:\n    tags:\n      - 'v*.*.*'", 'Release credentials must only be used by version-tag pushes.');
  const jobs = section(lines, 'jobs', 0);
  const names = jobs.filter((line) => /^ {2}\S/.test(line)).map((line) => line.trim());
  requirePolicy(names.join(',') === 'build:,attest:,release:', 'The release pipeline must have explicit build, attest, release jobs in order.');
  const build = section(jobs, 'build', 2);
  const attest = section(jobs, 'attest', 2);
  const release = section(jobs, 'release', 2);
  requirePolicy(!build.some((line) => /^ {4}permissions:/.test(line)), 'Build must inherit read-only permissions.');
  requirePolicy(JSON.stringify([...permissions(attest, 4)].sort()) === JSON.stringify([['attestations', 'write'], ['contents', 'read'], ['id-token', 'write']]), 'Only attestation may mint OIDC credentials; its contents permission must remain read-only.');
  requirePolicy(JSON.stringify([...permissions(release, 4)].sort()) === JSON.stringify([['attestations', 'read'], ['contents', 'write']]), 'Only release may write contents, without OIDC or attestation-write permission.');
  for (const [name, job, dependency] of [['attest', attest, 'build'], ['release', release, 'attest']]) {
    requirePolicy(job.includes(`    needs: ${dependency}`), `${name} must depend on successful ${dependency}.`);
    for (const line of job.filter((value) => /^ {4}\S/.test(value))) {
      requirePolicy(/^(?:    needs: (?:build|attest)|    runs-on: ubuntu-latest|    permissions:|    steps:)$/.test(line), `Unexpected ${name} job configuration; privileged jobs cannot execute repository defaults, containers, or reusable workflows.`);
    }
  }
  const attestSteps = steps(attest);
  requirePolicy(attestSteps.length === 3, 'Attestation must only download, verify its checksum, and attest.');
  artifactDownload(attestSteps[0]);
  requirePolicy(attestSteps[1].join('\n') === '        working-directory: dist\n        run: sha256sum --check SHA256SUMS', 'Attestation must verify the transferred checksum without executing source.');
  requirePolicy(/^        uses: actions\/attest-build-provenance@[a-f0-9]{40}\n        with:\n          subject-path: dist\/mainwp-novamira-addon-\*\.zip$/.test(attestSteps[2].join('\n')), 'Attestation must sign the tested package path with the pinned provenance action.');
  const releaseSteps = steps(release);
  requirePolicy(releaseSteps.length === 2, 'Release must only download and verify/publish the tested artifact.');
  artifactDownload(releaseSteps[0]);
  const preamble = releaseSteps[1].slice(0, releaseSteps[1].findIndex((line) => /^ {8}run:/.test(line))).join('\n');
  requirePolicy(preamble === '        env:\n          GH_TOKEN: ${{ github.token }}\n          GH_REPO: ${{ github.repository }}\n        shell: bash', 'Release must use only its scoped GitHub token and a plain Bash shell.');
  const commands = script(releaseSteps[1]);
  const expected = [
    'set -euo pipefail',
    'VERSION="${GITHUB_REF_NAME#v}"',
    '[[ "$VERSION" =~ ^[0-9]+\\.[0-9]+\\.[0-9]+$ ]]',
    'cd dist',
    'sha256sum --check SHA256SUMS',
    'gh attestation verify "mainwp-novamira-addon-${VERSION}.zip" --repo "$GITHUB_REPOSITORY" --signer-workflow "$GITHUB_REPOSITORY/.github/workflows/release.yml" --source-digest "$GITHUB_SHA" --source-ref "$GITHUB_REF" --deny-self-hosted-runners',
    'gh release create "$GITHUB_REF_NAME" "mainwp-novamira-addon-${VERSION}.zip" SHA256SUMS --verify-tag --title "Add-on for Novamira and MainWP ${VERSION}" --notes-file "release-notes-${VERSION}.md"',
  ];
  requirePolicy(JSON.stringify(commands) === JSON.stringify(expected), 'Release commands must validate checksum and bound provenance before publishing the exact asset; source execution and extra shell commands are forbidden.');
  return true;
}

function selfTest(source) {
  assert.equal(validateReleaseWorkflow(source), true);
  const mutations = [
    ['floating action tag', (value) => value.replace(/actions\/checkout@[a-f0-9]{40}/, 'actions/checkout@v4')],
    ['build write token', (value) => value.replace('  build:\n', '  build:\n    permissions:\n      contents: write\n')],
    ['release OIDC access', (value) => value.replace('      attestations: read', '      attestations: read\n      id-token: write')],
    ['cross-run artifact', (value) => value.replace('          name: distribution\n          path: dist', '          name: distribution\n          path: dist\n          run-id: 42')],
    ['missing source digest binding', (value) => value.replace('--source-digest "$GITHUB_SHA" ', '')],
    ['missing source ref binding', (value) => value.replace('--source-ref "$GITHUB_REF"', '')],
    ['missing signer workflow binding', (value) => value.replace('--signer-workflow "$GITHUB_REPOSITORY/.github/workflows/release.yml"', '')],
    ['repository script in release', (value) => value.replace('          gh release create', '          node ../tools/build-dist.mjs\n          gh release create')],
    ['custom privileged shell', (value) => value.replace('  release:\n', '  release:\n    defaults:\n      run:\n        shell: node {0}\n')],
    ['non-tag trigger', (value) => value.replace('  push:\n    tags:', '  pull_request_target:\n    branches:')],
    ['publish before verification', (value) => value.replace('          gh attestation verify', '          gh release create "$GITHUB_REF_NAME"\n          gh attestation verify')],
  ];
  for (const [name, mutate] of mutations) {
    const changed = mutate(source);
    assert.notEqual(changed, source, `${name} mutation must change the fixture`);
    assert.throws(() => validateReleaseWorkflow(changed), undefined, `${name} must be rejected`);
  }
  console.log(`Release workflow policy passed; ${mutations.length} unsafe mutations rejected.`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const filename = process.argv.find((argument, index) => index > 1 && argument !== '--self-test') ?? path.join(root, '.github/workflows/release.yml');
    const source = fs.readFileSync(filename, 'utf8');
    if (process.argv.includes('--self-test')) selfTest(source);
    else {
      validateReleaseWorkflow(source);
      console.log('Release workflow policy passed.');
    }
  } catch (error) {
    console.error(`Release workflow policy failed: ${error.message}`);
    process.exitCode = 1;
  }
}

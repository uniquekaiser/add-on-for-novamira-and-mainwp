# Changelog

All notable changes to Novamira for MainWP are documented here.

## 0.3.0 - 2026-08-05

- [NEW] Replaced the child companion architecture with signed, non-persistent one-shot operations over the existing MainWP Child connection.
- [FIX] Restored the fleet inventory on MainWP 6.1 by no longer passing an empty legacy site-search parameter.
- [IMPROVE] Rebuilt the extension navigation, fleet summaries, filters, contextual actions, and Packages view using MainWP's own extension UI patterns.
- [SECURITY] Added Dashboard-owned five-minute AI access windows with exact prior-value restoration, concurrent-call tracking, WP-Cron cleanup, and no custom child code installation.
- [COMPAT] Kept Novamira Free as the only required child plugin and isolated all optional Pro installation and licensing failures.
- [DEV] Replaced companion contract tests with one-shot transport, runtime restoration, routing, credential, and Pro-isolation coverage.

## 0.2.4 - 2026-08-04

- [IMPROVE] Ensured future updates show the target release notes, WordPress and PHP requirements, and plugin icon before installation.
- [DEV] Completed cross-version update verification against the published GitHub release asset.

## 0.2.3 - 2026-08-04

- [FIX] Preserved the target release's notes when checking future GitHub updates.
- [SECURITY] Restricted update-detail changelogs to categorized headings, lists, list items, and strong labels.

## 0.2.2 - 2026-08-04

- [FIX] Added the WordPress requirement to fresh and cached update rows.
- [IMPROVE] Normalized update details to categorized, update-safe changelog HTML generated from the canonical changelog.

## 0.2.1 - 2026-08-04

- [NEW] Published the first public GitHub release of the Dashboard fleet control plane, child companion, and routed MCP gateway.
- [SECURITY] Added exact-asset dashboard updates that reject source archives, prereleases, and unrelated ZIP files.
- [FIX] Made distribution inspection portable across Windows and Linux release runners.

## 0.2.0 - 2026-08-04

- [NEW] Added a Dashboard fleet control plane for status, policies, package deployment, encrypted credentials, auditing, and provider connection profiles.
- [NEW] Added an independently deployable MainWP Child companion for authenticated status, credential, policy, lease, and optional Pro operations.
- [NEW] Added a routed MCP gateway for tools, resources, prompts, bounded read-only fan-out, and confirmed single-site writes.
- [SECURITY] Added request-scoped five-minute AI leases that leave manually disabled child settings unchanged before and after gateway calls.
- [SECURITY] Added fail-closed site resolution, ability classification, credential rotation, redacted audit records, and production-site policy defaults.
- [COMPAT] Kept Novamira Free unmodified and fully operational without Novamira Pro.
- [DEV] Added exact-asset public GitHub Release updates with packaged updater runtime, metadata, icon, and source-archive fallback prevention.

## 0.1.0

- [DEV] Established the initial add-on architecture and fleet-management contracts.

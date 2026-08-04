# Changelog

All notable changes to Novamira for MainWP are documented here.

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

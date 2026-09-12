# Security remediation released in v0.7.2

Source baseline: `3fa02db5a92030fd4a9fb7fc0dd7254b82fd9d2d` (v0.7.1). These controls ship in v0.7.2; deployment owners must still configure independent Free-package trust pins and any optional export, origin, or audit-forwarding integrations described below.

## Configuration required before deployment

### Free package authentication

Fleet and onboarding now fail closed without a trusted release pin. In administrator-owned configuration (for example a reviewed MU plugin), the `novamira_mainwp_trusted_free_releases` filter must return a map of approved version to `sha256` and `download_url`. The highest approved version is selected. Metadata must match that exact version and URL; redirects are rejected and downloaded bytes must match the independently trusted SHA-256. MainWP installs a staged copy of those bytes, including on update and repair.

Obtain the digest through an independently authenticated publisher channel or an independently reviewed artifact. Reading a checksum from the same mutable metadata service is not independent authentication. No publisher key, signed manifest, or trusted checksum was invented or supplied with this patch. Do not compute a checksum from an untrusted download and treat that act alone as publisher authentication.

Example shape (replace values with independently verified release data):

```php
add_filter( 'novamira_mainwp_trusted_free_releases', function () {
    return array(
        'VERSION' => array(
            'sha256'       => 'INDEPENDENTLY_VERIFIED_SHA256',
            'download_url' => 'https://APPROVED_ORIGIN/EXACT_PACKAGE.zip',
        ),
    );
} );
```

The placeholder values intentionally fail validation. Packages must be HTTPS and must not contain query strings, fragments, or userinfo. Old unauthenticated cache records cannot authorize deployment.

### Confirmation and administrator access

All exposed abilities require `manage_options`; site operations also require current MainWP site access. Invoke a destructive ability with `dry_run=true` to obtain its preview. After the user approves the exact operation, repeat the unchanged operation arguments with `dry_run=false`, `confirmation_token`, and `user_confirmed=true`. Tokens expire after five minutes, cannot be replayed, and are consumed before execution even when the operation subsequently fails. A failed execution requires a fresh preview.

The store saves a hash of the random token and an HMAC binding, not plaintext arguments. Object key order is normalized; list order and value types remain significant. A Boolean from a client does not by itself authorize execution. An authenticated adapter can automate both steps, so the adapter must still collect human approval through a trusted interface; tokens are not proof of a physical human action.

### Credential export

Exports are disabled by default. `novamira_mainwp_credential_export_enabled` must return Boolean `true`, and `novamira_mainwp_credential_export_step_up( $approved, $user_id, $site_ids )` must verify a recent authentication event for the current browser session before returning Boolean `true`. There is no permissive fallback. Do not implement the step-up hook as an unconditional true value or rely only on a WordPress nonce.

Select 1–100 sites, review the selection, and approve the one-time download. Tokens bind user, sites, format, and browser session. Both steps recheck capability, site access, and recent authentication. Download and review responses use `Cache-Control: no-store`. Rotate credentials after use and verify that every old password was revoked; rotation can report a revocation failure.

Managed Application Passwords retain the connected administrator's REST authority. This patch does not pretend to route-scope WordPress Application Passwords or install a child-side service-account/role policy. Export restrictions reduce exposure; they do not remove that residual credential blast radius.

### Child origins

Default validation matches scheme, hostname, and effective port exactly. Userinfo, fragments, extra or duplicated query parameters, and redirects are rejected. Requests use WordPress safe URL validation.

Only administrator-owned configuration can supply exceptions: `novamira_mainwp_mcp_allowed_origins( $origins, $managed_site_url )` authorizes an alternate exact origin, while `novamira_mainwp_mcp_private_origins( $origins )` authorizes a private address or nonstandard port for safe HTTP transport. Values are exact origins such as `https://child.example:8443`, not wildcard hosts or paths. Neither filter receives authorization from MCP arguments. Private HTTP requires the existing local/development environment exception as well. Request-scoped safe-HTTP filters are always removed afterward.

### Database and audit operations

MySQL/MariaDB connection-owned advisory locks must be available. The lock has an owner and expiring metadata; expiry cannot steal a lock held by a still-running database connection. Runtime state bypasses option caches and is read back after writes. The entire shared lease journal is serialized, preventing both same-site and cross-site lost updates. Background recovery accepts only a restore snapshot from an exhausted durable journal while holding its runtime lock; it uses MainWP's signed transport without depending on a logged-in cron user. It cannot accept a new action or caller-supplied restore settings. Unchanged access windows do not overwrite later administrator settings. An unavailable lock, lost connection, invalid journal, or failed persistence prevents a new access window.

`novamira_mainwp_audit_retention_days` sets retention, clamped to 1–3650 days (default 90). Hourly cleanup deletes at most 10,000 expired records per run using an event-time index. WP-Cron must run for scheduled cleanup. `novamira_mainwp_audit_client` may supply a non-secret configured adapter label. `novamira_mainwp_audit_record( $redacted_record, $local_write_succeeded )` supports forwarding to an external append-only logger. Only redacted fields are forwarded. External delivery must be implemented and verified by the deployment owner; the local table is not tamper-proof against a database administrator.

## Release workflow

All Actions are pinned to full commit SHAs resolved from their official repositories. Build/test has read-only repository permissions. A separate job issues a GitHub artifact attestation; only the final publishing job has `contents: write`. That job checks the checksum and verifies repository, workflow, source digest, source ref, and hosted-runner provenance before publishing the ZIP and checksums.

The tagged release workflow builds, tests, checksums, attests, verifies, and publishes the exact plugin ZIP. Public GitHub updater downloads still rely on GitHub release transport and repository trust; the PHP updater does not independently verify Sigstore attestations. Workflow attestation is not a claim of runtime updater verification. Each release must also verify the real packaged update path from the prior installed version.

## Verification

The unit suite covers authorization, binding/replay/expiry, malicious package and endpoint cases, credential exports, database failures, and recovery behavior. `tests/integration/runtime-concurrency.php` exercises independent PHP processes against real MySQL with barriers for simultaneous same-site and cross-site acquisitions/releases and abrupt process death. It uses synthetic child transport and a disposable database. Release validation must separately record the live packaged update, update-details modal, changelog, icon, settings preservation, and post-update health checks. The original audit remains unchanged; upstream Novamira services, MainWP hosting security, and third-party MCP adapter approval interfaces retain their own security boundaries.

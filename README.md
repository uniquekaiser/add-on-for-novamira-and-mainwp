# Novamira for MainWP

Novamira for MainWP is one independently owned plugin with two roles:

- On the MainWP Dashboard it provides fleet management, encrypted credentials, policies, packages, auditing, provider configurations, and the routed MCP gateway.
- On a MainWP Child site it acts as a narrow companion beside the unmodified Novamira Free plugin and exposes the authenticated MainWP child contract.

Novamira Free remains an upstream dependency and is never patched or repackaged. Novamira Pro is optional and never gates Free fleet management or the MCP gateway.

This is an independent integration project and is not maintained by or affiliated with Novamira or MainWP.

## Requirements

- WordPress 6.9 or newer
- PHP 7.4 or newer on the MainWP Dashboard
- MainWP Dashboard and MainWP Child 6.0 or newer
- MainWP MCP Bridge 0.3.0 or newer on the Dashboard
- An upstream Novamira Free release supported by the add-on (1.11.1 or newer)

## Deployment order

1. Install the add-on and MainWP MCP Bridge on the Dashboard.
2. Build the add-on ZIP, then upload that same audited ZIP on the Packages screen.
3. Use **Repair companion + Free baseline** to deploy the companion and upstream Novamira Free to child sites.
4. Approve production access per site, create managed child credentials, and create the one-time Dashboard MCP profile.

The companion activation routine puts itself before Novamira in WordPress's active-plugin order. This is required because it must validate an incoming lease before stock Novamira reads its enablement settings.

## Security model

The gateway accepts only numeric MainWP site IDs, resolves them from MainWP, and checks the authenticated Dashboard user's access. It never accepts a child destination URL from MCP arguments. Child application passwords are returned once over MainWP's signed channel and immediately encrypted on the Dashboard.

When Novamira is manually off, a routed operation obtains an independent five-minute token over the signed MainWP channel. The child companion stores only its keyed hash. A request carrying the valid token receives request-local `pre_option_novamira_ai_abilities_enabled` and domain values before Novamira loads. No Novamira file or saved Novamira setting changes. The lease is released after the operation, and expiry supplies crash-safe cleanup. Production JIT access is denied until explicitly approved.

Audit records contain only actor, site, operation, outcome, duration, correlation ID, and argument-key names. Credentials, license keys, argument values, and remote results are never written to the audit table.

## MCP gateway

The add-on registers the `novamira-mainwp` ability namespace. MainWP MCP Bridge includes it in dedicated and shared-server exposure modes while preserving the bridge's policies, rate limits, resources, prompts, and confirmation tokens. The add-on routes tools, resources, and prompts to a selected child without flattening every child's catalog into the Dashboard schema.

The Connect screen's provider templates are maintained by this add-on and use the same configuration shapes users expect from Novamira. They do not require or modify Novamira's Connect page.

## Pro behavior

Pro is optional. Administrators may upload a release ZIP that passes root, path, plugin-header, and version checks, then configure an encrypted default license or per-site override. Missing, invalid, expired, or unreachable Pro licensing does not disable or change Novamira Free.

## Distribution build

Install Composer dependencies, then run `node tools/build-dist.mjs` from this plugin directory. It creates one independently installable and inspected add-on ZIP in `dist/`. No Novamira Free package is built or distributed.

## Updates

Official release ZIPs update from the public GitHub Releases page. The bundled updater accepts only an attached asset named `mainwp-novamira-addon-X.Y.Z.zip` from the latest stable Release. It never installs GitHub-generated source archives and never needs a GitHub token.

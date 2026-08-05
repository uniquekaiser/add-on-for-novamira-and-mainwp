# Novamira for MainWP

Novamira for MainWP is an independently owned, Dashboard-only MainWP extension.

It provides fleet management, encrypted credentials, policies, package deployment, auditing, provider configurations, and the routed MCP gateway. Child operations use the authenticated connection already provided by MainWP Child; no Novamira for MainWP plugin is installed on client sites. Novamira Free remains an upstream dependency and is never patched or repackaged. Novamira Pro is optional and never gates Free fleet management or the MCP gateway.

This is an independent integration project and is not maintained by or affiliated with Novamira or MainWP.

## Requirements

- WordPress 6.9 or newer
- PHP 7.4 or newer on the MainWP Dashboard
- MainWP Dashboard and MainWP Child 6.0 or newer
- MainWP MCP Bridge 0.3.0 or newer on the Dashboard
- An upstream Novamira Free release supported by the add-on (1.11.1 or newer)

## Deployment order

1. Install the add-on and MainWP MCP Bridge on the Dashboard.
2. Use Fleet to install or activate upstream Novamira Free on selected MainWP sites. The add-on validates the HTTPS metadata and package before MainWP deploys it.
3. Optionally upload an audited Novamira Pro ZIP on Packages.
4. Approve production access per site, create managed child credentials, and create the one-time Dashboard MCP profile.

## Security model

The gateway accepts only numeric MainWP site IDs, resolves them from MainWP, and checks the authenticated Dashboard user's access. It never accepts a child destination URL from MCP arguments. Child application passwords are returned once over MainWP's signed channel and immediately encrypted on the Dashboard.

When Novamira is manually off, a routed operation opens a five-minute Dashboard-owned access window through MainWP Child's signed, built-in one-shot Code Snippets operation. The operation is evaluated only for that authenticated request and is never saved as a child snippet. The Dashboard records the previous Novamira option values, restores them after the final concurrent gateway call, and schedules crash-safe cleanup. Manually enabled sites remain enabled, and production access is denied until explicitly approved.

Audit records contain only actor, site, operation, outcome, duration, correlation ID, and argument-key names. Credentials, license keys, argument values, and remote results are never written to the audit table.

## MCP gateway

The add-on registers the `novamira-mainwp` ability namespace. MainWP MCP Bridge includes it in dedicated and shared-server exposure modes while preserving the bridge's policies, rate limits, resources, prompts, and confirmation tokens. The add-on routes tools, resources, and prompts to a selected child without flattening every child's catalog into the Dashboard schema.

The Connect screen's provider templates are maintained by this add-on and use the same configuration shapes users expect from Novamira. They do not require or modify Novamira's Connect page.

## Pro behavior

Pro is optional. Administrators may upload a release ZIP that passes root, path, plugin-header, and version checks, then configure an encrypted default license or per-site override. Missing, invalid, expired, or unreachable Pro licensing does not disable or change Novamira Free.

## Distribution build

Install Composer dependencies, then run `node tools/build-dist.mjs` from this plugin directory. It creates one independently installable and inspected Dashboard add-on ZIP in `dist/`. No child companion or Novamira Free package is built or distributed.

## Updates

Official release ZIPs update from the public GitHub Releases page. The bundled updater accepts only an attached asset named `mainwp-novamira-addon-X.Y.Z.zip` from the latest stable Release. It never installs GitHub-generated source archives and never needs a GitHub token.

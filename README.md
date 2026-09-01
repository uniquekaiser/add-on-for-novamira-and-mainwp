# Add-on for Novamira and MainWP

Add-on for Novamira and MainWP is an unofficial, community-maintained, Dashboard-only MainWP extension.

It provides fleet management, encrypted credentials, policies, package deployment, auditing, provider configurations, and the routed MCP gateway. Child operations use the authenticated connection already provided by MainWP Child; no add-on companion plugin is installed on client sites. Novamira Free remains an upstream dependency and is never patched or repackaged. Novamira Pro is optional and never gates Free fleet management or the MCP gateway.

This project is not an official Novamira or MainWP product. It is not affiliated with, endorsed by, or maintained by the Novamira or MainWP development teams.

## Support and feedback

Report bugs, request features, and provide feedback through [GitHub Issues](https://github.com/uniquekaiser/add-on-for-novamira-and-mainwp/issues). Please do not direct support requests for this add-on to the Novamira or MainWP development teams.

## Requirements

- WordPress 6.9 or newer
- PHP 7.4 or newer on the MainWP Dashboard
- MainWP Dashboard and MainWP Child 6.0 or newer
- [MainWP MCP Bridge 0.3.0 or newer](https://github.com/uniquekaiser/mainwp-mcp-bridge) on the Dashboard
- An upstream Novamira Free release supported by the add-on (1.11.1 or newer)

## Deployment order

1. Install the add-on and [MainWP MCP Bridge](https://github.com/uniquekaiser/mainwp-mcp-bridge) on the Dashboard.
2. Use Fleet to install or activate upstream Novamira Free on selected MainWP sites. The add-on validates the HTTPS metadata and package before MainWP deploys it.
3. Optionally upload an audited Novamira Pro ZIP on Packages.
4. Approve production access per site, create managed child credentials, and create the one-time Dashboard MCP profile.

## New-site onboarding

On MainWP Dashboard 6.0 or newer, the Single Site Add Site form includes this add-on in **Add-ons Settings Synchronization**. Administrators can select **Install Novamira Free plugin** and, independently, **Create a managed credential and apply safe gateway defaults**. The installer uses the same HTTPS release-metadata and ZIP validation as Fleet; it never falls back to an unrelated WordPress.org package.

The setup option requires Novamira Free to be active, creates a managed application password only when one does not already exist, enables the gateway policy, keeps persistent AI enablement unchanged, uses just-in-time access, denies production access until explicitly approved, disables read fan-out, refreshes live status, and records redacted audit events. Optional Pro is never installed during onboarding. MainWP does not render its synchronization block for Multiple Sites or CSV imports, so those flows continue to use Fleet bulk provisioning.

## Security model

The gateway accepts only numeric MainWP site IDs, resolves them from MainWP, and checks the authenticated Dashboard user's access. It never accepts a child destination URL from MCP arguments. Child application passwords are returned once over MainWP's signed channel and immediately encrypted on the Dashboard.

When Novamira is manually off, a routed operation opens a five-minute Dashboard-owned access window through MainWP Child's signed, built-in one-shot Code Snippets operation. The operation is evaluated only for that authenticated request and is never saved as a child snippet. The Dashboard records the previous Novamira option values, restores them after the final concurrent gateway call, and schedules crash-safe cleanup. Manually enabled sites remain enabled, and production access is denied until explicitly approved.

Audit records contain only actor, site, operation, outcome, duration, correlation ID, and argument-key names. Credentials, license keys, argument values, and remote results are never written to the audit table.

The signed child status operation reports both WordPress's generated Novamira REST URL and its standard `rest_route` form. The gateway prefers the rewrite-independent form, which remains usable when a site's web-server rules return 404 for pretty REST paths. Reported endpoints are accepted only on the managed site's host, with verified HTTPS outside local development, and only for the Novamira MCP route. Read operations can refresh and retry session initialization once after a stale HTTP 404; writes are never retried automatically.

## MCP gateway

The add-on registers all 13 `novamira-mainwp` fleet abilities through the standard WordPress Abilities API. Every ability includes a stable versioned name, label, description, JSON input/output schemas, REST/MCP visibility metadata, and read-only/destructive/idempotent safety annotations. Any standards-compliant WordPress MCP implementation that exposes the WordPress ability registry can discover and use them without plugin-specific code or source scanning.

The release is tested through WordPress 7.1 and declares the standard WordPress 7.1 public exposure flag in addition to the existing REST and MCP-specific metadata. Novamira Free 1.12.0 and its bundled MCP Adapter 0.6.1 retain the `mcp/novamira` REST route; the add-on discovers the child WordPress base through the signed MainWP Child connection and uses its rewrite-independent transport URL.

[MainWP MCP Bridge](https://github.com/uniquekaiser/mainwp-mcp-bridge) additionally includes the namespace in dedicated and shared-server exposure modes while preserving the bridge's policies, rate limits, resources, prompts, and confirmation tokens. The add-on routes tools, resources, and prompts to a selected child without flattening every child's catalog into the Dashboard schema.

The Connect screen's provider templates are maintained by this add-on and use the same configuration shapes users expect from Novamira. They do not require or modify Novamira's Connect page. It can also download a single direct-child configuration in any supported client format as an emergency backup. That export contains plaintext child application passwords, excludes sites without managed credentials, and should be stored encrypted.

## Pro behavior

Pro is optional. Administrators may build the child package from the Novamira Pro copy installed on the MainWP Dashboard or upload a release ZIP that passes root, path, plugin-header, and version checks, then configure an encrypted default license or per-site override. The combined fleet action installs the package, activates the WordPress plugin, and activates that stored license independently for each selected site. A separate license-only fleet action reactivates the stored license without installing or activating the Pro plugin; it reports an isolated site failure when Pro is unavailable or inactive. Missing, invalid, expired, or unreachable Pro licensing does not disable or change Novamira Free.

## Distribution build

Install Composer dependencies, then run `node tools/build-dist.mjs` from this plugin directory. It creates one independently installable and inspected Dashboard add-on ZIP in `dist/`. No child companion or Novamira Free package is built or distributed.

## Updates

Official release ZIPs update from the public GitHub Releases page. The bundled updater accepts only an attached asset named `mainwp-novamira-addon-X.Y.Z.zip` from the latest stable Release. It never installs GitHub-generated source archives and never needs a GitHub token.

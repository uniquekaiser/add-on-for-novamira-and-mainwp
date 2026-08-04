=== Novamira for MainWP ===
Contributors: synergetic
Tags: mainwp, mcp, ai, fleet-management
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage unmodified Novamira Free sites and route approved child MCP servers through one MainWP MCP connection. Pro remains optional.

== Description ==

The same independently owned plugin runs as the Dashboard control plane and as a narrow MainWP Child companion. The companion owns the signed child contract, application-password lifecycle, policies, and request-scoped five-minute leases. Novamira Free is not modified, bundled, or repackaged.

On the Dashboard, the add-on provides fleet status, package deployment, encrypted credentials, redacted auditing, provider configurations, and the novamira-mainwp routed ability namespace. MainWP MCP Bridge keeps control of its existing exposure, policy, rate-limit, resource, prompt, and confirmation architecture.

Novamira Free provides the MCP server and abilities. Pro controls appear only when an administrator chooses to upload, install, and license Pro.

This is an independent integration project and is not maintained by or affiliated with Novamira or MainWP.

Official packages update from the public GitHub Releases page. Only the exact versioned plugin ZIP attached to the latest stable Release is accepted; GitHub source archives are never used.

== Installation ==

1. Install MainWP Dashboard, MainWP MCP Bridge, and Novamira for MainWP on the Dashboard.
2. Build or obtain the audited Novamira for MainWP ZIP and upload it on the Packages screen.
3. Use Repair companion + Free baseline to deploy this companion and upstream Novamira Free to selected child sites.
4. Approve each production site's policy and create its managed credential.
5. Create a one-time Dashboard application password on Connect and add the displayed MainWP profile to the AI client.

== Changelog ==

= 0.2.0 =
* [NEW] Added the Dashboard fleet control plane, independently deployable child companion, and routed MCP gateway.
* [SECURITY] Added request-scoped five-minute AI leases, encrypted managed credentials, fail-closed routing, confirmed writes, and redacted auditing.
* [COMPAT] Kept Novamira Free unmodified and fully operational without Novamira Pro.
* [DEV] Added exact-asset public GitHub Release updates with packaged updater metadata and icon.

= 0.1.0 =
* [DEV] Established the initial add-on architecture and fleet-management contracts.

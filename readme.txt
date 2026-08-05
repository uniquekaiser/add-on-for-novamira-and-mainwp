=== Novamira for MainWP ===
Contributors: synergetic
Tags: mainwp, mcp, ai, fleet-management
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage unmodified Novamira Free sites and route approved child MCP servers through one MainWP MCP connection. Pro remains optional.

== Description ==

This independently owned plugin runs only on the MainWP Dashboard. It uses each site's existing authenticated MainWP Child connection for one-shot status, settings, and application-password operations; no extra companion plugin is installed on client sites. Novamira Free is not modified, bundled, or repackaged.

On the Dashboard, the add-on provides fleet status, package deployment, encrypted credentials, redacted auditing, provider configurations, and the novamira-mainwp routed ability namespace. MainWP MCP Bridge (https://github.com/uniquekaiser/mainwp-mcp-bridge) keeps control of its existing exposure, policy, rate-limit, resource, prompt, and confirmation architecture. Fleet status includes an explicit live refresh timestamp, so settings changed directly in child wp-admin are read through the existing signed MainWP connection instead of being inferred from add-on policy.

Novamira Free provides the MCP server and abilities. Pro controls remain optional: child installations can use a validated package built from the Pro copy installed on the MainWP Dashboard or an administrator-uploaded audited ZIP.

This is an independent integration project and is not maintained by or affiliated with Novamira or MainWP.

Official packages update from the public GitHub Releases page. Only the exact versioned plugin ZIP attached to the latest stable Release is accepted; GitHub source archives are never used.

== Installation ==

1. Install MainWP Dashboard, MainWP MCP Bridge (https://github.com/uniquekaiser/mainwp-mcp-bridge), and Novamira for MainWP on the Dashboard.
2. Use Fleet to install or activate validated upstream Novamira Free on selected child sites.
3. Optionally use the Novamira Pro copy installed on the Dashboard or upload an audited Pro ZIP on Packages.
4. Approve each production site's policy and create its managed credential.
5. Create a one-time Dashboard application password on Connect and add the displayed MainWP profile to the AI client.

== Changelog ==

= 0.5.0 =
* [NEW] Added select-all controls and a guided install-and-activate check for sites missing Novamira Free.
* [NEW] Added single-file direct child MCP configuration exports for every supported client format.
* [IMPROVE] Added Pro install-only, plugin-and-license activation, and combined install-and-activate actions.
* [FIX] Processed confirmed fleet actions as isolated per-site requests so one timeout or failure does not stop other sites.
* [SECURITY] Warned that direct exports contain plaintext child credentials and omitted unmanaged sites.
* [IMPROVE] Linked MainWP MCP Bridge references to its canonical repository.

= 0.4.0 =
* [NEW] Added a switch between a package built from the Dashboard's installed Novamira Pro copy and an uploaded audited Pro ZIP.
* [IMPROVE] Explained every policy control with practical recommendations and linked audit users and sites by name.
* [FIX] Read actual child AI, Pro license, application-password, and ability state and distinguish unchecked state from disabled or unlicensed state.
* [FIX] Added per-site and bulk live-status refresh for settings changed directly in child wp-admin.
* [SECURITY] Added a stored default-license indicator without exposing encrypted key material.

= 0.3.1 =
* [FIX] Protected the fixed one-shot child runtime from MainWP form-transport slash normalization.
* [COMPAT] Fully qualified WordPress, Throwable, RuntimeException, and optional Novamira Pro symbols inside MainWP Child's namespace.
* [DEV] Added transport-envelope regression coverage and verified the signed runtime against a real MainWP Child 6.1.5 site.

= 0.3.0 =
* [NEW] Rebuilt child management around MainWP Child's existing signed connection; no companion plugin is installed on client sites.
* [FIX] Listed all accessible MainWP sites by omitting the empty legacy search argument that returned an empty fleet on MainWP 6.1.
* [IMPROVE] Applied MainWP's labeled-icon extension navigation, fleet summaries, filters, contextual actions, and package cards.
* [SECURITY] Added Dashboard-owned five-minute AI access windows with prior-value restoration, concurrent-call tracking, and crash-safe cleanup.
* [COMPAT] Kept Novamira Free fully functional when Pro is absent, invalid, expired, or unreachable.

= 0.2.4 =
* [IMPROVE] Ensured future updates show the target release notes, WordPress and PHP requirements, and plugin icon before installation.
* [DEV] Completed cross-version update verification against the published GitHub release asset.

= 0.2.3 =
* [FIX] Preserved the target release's notes when checking future GitHub updates.
* [SECURITY] Restricted update-detail changelogs to categorized headings, lists, list items, and strong labels.

= 0.2.2 =
* [FIX] Added the WordPress requirement to fresh and cached update rows.
* [IMPROVE] Normalized update details to categorized, update-safe changelog HTML generated from the canonical changelog.

= 0.2.1 =
* [NEW] Published the first public GitHub release of the Dashboard fleet control plane, child companion, and routed MCP gateway.
* [SECURITY] Added exact-asset dashboard updates that reject source archives, prereleases, and unrelated ZIP files.
* [FIX] Made distribution inspection portable across Windows and Linux release runners.

= 0.2.0 =
* [NEW] Added the Dashboard fleet control plane, independently deployable child companion, and routed MCP gateway.
* [SECURITY] Added request-scoped five-minute AI leases, encrypted managed credentials, fail-closed routing, confirmed writes, and redacted auditing.
* [COMPAT] Kept Novamira Free unmodified and fully operational without Novamira Pro.
* [DEV] Added exact-asset public GitHub Release updates with packaged updater metadata and icon.

= 0.1.0 =
* [DEV] Established the initial add-on architecture and fleet-management contracts.

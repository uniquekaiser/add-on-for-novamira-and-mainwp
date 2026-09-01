=== Add-on for Novamira and MainWP ===
Contributors: synergetic
Tags: mainwp, mcp, ai, fleet-management
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Unofficial community add-on for Novamira and MainWP; not maintained by the Novamira or MainWP development teams.

== Description ==

This independently owned plugin runs only on the MainWP Dashboard. It uses each site's existing authenticated MainWP Child connection for one-shot status, settings, and application-password operations; no extra companion plugin is installed on client sites. Novamira Free is not modified, bundled, or repackaged.

On the Dashboard, the add-on provides fleet status, package deployment, encrypted credentials, redacted auditing, provider configurations, and the novamira-mainwp routed ability namespace. All 13 fleet abilities are registered through the standard WordPress Abilities API with names, descriptions, JSON schemas, REST/MCP visibility metadata, and safety annotations, so standards-compliant WordPress MCP implementations can discover them without plugin-specific code. MainWP MCP Bridge (https://github.com/uniquekaiser/mainwp-mcp-bridge) keeps control of its existing exposure, policy, rate-limit, resource, prompt, and confirmation architecture. Fleet status includes an explicit live refresh timestamp, so settings changed directly in child wp-admin are read through the existing signed MainWP connection instead of being inferred from add-on policy.

Novamira Free provides the MCP server and abilities. Pro controls remain optional: child installations can use a validated package built from the Pro copy installed on the MainWP Dashboard or an administrator-uploaded audited ZIP.

Add-on for Novamira and MainWP is an unofficial community project. It is not affiliated with, endorsed by, or maintained by the Novamira or MainWP development teams.

Report add-on bugs, feature requests, and feedback at https://github.com/uniquekaiser/add-on-for-novamira-and-mainwp/issues. Please do not direct add-on-specific support requests to the Novamira or MainWP development teams.

Official packages update from the public GitHub Releases page. Only the exact versioned plugin ZIP attached to the latest stable Release is accepted; GitHub source archives are never used.

== New-site onboarding ==

The MainWP Single Site Add Site form includes two native Add-ons Settings Synchronization choices: install the validated upstream Novamira Free plugin, and create a managed credential with safe gateway defaults. Safe defaults leave persistent AI enablement unchanged, use just-in-time access, deny production access, disable read fan-out, and never install optional Pro. MainWP Multiple Sites and CSV import flows do not show this native synchronization block; use Fleet bulk provisioning for those sites.

== Installation ==

1. Install MainWP Dashboard, MainWP MCP Bridge (https://github.com/uniquekaiser/mainwp-mcp-bridge), and Add-on for Novamira and MainWP on the Dashboard.
2. Use Fleet to install or activate validated upstream Novamira Free on selected child sites.
3. Optionally use the Novamira Pro copy installed on the Dashboard or upload an audited Pro ZIP on Packages.
4. Approve each production site's policy and create its managed credential.
5. Create a one-time Dashboard application password on Connect and add the displayed MainWP profile to the AI client.

== Changelog ==

= 0.7.1 =
* [FIX] Resolve child Novamira MCP endpoints from signed WordPress site data and prefer the rewrite-independent REST route.
* [COMPAT] Verified Novamira Free 1.12.0 and bundled MCP Adapter 0.6.1 ability discovery and routing.
* [SECURITY] Validate child-reported endpoints against the managed host, HTTPS policy, and exact Novamira MCP route.
* [FIX] Self-heal a stale HTTP 404 endpoint once for read initialization while keeping writes non-retrying.
* [IMPROVE] Use the rewrite-independent endpoint in provider profiles and fleet exports.

= 0.7.0 =
* [NEW] Added a fleet action that activates the stored Novamira Pro license without installing or activating the Pro plugin.
* [COMPAT] Verified WordPress 7.1 compatibility and added its standard public ability-discovery flag while preserving REST and MCP metadata.
* [IMPROVE] Added exact WordPress 7.1 compatibility metadata to fresh and cached dashboard update rows.
* [SECURITY] Prevented Git development checkouts from initializing the packaged public GitHub updater.
* [DEV] Added license-only isolation, ability-schema, discovery, UI, and updater-metadata regression coverage.

= 0.6.0 =
* [IMPROVE] Renamed the public project and plugin display name to Add-on for Novamira and MainWP.
* [NEW] Added native MainWP Add Site onboarding for validated Novamira Free installation and safe managed gateway setup.
* [SECURITY] Onboarding does not enable AI persistently, denies production access, uses JIT, disables fan-out, and leaves Pro optional.
* [COMPAT] Preserved internal identifiers and the novamira-mainwp ability namespace for seamless upgrades.
* [DEV] Updated the locked code-quality tooling to a patched release after its upstream command-injection advisory.

= 0.5.1 =
* [FIX] Added the combined Pro installation, plugin activation, and license activation operation to the registered WordPress Ability schema.
* [IMPROVE] Documented complete standard ability discovery metadata for all 13 fleet abilities.
* [IMPROVE] Clarified the unofficial community-project status and directed add-on support to GitHub Issues.
* [DEV] Added discovery-contract coverage for every registered fleet ability.

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

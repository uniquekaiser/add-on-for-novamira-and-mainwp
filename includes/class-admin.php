<?php
/**
 * MainWP-native fleet administration.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Admin {
	/** @var array<string, mixed>|\WP_Error|null */
	private static $result;

	public static function on_load(): void {
		if ( current_user_can( 'manage_options' ) ) {
			self::handle_post();
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage the Novamira fleet.', 'mainwp-novamira-addon' ) );
		}
		if ( null === self::$result && 'POST' === self::request_method() ) {
			self::handle_post();
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'fleet'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		do_action( 'mainwp_pageheader_extensions', NOVAMIRA_MAINWP_FILE );
		?>
		<div class="ui segment novamira-mainwp">
			<h1 class="ui header"><?php esc_html_e( 'Novamira for MainWP', 'mainwp-novamira-addon' ); ?></h1>
			<div class="ui labeled icon inverted menu mainwp-sub-submenu">
				<?php
				foreach ( array(
					'fleet'    => array( 'Fleet', 'sitemap' ),
					'connect'  => array( 'Connect', 'linkify' ),
					'policies' => array( 'Policies', 'shield alternate' ),
					'packages' => array( 'Packages', 'box' ),
					'audit'    => array( 'Audit', 'history' ),
				) as $slug => $item ) :
					?>
					<a class="<?php echo $tab === $slug ? 'active ' : ''; ?>item" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'Extensions-Mainwp-Novamira-Addon',
								'tab'  => $slug,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								"><i class="<?php echo esc_attr( $item[1] ); ?> icon"></i><?php echo esc_html( $item[0] ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php self::render_result(); ?>
			<?php
			if ( 'connect' === $tab ) {
				self::render_connect();
			} elseif ( 'policies' === $tab ) {
				self::render_policies();
			} elseif ( 'packages' === $tab ) {
				self::render_packages();
			} elseif ( 'audit' === $tab ) {
				self::render_audit();
			} else {
				self::render_fleet();
			}
			?>
		</div>
		<style>.novamira-mainwp .nmm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem}.novamira-mainwp pre{white-space:pre-wrap;overflow:auto;max-height:420px}.novamira-mainwp .nmm-actions{display:flex;gap:.4rem;flex-wrap:wrap}.novamira-mainwp .nmm-actions form{margin:0}.novamira-mainwp .nmm-ok{color:#16833d;font-weight:600}.novamira-mainwp .nmm-bad{color:#b42318;font-weight:600}.novamira-mainwp textarea{width:100%;font-family:monospace}.novamira-mainwp .nmm-summary{margin:1.5rem 0}.novamira-mainwp .nmm-filters{margin-bottom:1rem}.novamira-mainwp table small{overflow-wrap:anywhere}</style>
		<?php
		do_action( 'mainwp_pagefooter_extensions', NOVAMIRA_MAINWP_FILE );
	}

	public static function render_site(): void {
		$site_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $site_id ) {
			echo '<div class="ui negative message">' . esc_html__( 'No MainWP site was selected.', 'mainwp-novamira-addon' ) . '</div>';
			return;
		}
		$site = Fleet_Service::get_site( $site_id, false );
		if ( is_wp_error( $site ) ) {
			echo '<div class="ui negative message">' . esc_html( $site->get_error_message() ) . '</div>';
			return;
		}
		echo '<div class="wrap"><h2>' . esc_html( (string) $site['name'] ) . ' - Novamira</h2>';
		self::site_status_table( $site );
		echo '</div>';
	}

	private static function handle_post(): void {
		if ( 'POST' !== self::request_method() || empty( $_POST['novamira_mainwp_action'] ) ) {
			return;
		}
		check_admin_referer( 'novamira_mainwp_admin' );
		nocache_headers();
		$action  = sanitize_key( wp_unslash( $_POST['novamira_mainwp_action'] ) );
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;

		if ( 'refresh' === $action ) {
			self::$result = Fleet_Service::refresh_status( $site_id );
		} elseif ( 'rotate-credential' === $action ) {
			self::$result = Fleet_Service::rotate_credential( $site_id, true );
		} elseif ( 'revoke-credential' === $action ) {
			self::$result = Fleet_Service::revoke_credential( $site_id );
		} elseif ( 'save-policy' === $action ) {
			self::$result = Fleet_Service::set_policy(
				$site_id,
				array(
					'gateway_enabled'     => ! empty( $_POST['gateway_enabled'] ),
					'production_allowed'  => ! empty( $_POST['production_allowed'] ),
					'ai_lifecycle'        => isset( $_POST['ai_lifecycle'] ) ? sanitize_key( wp_unslash( $_POST['ai_lifecycle'] ) ) : 'just-in-time',
					'fanout_read_allowed' => ! empty( $_POST['fanout_read_allowed'] ),
					'allowed_abilities'   => self::lines( sanitize_textarea_field( wp_unslash( $_POST['allowed_abilities'] ?? '' ) ) ),
					'disabled_abilities'  => self::lines( sanitize_textarea_field( wp_unslash( $_POST['disabled_abilities'] ?? '' ) ) ),
				)
			);
		} elseif ( 'provision' === $action ) {
			$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
			$site_ids  = self::selected_site_ids( $site_id );
			$dry_run   = ! empty( $_POST['dry_run'] );
			if ( in_array( $operation, array( 'rotate-credential', 'revoke-credential' ), true ) ) {
				self::$result = self::bulk_credentials( $site_ids, $operation, $dry_run );
			} else {
				self::$result = Fleet_Service::provision( $site_ids, $operation, $dry_run );
			}
		} elseif ( 'bulk-policy' === $action ) {
			self::$result = self::bulk_policy( self::selected_site_ids( 0 ), ! empty( $_POST['dry_run'] ) );
		} elseif ( 'save-default-license' === $action ) {
			$license      = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			self::$result = Storage::set_default_pro_license( $license );
		} elseif ( 'upload-pro' === $action ) {
			self::$result = self::upload_package( 'pro' );
		} elseif ( 'create-dashboard-credential' === $action ) {
			self::$result = self::create_dashboard_credential();
		}
	}

	private static function render_fleet(): void {
		$fleet       = Fleet_Service::list_sites( 1, 100 );
		$all_sites   = isset( $fleet['items'] ) && is_array( $fleet['items'] ) ? $fleet['items'] : array();
		$view        = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$installed   = count(
			array_filter(
				$all_sites,
				static function ( array $site ): bool {
					return ! empty( $site['free']['installed'] );
				}
			)
		);
		$active      = count(
			array_filter(
				$all_sites,
				static function ( array $site ): bool {
					return ! empty( $site['free']['active'] );
				}
			)
		);
		$credentials = count(
			array_filter(
				$all_sites,
				static function ( array $site ): bool {
					return ! empty( $site['credential']['managed'] );
				}
			)
		);
		$sites       = array_values(
			array_filter(
				$all_sites,
				static function ( array $site ) use ( $view ): bool {
					if ( 'novamira' === $view ) {
						return ! empty( $site['free']['installed'] );
					}
					if ( 'missing' === $view ) {
						return empty( $site['free']['installed'] );
					}
					return true;
				}
			)
		);
		?>
		<div class="ui info message"><i class="linkify icon"></i><div class="content"><div class="header"><?php esc_html_e( 'No child companion is required', 'mainwp-novamira-addon' ); ?></div><p><?php esc_html_e( 'Every action uses the authenticated MainWP Child connection already established for each site. Novamira Free and optional Pro are the only Novamira plugins installed on child sites.', 'mainwp-novamira-addon' ); ?></p></div></div>
		<div class="ui four mini statistics nmm-summary">
			<div class="statistic"><div class="value"><?php echo esc_html( (string) count( $all_sites ) ); ?></div><div class="label">MainWP sites</div></div>
			<div class="statistic"><div class="value"><?php echo esc_html( (string) $installed ); ?></div><div class="label">Novamira installed</div></div>
			<div class="statistic"><div class="value"><?php echo esc_html( (string) $active ); ?></div><div class="label">Novamira active</div></div>
			<div class="statistic"><div class="value"><?php echo esc_html( (string) $credentials ); ?></div><div class="label">Managed credentials</div></div>
		</div>
		<div class="ui compact menu nmm-filters">
		<?php
		foreach ( array(
			'all'      => 'All sites',
			'novamira' => 'Novamira sites',
			'missing'  => 'Needs Novamira',
		) as $filter => $label ) :
			?>
			<a class="<?php echo $view === $filter ? 'active ' : ''; ?>item" href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'Extensions-Mainwp-Novamira-Addon',
						'tab'  => 'fleet',
						'view' => $filter,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		</div>
		<form method="post" id="nmm-bulk-fleet" class="ui form segment"><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="provision"><div class="inline fields"><label><?php esc_html_e( 'Bulk action', 'mainwp-novamira-addon' ); ?></label><select name="operation"><option value="install-free">Install and activate Free</option><option value="activate-free">Activate Free</option><option value="update-free">Update Free</option><option value="repair-free">Repair Free from upstream</option><option value="enable-ai">Enable AI abilities</option><option value="disable-ai">Disable AI abilities</option><option value="rotate-credential">Create/rotate credentials</option><option value="revoke-credential">Revoke credentials</option><option value="install-pro">Install optional Pro</option><option value="activate-pro">Activate optional Pro</option><option value="update-pro">Update optional Pro</option></select><button class="ui button" type="submit" name="dry_run" value="1"><i class="eye icon"></i>Preview</button><button class="ui green button" type="submit" onclick="return window.confirm('Run this action on every selected site?')"><i class="play icon"></i>Confirm and run</button></div></form>
		<?php if ( empty( $sites ) ) : ?>
			<div class="ui placeholder segment"><div class="ui icon header"><i class="plug icon"></i><?php esc_html_e( 'No sites match this view.', 'mainwp-novamira-addon' ); ?></div></div>
		<?php else : ?>
		<table class="ui compact celled selectable table"><thead><tr><th><span class="screen-reader-text">Select</span></th><th>Site</th><th>Novamira Free</th><th>Pro / license</th><th>AI / policy</th><th>Credential</th><th>Abilities</th><th>Last call</th><th>Quick actions</th></tr></thead><tbody>
			<?php foreach ( $sites as $site ) : ?>
			<tr>
				<td><input form="nmm-bulk-fleet" type="checkbox" name="site_ids[]" value="<?php echo esc_attr( (string) $site['id'] ); ?>" aria-label="Select <?php echo esc_attr( (string) $site['name'] ); ?>"></td>
				<td><strong><?php echo esc_html( $site['name'] ); ?></strong><br><small><a href="<?php echo esc_url( $site['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site['url'] ); ?></a></small><?php echo ! empty( $site['sync_error'] ) ? '<br><span class="nmm-bad">Sync issue</span>' : ''; ?></td>
				<td><?php echo esc_html( self::plugin_state( $site['free'] ) ); ?><?php echo ! empty( $site['free']['update_available'] ) ? '<br><span class="ui tiny orange label">Update ' . esc_html( (string) $site['free']['update_version'] ) . '</span>' : ''; ?></td>
				<td><?php echo esc_html( self::plugin_state( $site['pro'] ) ); ?><br><small><?php echo ! empty( $site['pro']['license_active'] ) ? '<span class="nmm-ok">Licensed</span>' : 'Optional / not licensed'; ?></small></td>
				<td><?php echo ! empty( $site['ai']['manual_enabled'] ) ? '<span class="nmm-ok">Enabled</span>' : 'Off'; ?><br><small><?php echo esc_html( (string) $site['policy']['ai_lifecycle'] ); ?><?php echo ! empty( $site['policy']['production_allowed'] ) ? ' / production approved' : ' / production denied'; ?></small></td>
				<td><?php echo ! empty( $site['credential']['managed'] ) ? '<span class="nmm-ok">Managed</span>' : '<span class="nmm-bad">Missing</span>'; ?><?php echo false === ( $site['credential']['healthy'] ?? null ) ? '<br><small class="nmm-bad">Needs rotation</small>' : ''; ?></td>
				<td><?php echo ! empty( $site['available_abilities_known'] ) ? esc_html( (string) count( (array) $site['available_abilities'] ) ) : 'On demand'; ?></td>
				<td><?php echo esc_html( (string) ( $site['last_success'] ?? 'Never' ) ); ?></td>
				<td><div class="nmm-actions">
					<?php self::action_button( (int) $site['id'], 'refresh', 'Refresh' ); ?>
					<?php
					if ( empty( $site['free']['installed'] ) ) :
						self::provision_button( (int) $site['id'], 'install-free', 'Install Free' );
						?>
						<?php
					elseif ( empty( $site['free']['active'] ) ) :
						self::provision_button( (int) $site['id'], 'activate-free', 'Activate' );
						?>
						<?php
					else :
						self::action_button( (int) $site['id'], 'rotate-credential', ! empty( $site['credential']['managed'] ) ? 'Rotate key' : 'Create key' );
						self::provision_button( (int) $site['id'], ! empty( $site['ai']['manual_enabled'] ) ? 'disable-ai' : 'enable-ai', ! empty( $site['ai']['manual_enabled'] ) ? 'Disable AI' : 'Enable AI' );
						?>
					<?php endif; ?>
					<?php
					if ( ! empty( $site['free']['update_available'] ) ) :
						self::provision_button( (int) $site['id'], 'update-free', 'Update' );
endif;
					?>
				</div></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
		<?php
	}

	private static function render_connect(): void {
		$one_time = is_array( self::$result ) && ! empty( self::$result['password'] );
		$username = $one_time ? (string) self::$result['username'] : '{MAINWP_USERNAME}';
		$password = $one_time ? (string) self::$result['password'] : '{MAINWP_APPLICATION_PASSWORD}';
		$url      = rest_url( 'mcp/mainwp' );
		$configs  = \Novamira_Provider_Config_Registry::build( $url, $username, $password, 'mainwp-novamira', false, 'mainwp-novamira-addon' );
		?>
		<div class="ui info message"><strong>One MCP connection:</strong> these profiles connect the AI client to MainWP; child credentials remain internal to the gateway.</div>
		<form method="post"><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="create-dashboard-credential"><button class="ui primary button" type="submit">Create one-time Dashboard credential and configs</button></form>
		<?php
		if ( $one_time ) :
			?>
			<div class="ui warning message"><strong>Copy now.</strong> This Dashboard application password will not be shown again.</div><?php endif; ?>
		<div class="nmm-grid">
		<?php foreach ( $configs as $slug => $config ) : ?>
			<details class="ui segment" <?php echo 'codex' === $slug ? 'open' : ''; ?>><summary><strong><?php echo esc_html( ucwords( str_replace( '-', ' ', $slug ) ) ); ?></strong></summary><p><?php echo wp_kses_post( (string) $config['hint'] ); ?></p><textarea readonly rows="12"><?php echo esc_textarea( (string) $config['code'] ); ?></textarea></details>
		<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_policies(): void {
		$fleet = Fleet_Service::list_sites( 1, 100 );
		?>
		<form method="post" class="ui segment"><h3>Apply one policy to selected sites</h3><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="bulk-policy"><div class="field"><select name="site_ids[]" multiple size="6" required>
		<?php
		foreach ( $fleet['items'] as $site ) :
			?>
			<option value="<?php echo esc_attr( (string) $site['id'] ); ?>"><?php echo esc_html( (string) $site['name'] ); ?></option><?php endforeach; ?></select></div><label><input type="checkbox" name="gateway_enabled" value="1" checked> Gateway enabled</label>&nbsp;&nbsp;<label><input type="checkbox" name="production_allowed" value="1"> Approve production JIT access</label>&nbsp;&nbsp;<label><input type="checkbox" name="fanout_read_allowed" value="1"> Allow read fan-out</label><label> AI lifecycle <select name="ai_lifecycle"><option value="just-in-time">Just in time</option><option value="manual-only">Manual only</option><option value="disabled">Disabled</option></select></label><div class="two fields"><label>Allow only these abilities (blank allows all except blocked)<br><textarea name="allowed_abilities" rows="4"></textarea></label><label>Blocked abilities, one per line<br><textarea name="disabled_abilities" rows="4"></textarea></label></div><button class="ui button" type="submit" name="dry_run" value="1">Preview</button><button class="ui primary button" type="submit" onclick="return window.confirm('Apply this policy to every selected site?')">Confirm and apply</button></form>
		<?php
		foreach ( $fleet['items'] as $site ) {
			$policy = $site['policy'];
			?>
			<form method="post" class="ui segment"><h3><?php echo esc_html( $site['name'] ); ?></h3><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="save-policy"><input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site['id'] ); ?>">
			<label><input type="checkbox" name="gateway_enabled" value="1" <?php checked( ! empty( $policy['gateway_enabled'] ) ); ?>> Gateway enabled</label>&nbsp;&nbsp;
			<label><input type="checkbox" name="production_allowed" value="1" <?php checked( ! empty( $policy['production_allowed'] ) ); ?>> Approve production JIT access</label>&nbsp;&nbsp;
			<label><input type="checkbox" name="fanout_read_allowed" value="1" <?php checked( ! empty( $policy['fanout_read_allowed'] ) ); ?>> Allow read fan-out</label>
			<label>AI lifecycle <select name="ai_lifecycle"><option value="just-in-time" <?php selected( $policy['ai_lifecycle'], 'just-in-time' ); ?>>Just in time</option><option value="manual-only" <?php selected( $policy['ai_lifecycle'], 'manual-only' ); ?>>Manual only</option><option value="disabled" <?php selected( $policy['ai_lifecycle'], 'disabled' ); ?>>Disabled</option></select></label>
			<div class="two fields"><label>Allow only these abilities (blank allows all except blocked)<br><textarea name="allowed_abilities" rows="4"><?php echo esc_textarea( implode( "\n", (array) $policy['allowed_abilities'] ) ); ?></textarea></label><label>Blocked abilities, one per line<br><textarea name="disabled_abilities" rows="4"><?php echo esc_textarea( implode( "\n", (array) $policy['disabled_abilities'] ) ); ?></textarea></label></div><button class="ui primary button" type="submit">Save policy</button></form>
			<?php
		}
	}

	private static function render_packages(): void {
		$packages = Storage::packages();
		$free     = Fleet_Service::free_package();
		?>
		<div class="ui info message"><i class="cloud download icon"></i><div class="content"><div class="header">Novamira Free is fetched automatically</div><p>There is no child companion package to upload. Install and repair actions validate Novamira Free directly from its HTTPS upstream release metadata before MainWP sends it to selected child sites.</p></div></div>
		<div class="nmm-grid">
		<section class="ui segment"><h3 class="ui header"><i class="plug icon"></i><span class="content">Novamira Free<span class="sub header">Required child plugin</span></span></h3>
		<?php
		if ( is_wp_error( $free ) ) :
			?>
			<div class="ui warning message"><?php echo esc_html( $free->get_error_message() ); ?></div>
			<?php
		else :
			?>
			<div class="ui relaxed list"><div class="item"><strong>Version:</strong> <?php echo esc_html( (string) $free['version'] ); ?></div><div class="item"><strong>Source:</strong> <code>license.dynamic.ooo</code></div><div class="item"><strong>Validated SHA-256:</strong><br><code><?php echo esc_html( (string) $free['sha256'] ); ?></code></div></div><?php endif; ?>
		<p><i class="check circle green icon"></i>No upload is needed.</p></section>
		<form method="post" enctype="multipart/form-data" class="ui segment"><h3 class="ui header"><i class="upload icon"></i><span class="content">Novamira Pro bootstrap ZIP<span class="sub header">Optional child plugin</span></span></h3><p>Upload an audited Pro release only when Pro should be centrally deployed. Free continues to work normally without it.</p><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="upload-pro"><div class="field"><input type="file" name="pro_zip" accept=".zip" required></div><button class="ui primary button" type="submit"><i class="shield alternate icon"></i>Validate and store Pro</button>
		<?php
		if ( ! empty( $packages['pro'] ) ) :
			?>
			<div class="ui positive message">Stored version <?php echo esc_html( (string) $packages['pro']['version'] ); ?><br><code><?php echo esc_html( (string) $packages['pro']['sha256'] ); ?></code></div><?php endif; ?></form>
		<form method="post" class="ui segment"><h3 class="ui header"><i class="key icon"></i><span class="content">Default Pro license<span class="sub header">Optional and encrypted</span></span></h3><p>A per-site license supplied through the ability overrides this default. Missing or invalid Pro licensing never blocks Free.</p><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="save-default-license"><div class="field"><input class="fluid" type="password" name="license_key" autocomplete="new-password"></div><button class="ui primary button" type="submit">Replace default license</button></form></div>
		<?php
	}

	private static function render_audit(): void {
		?>
		<table class="ui celled table"><thead><tr><th>Time</th><th>User</th><th>Site</th><th>Operation</th><th>Outcome</th><th>Duration</th><th>Correlation</th><th>Argument keys</th></tr></thead><tbody>
		<?php
		foreach ( Audit::recent() as $row ) :
			?>
			<tr><td><?php echo esc_html( $row['event_time'] ); ?></td><td><?php echo esc_html( $row['user_id'] ); ?></td><td><?php echo esc_html( $row['site_id'] ); ?></td><td><?php echo esc_html( $row['operation'] ); ?></td><td><?php echo esc_html( $row['outcome'] ); ?></td><td><?php echo esc_html( $row['duration_ms'] ); ?> ms</td><td><code><?php echo esc_html( $row['correlation_id'] ); ?></code></td><td><code><?php echo esc_html( $row['argument_keys'] ); ?></code></td></tr><?php endforeach; ?></tbody></table>
		<?php
	}

	private static function render_result(): void {
		if ( null === self::$result ) {
			return;
		}
		if ( is_wp_error( self::$result ) ) {
			echo '<div class="ui negative message">' . esc_html( self::$result->get_error_message() ) . '</div>';
			return;
		}
		echo '<div class="ui positive message">' . esc_html__( 'Operation completed.', 'mainwp-novamira-addon' ) . '</div>';
		if ( is_array( self::$result ) && ! empty( self::$result['dry_run'] ) ) {
			$ids = isset( self::$result['site_ids'] ) && is_array( self::$result['site_ids'] ) ? array_map( 'absint', self::$result['site_ids'] ) : array();
			echo '<div class="ui info message"><strong>' . esc_html__( 'Preview only:', 'mainwp-novamira-addon' ) . '</strong> ' . esc_html( (string) ( self::$result['operation'] ?? 'operation' ) ) . ' â€” ' . esc_html( implode( ', ', $ids ) ) . '</div>';
		}
		if ( is_array( self::$result ) && isset( self::$result['results'] ) && is_array( self::$result['results'] ) ) {
			echo '<table class="ui compact celled table"><thead><tr><th>Site ID</th><th>Outcome</th><th>Message</th></tr></thead><tbody>';
			foreach ( self::$result['results'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$ok      = ! empty( $item['ok'] );
				$message = $ok ? __( 'Completed', 'mainwp-novamira-addon' ) : (string) ( $item['error'] ?? __( 'Failed', 'mainwp-novamira-addon' ) );
				echo '<tr><td>' . esc_html( (string) ( $item['site_id'] ?? '' ) ) . '</td><td>' . ( $ok ? '<span class="nmm-ok">Success</span>' : '<span class="nmm-bad">Failed</span>' ) . '</td><td>' . esc_html( $message ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( is_array( self::$result ) && ! empty( self::$result['password'] ) && ! empty( self::$result['site_id'] ) ) {
			$site = Fleet_Service::get_site( (int) self::$result['site_id'], false );
			if ( ! is_wp_error( $site ) ) {
				$url     = trailingslashit( (string) $site['url'] ) . 'wp-json/mcp/novamira';
				$configs = \Novamira_Provider_Config_Registry::build( $url, (string) self::$result['username'], (string) self::$result['password'], 'novamira-' . (int) self::$result['site_id'], false, 'mainwp-novamira-addon' );
				echo '<div class="ui warning message"><strong>' . esc_html__( 'Optional direct-site fallback â€” copy now.', 'mainwp-novamira-addon' ) . '</strong> ' . esc_html__( 'This child credential will not be shown again. The primary setup remains the single MainWP gateway on the Connect tab.', 'mainwp-novamira-addon' ) . '</div><div class="nmm-grid">';
				foreach ( $configs as $client => $config ) {
					echo '<details class="ui segment"><summary><strong>' . esc_html( ucwords( str_replace( '-', ' ', $client ) ) ) . '</strong></summary><textarea readonly rows="10">' . esc_textarea( (string) $config['code'] ) . '</textarea></details>';
				}
				echo '</div>';
			}
		}
	}

	/** @param array<string,mixed> $site */
	private static function site_status_table( array $site ): void {
		$policy = isset( $site['policy'] ) && is_array( $site['policy'] ) ? $site['policy'] : array();
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>URL</th><td>' . esc_html( $site['url'] ) . '</td></tr>';
		echo '<tr><th>Novamira Free</th><td>' . esc_html( self::plugin_state( $site['free'] ) ) . '</td></tr>';
		echo '<tr><th>Novamira Pro</th><td>' . esc_html( self::plugin_state( $site['pro'] ) ) . ' / ' . ( ! empty( $site['pro']['license_active'] ) ? 'Licensed' : 'Not licensed' ) . '</td></tr>';
		echo '<tr><th>AI</th><td>' . ( ! empty( $site['ai']['manual_enabled'] ) ? 'Manually enabled' : 'Manually disabled' ) . ' / ' . esc_html( (string) ( $policy['ai_lifecycle'] ?? 'just-in-time' ) ) . '</td></tr>';
		echo '<tr><th>Production</th><td>' . ( ! empty( $policy['production_allowed'] ) ? 'Approved' : 'Denied' ) . '</td></tr>';
		echo '<tr><th>Credential</th><td>' . ( ! empty( $site['credential']['managed'] ) ? 'Managed' : 'Missing' ) . '</td></tr>';
		echo '<tr><th>Abilities</th><td>' . esc_html( implode( ', ', (array) ( $site['available_abilities'] ?? array() ) ) ) . '</td></tr>';
		echo '<tr><th>Last successful call</th><td>' . esc_html( (string) ( $site['last_success'] ?? 'Never' ) ) . '</td></tr>';
		echo '</tbody></table>';
	}

	private static function action_button( int $site_id, string $action, string $label ): void {
		?>
		<form method="post"><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="<?php echo esc_attr( $action ); ?>"><input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site_id ); ?>"><button class="ui mini button" type="submit"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	private static function provision_button( int $site_id, string $operation, string $label ): void {
		?>
		<form method="post"><?php wp_nonce_field( 'novamira_mainwp_admin' ); ?><input type="hidden" name="novamira_mainwp_action" value="provision"><input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>"><input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site_id ); ?>"><button class="ui mini button" type="submit"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	/** @param array<string,mixed> $state */
	private static function plugin_state( array $state ): string {
		if ( empty( $state['installed'] ) ) {
			return 'Not detected';
		}
		return ( ! empty( $state['active'] ) ? 'Active ' : 'Installed ' ) . (string) ( $state['version'] ?? '' );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function create_dashboard_credential() {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user || ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error( 'novamira_mainwp_dashboard_password_unavailable', 'Application Passwords are unavailable for this Dashboard administrator.' );
		}
		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => 'Novamira MainWP Gateway',
				'app_id' => wp_generate_uuid4(),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return array(
			'username' => $user->user_login,
			'password' => $created[0],
			'uuid'     => (string) ( $created[1]['uuid'] ?? '' ),
			'one_time' => true,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function upload_package( string $type ) {
		if ( 'pro' !== $type ) {
			return new \WP_Error( 'novamira_mainwp_package_type_invalid', 'Only an optional Novamira Pro package can be uploaded.' );
		}
		$field = 'pro_zip';
		$label = 'Novamira Pro';
		// The only caller verifies the administration nonce before dispatching.
		$upload    = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$temporary = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? wp_unslash( $upload['tmp_name'] ) : '';
		if ( '' === $temporary || ! is_uploaded_file( $temporary ) ) {
			return new \WP_Error( 'novamira_mainwp_upload_missing', 'Choose a ' . $label . ' ZIP to upload.' );
		}
		if ( (int) ( $upload['size'] ?? 0 ) > 52428800 ) {
			return new \WP_Error( 'novamira_mainwp_upload_too_large', 'The plugin ZIP exceeds the 50 MB limit.' );
		}
		$inspection = self::inspect_package_zip( $temporary, $type );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}
		$mainwp_dir = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir();
		$target_dir = trailingslashit( $mainwp_dir[0] ) . 'bulk/novamira-mainwp/';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new \WP_Error( 'novamira_mainwp_package_directory_failed', 'Could not create the private MainWP package directory.' );
		}
		$target = $target_dir . 'novamira-pro-' . sanitize_file_name( (string) $inspection['version'] ) . '.zip';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new \WP_Error( 'novamira_mainwp_filesystem_unavailable', 'WordPress could not initialize its filesystem for the validated Pro ZIP.' );
		}
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $temporary, $target, true ) ) {
			return new \WP_Error( 'novamira_mainwp_package_move_failed', 'Could not store the validated plugin ZIP.' );
		}
		$relative          = ltrim( str_replace( wp_normalize_path( $mainwp_dir[0] ), '', wp_normalize_path( $target ) ), '/' );
		$url               = admin_url( '?sig=' . \MainWP\Dashboard\MainWP_System_Utility::get_download_sig( $target ) . '&mwpdl=' . rawurlencode( $relative ) );
		$packages          = Storage::packages();
		$packages[ $type ] = array(
			'path'         => $target,
			'download_url' => $url,
			'version'      => $inspection['version'],
			'sha256'       => hash_file( 'sha256', $target ),
			'uploaded_at'  => current_time( 'mysql', true ),
		);
		Storage::save_packages( $packages );
		return $packages[ $type ];
	}

	/** @return array{version:string}|\WP_Error */
	private static function inspect_package_zip( string $path, string $type ) {
		$root          = 'novamira-pro/';
		$main_file     = $root . 'novamira-pro.php';
		$expected_name = 'Novamira Pro';
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_unavailable', 'ZipArchive is required to inspect plugin packages.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_invalid', 'The uploaded file is not a readable ZIP.' );
		}
		if ( $zip->numFiles > 5000 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$zip->close();
			return new \WP_Error( 'novamira_mainwp_zip_too_many_files', 'The plugin ZIP contains too many files.' );
		}
		$main              = '';
		$uncompressed_size = 0;
		for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name               = (string) $zip->getNameIndex( $index );
			$stat               = $zip->statIndex( $index );
			$uncompressed_size += is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			$attributes         = 0;
			$operations         = 0;
			$is_symlink         = $zip->getExternalAttributesIndex( $index, $operations, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 );
			if ( $uncompressed_size > 209715200 || $is_symlink || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, '../' ) || 0 === strpos( $name, '/' ) || 0 !== strpos( $name, $root ) ) {
				$zip->close();
				return new \WP_Error( 'novamira_mainwp_zip_unsafe', 'The plugin ZIP contains an unsafe path or invalid root directory.' );
			}
			if ( $main_file === $name ) {
				$main = (string) $zip->getFromIndex( $index );
			}
		}
		$zip->close();
		$plugin_name = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*([^\r\n]+)/mi', $main, $name_matches ) ? trim( $name_matches[1] ) : '';
		$version     = preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $main, $version_matches ) ? trim( $version_matches[1] ) : '';
		if ( '' === $main || 0 !== strcasecmp( $plugin_name, $expected_name ) || 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return new \WP_Error( 'novamira_mainwp_zip_header_missing', 'The ZIP does not contain the expected plugin root, main file, name, and version header.' );
		}
		return array( 'version' => $version );
	}

	/** @return array<int,string> */
	private static function lines( $value ): array {
		$value = is_scalar( $value ) ? (string) $value : '';
		$lines = preg_split( '/\R/', $value );
		return array_values( array_filter( array_map( 'trim', false === $lines ? array() : $lines ) ) );
	}

	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
	}

	/** @return array<int,int> */
	private static function selected_site_ids( int $fallback ): array {
		$raw = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ? wp_unslash( $_POST['site_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $raw ) ) ) );
		if ( empty( $ids ) && $fallback > 0 ) {
			$ids[] = $fallback;
		}
		return array_slice( $ids, 0, 100 );
	}

	/** @param array<int,int> $site_ids @return array<string,mixed> */
	private static function bulk_credentials( array $site_ids, string $operation, bool $dry_run ): array {
		if ( $dry_run ) {
			return array(
				'dry_run'   => true,
				'operation' => $operation,
				'site_ids'  => $site_ids,
				'count'     => count( $site_ids ),
			);
		}
		$results = array();
		foreach ( $site_ids as $selected_site_id ) {
			$result    = 'rotate-credential' === $operation ? Fleet_Service::rotate_credential( $selected_site_id, false ) : Fleet_Service::revoke_credential( $selected_site_id );
			$results[] = is_wp_error( $result ) ? array(
				'site_id' => $selected_site_id,
				'ok'      => false,
				'error'   => $result->get_error_message(),
			) : array(
				'site_id' => $selected_site_id,
				'ok'      => true,
				'result'  => $result,
			);
		}
		return array(
			'operation' => $operation,
			'results'   => $results,
		);
	}

	/** @param array<int,int> $site_ids @return array<string,mixed> */
	private static function bulk_policy( array $site_ids, bool $dry_run ): array {
		// The only caller verifies the administration nonce before dispatching.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$policy = array(
			'gateway_enabled'     => ! empty( $_POST['gateway_enabled'] ),
			'production_allowed'  => ! empty( $_POST['production_allowed'] ),
			'ai_lifecycle'        => isset( $_POST['ai_lifecycle'] ) ? sanitize_key( wp_unslash( $_POST['ai_lifecycle'] ) ) : 'just-in-time',
			'fanout_read_allowed' => ! empty( $_POST['fanout_read_allowed'] ),
			'allowed_abilities'   => self::lines( sanitize_textarea_field( wp_unslash( $_POST['allowed_abilities'] ?? '' ) ) ),
			'disabled_abilities'  => self::lines( sanitize_textarea_field( wp_unslash( $_POST['disabled_abilities'] ?? '' ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $dry_run ) {
			return array(
				'dry_run'   => true,
				'operation' => 'set-policy',
				'site_ids'  => $site_ids,
				'policy'    => $policy,
			);
		}
		$results = array();
		foreach ( $site_ids as $selected_site_id ) {
			$result    = Fleet_Service::set_policy( $selected_site_id, $policy );
			$results[] = is_wp_error( $result ) ? array(
				'site_id' => $selected_site_id,
				'ok'      => false,
				'error'   => $result->get_error_message(),
			) : array(
				'site_id' => $selected_site_id,
				'ok'      => true,
				'result'  => $result,
			);
		}
		return array(
			'operation' => 'set-policy',
			'results'   => $results,
		);
	}
}

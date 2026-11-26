<?php
/**
 * Browser-only fleet settings page.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Settings_Admin {
	public static function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) || ! wp_get_session_token() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in to the Dashboard as an administrator.', 'mainwp-novamira-addon' ) ), 403 );
		}
		check_ajax_referer( 'novamira_mainwp_bulk_site', 'nonce' );

		// JSON preserves booleans and distinguishes a cleared text field from an omitted one.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded settings are validated by Novamira_Settings.
		$raw  = isset( $_POST['settings'] ) && is_string( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		$site = isset( $_POST['site_id'] ) && is_string( $_POST['site_id'] ) ? wp_unslash( $_POST['site_id'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input = strlen( $raw ) <= 70000 ? json_decode( $raw, true, 8 ) : null;
		if ( ! is_array( $input ) || ! preg_match( '/^[1-9][0-9]*$/D', $site ) || (string) (int) $site !== $site ) {
			wp_send_json_error( array( 'message' => __( 'Invalid site or settings request.', 'mainwp-novamira-addon' ) ), 400 );
		}
		$result = Novamira_Settings::request( (int) $site, $input );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function render(): void {
		$page   = isset( $_GET['settings_page'] ) ? max( 1, min( 10000, absint( $_GET['settings_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['search'] ) && is_string( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sites  = MainWP_Client::sites( $page, 50, $search );
		$url    = add_query_arg(
			array(
				'page'   => 'Extensions-Mainwp-Novamira-Addon',
				'tab'    => 'settings',
				'search' => $search,
			),
			admin_url( 'admin.php' )
		);
		?>
		<section id="nmm-settings">
			<h2><?php esc_html_e( 'Novamira settings', 'mainwp-novamira-addon' ); ?></h2>
			<p><?php esc_html_e( 'Select sites, then preview the settings you want to change. Select Inspect sites to see each site’s approval level and feature IDs.', 'mainwp-novamira-addon' ); ?></p>
			<form method="get" class="ui form">
				<input type="hidden" name="page" value="Extensions-Mainwp-Novamira-Addon">
				<input type="hidden" name="tab" value="settings">
				<label for="nmm-settings-search"><?php esc_html_e( 'Find sites by name or URL', 'mainwp-novamira-addon' ); ?></label>
				<input type="search" id="nmm-settings-search" name="search" value="<?php echo esc_attr( $search ); ?>">
				<button type="submit" class="ui button"><?php esc_html_e( 'Search', 'mainwp-novamira-addon' ); ?></button>
			</form>
			<table class="ui compact table">
				<thead><tr><th scope="col"><input id="nmm-settings-all" type="checkbox" aria-label="<?php esc_attr_e( 'Select all sites on this page', 'mainwp-novamira-addon' ); ?>"></th><th scope="col"><?php esc_html_e( 'Site', 'mainwp-novamira-addon' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<tr><td><input type="checkbox" class="nmm-settings-site" value="<?php echo esc_attr( (string) $site->id ); ?>" data-site-name="<?php echo esc_attr( (string) $site->name ); ?>" aria-label="<?php echo esc_attr( (string) $site->name ); ?>" <?php disabled( ! empty( $site->suspended ) ); ?>></td>
						<td><?php echo esc_html( (string) $site->name ); ?><br><small><?php echo esc_html( (string) $site->url ); ?></small></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $sites ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No sites found.', 'mainwp-novamira-addon' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $page > 1 ) : ?>
				<a class="ui button" href="<?php echo esc_url( add_query_arg( 'settings_page', $page - 1, $url ) ); ?>"><?php esc_html_e( 'Previous sites', 'mainwp-novamira-addon' ); ?></a>
			<?php endif; ?>
			<?php if ( 50 === count( $sites ) ) : ?>
				<a class="ui button" href="<?php echo esc_url( add_query_arg( 'settings_page', $page + 1, $url ) ); ?>"><?php esc_html_e( 'Next sites', 'mainwp-novamira-addon' ); ?></a>
			<?php endif; ?>
			<button type="button" class="ui button" id="nmm-settings-inspect"><?php esc_html_e( 'Inspect sites', 'mainwp-novamira-addon' ); ?></button>
			<?php self::render_form(); ?>
			<datalist id="nmm-feature-ids"></datalist>
			<datalist id="nmm-ability-ids"></datalist>
			<p id="nmm-settings-status" role="status"></p>
			<table id="nmm-settings-results" class="ui compact celled table" hidden>
				<thead><tr><th scope="col"><?php esc_html_e( 'Site', 'mainwp-novamira-addon' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'mainwp-novamira-addon' ); ?></th></tr></thead>
				<tbody></tbody>
			</table>
			<button type="button" class="ui primary button" id="nmm-settings-apply" hidden><?php esc_html_e( 'Apply previewed changes', 'mainwp-novamira-addon' ); ?></button>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to preview and apply bulk settings.', 'mainwp-novamira-addon' ); ?></p></noscript>
		</section>
		<?php
	}

	private static function render_form(): void {
		?>
		<form id="nmm-settings-form" class="ui form segment">
			<div class="field"><label for="nmm-settings-scope"><?php esc_html_e( 'Settings to configure', 'mainwp-novamira-addon' ); ?></label>
				<select id="nmm-settings-scope">
					<option value="general"><?php esc_html_e( 'Approvals, email and context', 'mainwp-novamira-addon' ); ?></option>
					<option value="feature"><?php esc_html_e( 'Feature or specialization', 'mainwp-novamira-addon' ); ?></option>
					<option value="ability_approval"><?php esc_html_e( 'Approval for an individual ability', 'mainwp-novamira-addon' ); ?></option>
					<option value="ability_enabled"><?php esc_html_e( 'Enable or disable an individual ability', 'mainwp-novamira-addon' ); ?></option>
					<option value="read_mode"><?php esc_html_e( 'Read Mode', 'mainwp-novamira-addon' ); ?></option>
					<option value="ghost_mode"><?php esc_html_e( 'Ghost Mode', 'mainwp-novamira-addon' ); ?></option>
					<option value="reset_destinations"><?php esc_html_e( 'Reset approved destinations', 'mainwp-novamira-addon' ); ?></option>
				</select>
			</div>
			<fieldset class="ui segment" data-nmm-settings-scope="general">
				<div class="field"><label for="nmm-approval-level"><?php esc_html_e( 'Approval level', 'mainwp-novamira-addon' ); ?></label>
					<select id="nmm-approval-level"><option value=""><?php esc_html_e( 'Keep each site’s setting', 'mainwp-novamira-addon' ); ?></option>
					<?php foreach ( Novamira_Settings::levels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					</select>
				</div>
				<div class="field"><label for="nmm-hold-emails"><?php esc_html_e( 'AI-generated emails', 'mainwp-novamira-addon' ); ?></label>
					<select id="nmm-hold-emails">
						<option value=""><?php esc_html_e( 'Keep each site’s setting', 'mainwp-novamira-addon' ); ?></option>
						<option value="on"><?php esc_html_e( 'Hold for review', 'mainwp-novamira-addon' ); ?></option>
						<option value="off"><?php esc_html_e( 'Send normally', 'mainwp-novamira-addon' ); ?></option>
					</select>
				</div>
				<div class="field"><label><input id="nmm-change-approval-instructions" type="checkbox"> <?php esc_html_e( 'Replace approval instructions', 'mainwp-novamira-addon' ); ?></label>
					<textarea id="nmm-approval-instructions" rows="3" maxlength="2000" aria-label="<?php esc_attr_e( 'Approval instructions', 'mainwp-novamira-addon' ); ?>"></textarea>
				</div>
				<div class="field"><label><input id="nmm-change-user-context" type="checkbox"> <?php esc_html_e( 'Replace user context', 'mainwp-novamira-addon' ); ?></label>
					<textarea id="nmm-user-context" rows="3" maxlength="8000" aria-label="<?php esc_attr_e( 'User context', 'mainwp-novamira-addon' ); ?>"></textarea>
				</div>
				<p><?php esc_html_e( 'Selecting Replace and leaving the text field empty clears that setting.', 'mainwp-novamira-addon' ); ?></p>
			</fieldset>
			<fieldset class="ui segment" data-nmm-settings-scope="ghost_mode" hidden>
				<label for="nmm-settings-ghost-mode"><?php esc_html_e( 'Ghost Mode', 'mainwp-novamira-addon' ); ?></label>
				<select id="nmm-settings-ghost-mode">
					<option value="on"><?php esc_html_e( 'Turn on with this site’s existing owners', 'mainwp-novamira-addon' ); ?></option>
					<option value="off"><?php esc_html_e( 'Turn off', 'mainwp-novamira-addon' ); ?></option>
				</select>
			</fieldset>
			<fieldset class="ui segment" data-nmm-settings-scope="reset_destinations" hidden>
				<p><?php esc_html_e( 'Clear the approved destinations list on each selected site. Future requests can ask for approval again.', 'mainwp-novamira-addon' ); ?></p>
			</fieldset>
			<fieldset class="ui segment" data-nmm-settings-scope="read_mode" hidden>
				<label for="nmm-settings-read-mode"><?php esc_html_e( 'Read Mode', 'mainwp-novamira-addon' ); ?></label>
				<select id="nmm-settings-read-mode">
					<option value="on"><?php esc_html_e( 'Turn on with this site’s existing choices', 'mainwp-novamira-addon' ); ?></option>
					<option value="off"><?php esc_html_e( 'Turn off', 'mainwp-novamira-addon' ); ?></option>
				</select>
			</fieldset>
			<?php
			self::render_target_fields(
				'feature',
				__( 'Feature ID', 'mainwp-novamira-addon' ),
				array(
					'on'  => __( 'Enable', 'mainwp-novamira-addon' ),
					'off' => __( 'Disable', 'mainwp-novamira-addon' ),
				)
			);
			self::render_target_fields(
				'ability_approval',
				__( 'Ability name', 'mainwp-novamira-addon' ),
				array(
					'level' => __( 'Follow approval level', 'mainwp-novamira-addon' ),
					'yes'   => __( 'Always ask', 'mainwp-novamira-addon' ),
					'no'    => __( 'Never ask', 'mainwp-novamira-addon' ),
				)
			);
			self::render_target_fields(
				'ability_enabled',
				__( 'Ability name', 'mainwp-novamira-addon' ),
				array(
					'on'  => __( 'Enable', 'mainwp-novamira-addon' ),
					'off' => __( 'Disable', 'mainwp-novamira-addon' ),
				)
			);
			?>
			<button type="submit" class="ui button"><?php esc_html_e( 'Preview changes', 'mainwp-novamira-addon' ); ?></button>
		</form>
		<?php
	}

	/** @param array<string,string> $choices Choices by value. */
	private static function render_target_fields( string $scope, string $label, array $choices ): void {
		$id   = 'nmm-settings-' . $scope;
		$list = 'feature' === $scope ? 'nmm-feature-ids' : 'nmm-ability-ids';
		?>
		<fieldset class="ui segment" data-nmm-settings-scope="<?php echo esc_attr( $scope ); ?>" hidden>
			<div class="field">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>" type="text" maxlength="191" data-nmm-target-id list="<?php echo esc_attr( $list ); ?>">
			</div>
			<div class="field"><label for="<?php echo esc_attr( $id . '-choice' ); ?>"><?php esc_html_e( 'Change to', 'mainwp-novamira-addon' ); ?></label>
				<select id="<?php echo esc_attr( $id . '-choice' ); ?>" data-nmm-target-choice>
					<?php foreach ( $choices as $key => $choice ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $choice ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</fieldset>
		<?php
	}
}

<?php
/**
 * Stable fleet abilities exposed through the WordPress Abilities API.
 *
 * @package NovamiraMainWP
 */

declare( strict_types=1 );

namespace Novamira\MainWP;

final class Abilities {
	public static function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'novamira-mainwp',
				array(
					'label'       => __( 'Novamira Fleet', 'mainwp-novamira-addon' ),
					'description' => __( 'Securely manage and route Novamira across MainWP child sites.', 'mainwp-novamira-addon' ),
				)
			);
		}
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::ability( 'list-sites-v1', 'List Novamira sites', 'List the managed MainWP fleet and cached Novamira status.', self::list_schema(), array( self::class, 'list_sites' ), true );
		self::ability( 'get-site-v1', 'Get Novamira site', 'Get one managed site and optionally refresh its Novamira status.', self::site_schema( true ), array( self::class, 'get_site' ), true );
		self::ability( 'provision-sites-v1', 'Provision Novamira sites', 'Install, activate, update, or repair Novamira Free and optional Pro through the existing MainWP Child connection, including combined Pro plugin and license activation.', self::provision_schema(), array( self::class, 'provision' ), false, true );
		self::ability( 'set-site-policy-v1', 'Set Novamira site policy', 'Set production approval, gateway access, fan-out, and ability rules.', self::policy_schema(), array( self::class, 'set_policy' ), false, true );
		self::ability( 'rotate-credential-v1', 'Rotate Novamira credential', 'Create and store a new managed child application password without returning its plaintext.', self::confirmed_site_schema(), array( self::class, 'rotate_credential' ), false, true );
		self::ability( 'revoke-credential-v1', 'Revoke Novamira credential', 'Revoke and remove the managed child application password.', self::confirmed_site_schema(), array( self::class, 'revoke_credential' ), false, true );
		self::ability( 'manage-pro-license-v1', 'Manage Novamira Pro license', 'Optionally activate, refresh, or deactivate Novamira Pro licensing.', self::pro_license_schema(), array( self::class, 'manage_pro_license' ), false, true );
		self::ability( 'list-components-v1', 'List child MCP components', 'List tools, resources, or prompts from one leased Novamira child MCP server.', self::components_schema(), array( self::class, 'list_components' ), true );
		self::ability( 'call-read-tool-v1', 'Call child read tool', 'Call a remote MCP tool only after it is verified as read-only.', self::tool_schema( false ), array( self::class, 'call_read_tool' ), true );
		self::ability( 'fanout-read-tool-v1', 'Fan out child read tool', 'Run the same verified read-only MCP tool across approved sites.', self::fanout_schema(), array( self::class, 'fanout_read_tool' ), true );
		self::ability( 'call-write-tool-v1', 'Call child write tool', 'Call one mutating remote MCP tool after explicit confirmation.', self::tool_schema( true ), array( self::class, 'call_write_tool' ), false, true );
		self::ability( 'read-resource-v1', 'Read child MCP resource', 'Read a resource from one leased Novamira child MCP server.', self::resource_schema(), array( self::class, 'read_resource' ), true );
		self::ability( 'get-prompt-v1', 'Get child MCP prompt', 'Get a prompt from one leased Novamira child MCP server.', self::prompt_schema(), array( self::class, 'get_prompt' ), true );
	}

	/** @return bool|\WP_Error */
	public static function permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error( 'novamira_mainwp_permission_denied', 'Authentication is required.' );
		}
		return true;
	}

	/** @return array<string, mixed> */
	public static function list_sites( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return Fleet_Service::list_sites( (int) ( $input['page'] ?? 1 ), (int) ( $input['per_page'] ?? 50 ), (string) ( $input['search'] ?? '' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function get_site( $input ) {
		return Fleet_Service::get_site( (int) $input['site_id'], ! empty( $input['refresh'] ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function provision( $input ) {
		if ( is_array( $input ) && ! empty( $input['dry_run'] ) ) {
			return Fleet_Service::provision( (array) $input['site_ids'], sanitize_key( (string) $input['operation'] ), true );
		}
		$confirmation = self::confirm_or_preview( $input, 'provision' );
		if ( null !== $confirmation ) {
			return $confirmation;
		}
		return Fleet_Service::provision( (array) $input['site_ids'], sanitize_key( (string) $input['operation'] ), false );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function set_policy( $input ) {
		$confirmation = self::confirm_or_preview( $input, 'set-policy' );
		if ( null !== $confirmation ) {
			return $confirmation;
		}
		return Fleet_Service::set_policy(
			(int) $input['site_id'],
			array(
				'gateway_enabled'     => true === ( $input['gateway_enabled'] ?? false ),
				'production_allowed'  => true === ( $input['production_allowed'] ?? false ),
				'ai_lifecycle'        => (string) ( $input['ai_lifecycle'] ?? 'just-in-time' ),
				'fanout_read_allowed' => true === ( $input['fanout_read_allowed'] ?? false ),
				'allowed_abilities'   => (array) ( $input['allowed_abilities'] ?? array() ),
				'disabled_abilities'  => (array) ( $input['disabled_abilities'] ?? array() ),
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function rotate_credential( $input ) {
		$confirmation = self::confirm_or_preview( $input, 'rotate-credential' );
		return null !== $confirmation ? $confirmation : Fleet_Service::rotate_credential( (int) $input['site_id'], false );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function revoke_credential( $input ) {
		$confirmation = self::confirm_or_preview( $input, 'revoke-credential' );
		return null !== $confirmation ? $confirmation : Fleet_Service::revoke_credential( (int) $input['site_id'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function manage_pro_license( $input ) {
		$confirmation = self::confirm_or_preview( $input, 'manage-pro-license' );
		return null !== $confirmation
			? $confirmation
			: Fleet_Service::manage_pro_license( (int) $input['site_id'], sanitize_key( (string) $input['operation'] ), (string) ( $input['license_key'] ?? '' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function list_components( $input ) {
		return Fleet_Service::list_components( (int) $input['site_id'], (string) $input['type'], (string) ( $input['search'] ?? '' ), (string) ( $input['cursor'] ?? '' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function call_read_tool( $input ) {
		return Fleet_Service::call_tool( (int) $input['site_id'], (string) $input['tool_name'], (array) ( $input['arguments'] ?? array() ), 'read' );
	}

	/** @return array<string, mixed> */
	public static function fanout_read_tool( $input ): array {
		$results  = array();
		$site_ids = array_slice( array_values( array_unique( array_map( 'intval', (array) $input['site_ids'] ) ) ), 0, 10 );
		foreach ( $site_ids as $site_id ) {
			$policy = Storage::policy( $site_id );
			if ( ! $policy['fanout_read_allowed'] ) {
				$results[] = array(
					'site_id' => $site_id,
					'ok'      => false,
					'error'   => 'Read fan-out is not approved for this site.',
				);
				continue;
			}
			$result    = Fleet_Service::call_tool( $site_id, (string) $input['tool_name'], (array) ( $input['arguments'] ?? array() ), 'read' );
			$results[] = is_wp_error( $result )
				? array(
					'site_id' => $site_id,
					'ok'      => false,
					'error'   => $result->get_error_message(),
				)
				: array(
					'site_id' => $site_id,
					'ok'      => true,
					'result'  => $result,
				);
		}
		return array( 'results' => $results );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function call_write_tool( $input ) {
		$confirmation = self::confirm_or_preview( $input, 'call-write-tool' );
		if ( null !== $confirmation ) {
			return $confirmation;
		}
		return Fleet_Service::call_tool( (int) $input['site_id'], (string) $input['tool_name'], (array) ( $input['arguments'] ?? array() ), 'write' );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function read_resource( $input ) {
		return Fleet_Service::read_resource( (int) $input['site_id'], (string) $input['uri'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function get_prompt( $input ) {
		return Fleet_Service::get_prompt( (int) $input['site_id'], (string) $input['name'], (array) ( $input['arguments'] ?? array() ) );
	}

	/**
	 * @param callable $callback
	 * @param array<string,mixed> $schema
	 */
	private static function ability( string $slug, string $label, string $description, array $schema, callable $callback, bool $read_only, bool $destructive = false ): void {
		wp_register_ability(
			'novamira-mainwp/' . $slug,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'novamira-mainwp',
				'input_schema'        => $schema,
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => $callback,
				'permission_callback' => array( self::class, 'permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'instructions' => $destructive ? 'Use dry_run first and execute only after explicit user confirmation.' : '',
						'readonly'     => $read_only,
						'destructive'  => $destructive,
						'idempotent'   => $read_only,
					),
				),
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error|null */
	private static function confirm_or_preview( $input, string $operation ) {
		$input = is_array( $input ) ? $input : array();
		if ( true === ( $input['dry_run'] ?? false ) ) {
			$preview = $input;
			unset( $preview['license_key'], $preview['arguments'], $preview['confirm'], $preview['dry_run'] );
			if ( isset( $input['arguments'] ) && is_array( $input['arguments'] ) ) {
				$preview['argument_keys'] = array_keys( $input['arguments'] );
			}
			return array(
				'dry_run'   => true,
				'operation' => $operation,
				'preview'   => $preview,
			);
		}
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'novamira_mainwp_confirmation_required', 'Set dry_run=true for a preview or confirm=true after explicit approval.' );
		}
		return null;
	}

	/** @return array<string, mixed> */
	private static function confirmation_fields(): array {
		return array(
			'confirm' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Execute after explicit user approval.',
			),
			'dry_run' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Preview without changing a site.',
			),
		);
	}

	/** @return array<string, mixed> */
	private static function list_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
				'search'   => array( 'type' => 'string' ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function site_schema( bool $refresh = false ): array {
		$properties = array(
			'site_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( $refresh ) {
			$properties['refresh'] = array(
				'type'    => 'boolean',
				'default' => false,
			);
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'site_id' ),
		);
	}

	/** @return array<string, mixed> */
	private static function confirmed_site_schema(): array {
		$schema               = self::site_schema();
		$schema['properties'] = array_merge( $schema['properties'], self::confirmation_fields() );
		return $schema;
	}

	/** @return array<string, mixed> */
	private static function provision_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'site_ids'  => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'minItems' => 1,
						'maxItems' => 100,
					),
					'operation' => array(
						'type' => 'string',
						'enum' => array( 'repair-free', 'install-free', 'activate-free', 'update-free', 'enable-ai', 'disable-ai', 'install-pro', 'install-activate-pro', 'activate-pro', 'update-pro' ),
					),
				),
				self::confirmation_fields()
			),
			'required'   => array( 'site_ids', 'operation' ),
		);
	}

	/** @return array<string, mixed> */
	private static function policy_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'site_id'             => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'gateway_enabled'     => array( 'type' => 'boolean' ),
					'production_allowed'  => array( 'type' => 'boolean' ),
					'ai_lifecycle'        => array(
						'type'    => 'string',
						'enum'    => array( 'just-in-time', 'manual-only', 'disabled' ),
						'default' => 'just-in-time',
					),
					'fanout_read_allowed' => array( 'type' => 'boolean' ),
					'allowed_abilities'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'disabled_abilities'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				self::confirmation_fields()
			),
			'required'   => array( 'site_id', 'gateway_enabled', 'production_allowed', 'fanout_read_allowed' ),
		);
	}

	/** @return array<string, mixed> */
	private static function pro_license_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'site_id'     => array( 'type' => 'integer' ),
					'operation'   => array(
						'type' => 'string',
						'enum' => array( 'activate', 'refresh', 'deactivate' ),
					),
					'license_key' => array(
						'type'        => 'string',
						'description' => 'Optional per-site override; never logged or returned.',
					),
				),
				self::confirmation_fields()
			),
			'required'   => array( 'site_id', 'operation' ),
		);
	}

	/** @return array<string, mixed> */
	private static function components_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'site_id' => array( 'type' => 'integer' ),
				'type'    => array(
					'type' => 'string',
					'enum' => array( 'tools', 'resources', 'prompts' ),
				),
				'search'  => array( 'type' => 'string' ),
				'cursor'  => array( 'type' => 'string' ),
			),
			'required'   => array( 'site_id', 'type' ),
		);
	}

	/** @return array<string, mixed> */
	private static function tool_schema( bool $confirmed ): array {
		$properties = array(
			'site_id'   => array( 'type' => 'integer' ),
			'tool_name' => array( 'type' => 'string' ),
			'arguments' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
		);
		if ( $confirmed ) {
			$properties = array_merge( $properties, self::confirmation_fields() );
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'site_id', 'tool_name', 'arguments' ),
		);
	}

	/** @return array<string, mixed> */
	private static function fanout_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'site_ids'  => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'minItems' => 1,
					'maxItems' => 10,
				),
				'tool_name' => array( 'type' => 'string' ),
				'arguments' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'   => array( 'site_ids', 'tool_name', 'arguments' ),
		);
	}

	/** @return array<string, mixed> */
	private static function resource_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'site_id' => array( 'type' => 'integer' ),
				'uri'     => array( 'type' => 'string' ),
			),
			'required'   => array( 'site_id', 'uri' ),
		);
	}

	/** @return array<string, mixed> */
	private static function prompt_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'site_id'   => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'arguments' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'   => array( 'site_id', 'name' ),
		);
	}
}

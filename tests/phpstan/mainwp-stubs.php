<?php

namespace MainWP\Dashboard {
	class MainWP_DB {
		public static function instance(): self {}
		public function get_website_by_id( int $site_id ) {}
		public function get_websites_for_current_user( array $args ): array {}
	}

	class MainWP_System_Utility {
		public static function can_edit_website( $site ): bool {}
		public static function get_mainwp_dir(): array {}
		public static function get_download_sig( string $path ): string {}
	}
}

namespace Novamira\Pro {
	function activate_new_license_key( string $key ): array {}
	function deactivate_license(): array {}
	function is_license_active(): bool {}
	function license_error(): string {}
	function license_key_masked(): string {}
	function refresh_and_repair_license_status(): void {}
}

namespace {
	function wp_register_ability_category( string $name, array $args ): void {}
	function wp_register_ability( string $name, array $args ): void {}
}

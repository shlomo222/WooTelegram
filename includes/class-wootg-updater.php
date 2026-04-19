<?php
/**
 * GitHub Releases–based plugin updates (private repo compatible).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects update metadata from GitHub into WordPress core updater.
 */
class WooTG_Updater {

	private string $github_user = 'shlomo222';

	private string $github_repo = 'WooTelegram';

	private string $transient_key = 'wootg_github_update_check';

	private int $cache_hours = 12;

	/**
	 * Plugin basename relative to wp-content/plugins (e.g. Folder/file.php).
	 */
	private string $plugin_file;

	/**
	 * Plugin directory slug (folder name).
	 */
	private string $slug;

	public function __construct() {
		$this->plugin_file = plugin_basename( WOOTG_FILE );
		$this->slug        = dirname( $this->plugin_file );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'plugin_action_links_' . $this->plugin_file, array( $this, 'action_links' ) );
		add_action( 'admin_post_wootg_force_update_check', array( $this, 'force_update_check' ) );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_name' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'maybe_authorize_github_http' ), 10, 2 );
	}

	/**
	 * Fetch latest release (cached unless $force).
	 *
	 * @return array{version: string, tag: string, body: string, published_at: string, zip_url: string, html_url: string}|null
	 */
	public function get_remote_release( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( $this->transient_key );
			if ( is_array( $cached ) && isset( $cached['version'], $cached['zip_url'] ) ) {
				return $cached;
			}
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->github_user ),
			rawurlencode( $this->github_repo )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->build_github_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			WooTG_Logger::log_error(
				'updater',
				'github api error: ' . $response->get_error_message(),
				array( 'url' => $url )
			);
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			WooTG_Logger::log_error(
				'updater',
				'github api error: ' . (string) $code,
				array(
					'url'  => $url,
					'body' => wp_remote_retrieve_body( $response ),
				)
			);
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			WooTG_Logger::log_error(
				'updater',
				'github api error: invalid release payload',
				array( 'url' => $url )
			);
			return null;
		}

		$tag     = (string) $data['tag_name'];
		$version = $this->normalize_release_version( $tag );

		$zip_url = sprintf(
			'https://api.github.com/repos/%s/%s/zipball/%s',
			$this->github_user,
			$this->github_repo,
			rawurlencode( $tag )
		);

		$payload = array(
			'version'      => $version,
			'tag'          => $tag,
			'body'         => isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) && is_string( $data['published_at'] ) ? $data['published_at'] : '',
			'zip_url'      => $zip_url,
			'html_url'     => isset( $data['html_url'] ) && is_string( $data['html_url'] ) ? $data['html_url'] : '',
		);

		set_site_transient( $this->transient_key, $payload, $this->cache_hours * HOUR_IN_SECONDS );

		return $payload;
	}

	/**
	 * @param object $transient update_plugins transient payload.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->checked[ $this->plugin_file ] ) ) {
			return $transient;
		}

		$release = $this->get_remote_release( false );
		if ( null === $release ) {
			return $transient;
		}

		$remote = $release['version'];
		$local  = WOOTG_VERSION;

		if ( version_compare( $remote, $local, '>' ) ) {
			$transient->response[ $this->plugin_file ] = (object) array(
				'id'            => $this->plugin_file,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_file,
				'new_version'   => $remote,
				'url'           => $release['html_url'],
				'package'       => $release['zip_url'],
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '6.6',
				'requires_php'  => '8.0',
			);
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->plugin_file ] = (object) array(
				'id'           => $this->plugin_file,
				'slug'         => $this->slug,
				'plugin'       => $this->plugin_file,
				'new_version'  => $remote,
				'url'          => $release['html_url'],
				'package'      => $release['zip_url'],
				'icons'        => array(),
				'banners'      => array(),
				'banners_rtl'  => array(),
				'tested'       => '6.6',
				'requires_php' => '8.0',
			);
		}

		return $transient;
	}

	/**
	 * @param false|object|array $result  Result.
	 * @param string               $action  API action.
	 * @param object               $args    Request args.
	 * @return object|false
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! is_object( $args ) || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_remote_release( false );
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WooTelegram Manager',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/shlomo222">Shlomi</a>',
			'homepage'      => 'https://github.com/shlomo222/WooTelegram',
			'download_link' => $release['zip_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => '<p>WooTelegram Manager — ניהול חנות WooCommerce דרך טלגרם.</p>',
				'changelog'   => '<pre style="white-space:pre-wrap;">' . esc_html( $release['body'] ) . '</pre>',
			),
		);
	}

	/**
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wootg_force_update_check' ),
			'wootg_force_update_check'
		);

		$label = __( 'בדוק עדכונים', 'woo-telegram-manager' );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' );

		return $links;
	}

	/**
	 * Force refresh from GitHub and re-run core update check.
	 */
	public function force_update_check(): void {
		check_admin_referer( 'wootg_force_update_check' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'woo-telegram-manager' ) );
		}

		delete_site_transient( $this->transient_key );
		delete_site_transient( 'update_plugins' );

		$this->get_remote_release( true );

		if ( function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
			wp_update_plugins();
		}

		wp_safe_redirect(
			admin_url( 'plugins.php?wootg_update_checked=1' )
		);
		exit;
	}

	/**
	 * Rename extracted GitHub folder (owner-repo-hash) to real plugin directory.
	 *
	 * @param string      $source        Path to extracted source.
	 * @param string      $remote_source Remote path.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array<string, mixed>|null $hook_extra Extra args.
	 */
	public function fix_source_name( $source, $remote_source, $upgrader, $hook_extra = null ): string {
		global $wp_filesystem;

		if ( ! is_array( $hook_extra ) || ! $this->is_our_plugin_upgrade( $hook_extra ) ) {
			return $source;
		}

		if ( ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$desired = dirname( $this->plugin_file );
		$base    = basename( $source );

		if ( $base === $desired ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source ) ) . $desired;

		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		if ( $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}

		return $source;
	}

	/**
	 * Attach Authorization to private zipball downloads.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return array<string, mixed>
	 */
	public function maybe_authorize_github_http( array $args, $url ): array {
		if ( ! is_string( $url ) ) {
			return $args;
		}

		$needle = sprintf(
			'https://api.github.com/repos/%s/%s/zipball/',
			$this->github_user,
			$this->github_repo
		);

		if ( strpos( $url, $needle ) !== 0 ) {
			return $args;
		}

		$token = $this->get_github_token();
		if ( '' === $token ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'token ' . $token;

		return $args;
	}

	/**
	 * @param array<string, mixed> $hook_extra Hook extra.
	 */
	private function is_our_plugin_upgrade( array $hook_extra ): bool {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_file ) {
			return true;
		}

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			return in_array( $this->plugin_file, $hook_extra['plugins'], true );
		}

		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private function build_github_headers(): array {
		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		);

		$token = $this->get_github_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'token ' . $token;
		}

		return $headers;
	}

	/**
	 * Decrypted PAT from options (empty if unset).
	 */
	private function get_github_token(): string {
		$settings = get_option( 'wootg_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['github_token'] ) ) {
			return '';
		}

		$raw = trim( (string) $settings['github_token'] );
		if ( '' === $raw ) {
			return '';
		}

		$plain = WooTG_Crypto::decrypt( $raw );
		if ( '' !== $plain ) {
			return $plain;
		}

		return $raw;
	}

	private function normalize_release_version( string $tag ): string {
		return ltrim( $tag, "vV \t\n\r\0\x0B" );
	}
}

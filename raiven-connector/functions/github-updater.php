<?php
/**
 * GitHub release updater for WordPress admin.
 *
 * @package RaivenConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies native WordPress updates from public GitHub releases.
 */
final class AS329_RAI_GitHub_Updater {
	private const OWNER               = 'cchatterton';
	private const REPO                = 'raiven-connector';
	private const SLUG                = 'raiven-connector';
	private const ASSET_NAME          = 'raiven-connector.zip';
	private const CHECK_QUERY_KEY     = 'as329_rai_check_updates';
	private const RELEASE_TRANSIENT   = 'as329_rai_github_latest_release';
	private const ERROR_TRANSIENT     = 'as329_rai_github_latest_release_error';
	private const BACKOFF_TRANSIENT   = 'as329_rai_github_release_backoff';
	private const MANIFEST_URL        = 'https://raw.githubusercontent.com/cchatterton/raiven-connector/main/update.json';
	private const REPOSITORY_URL      = 'https://github.com/cchatterton/raiven-connector';
	private const WORDPRESS_VERSION   = '7.0';
	private const PHP_VERSION         = '8.1';

	/** @var bool Whether this request has already bypassed the release cache. */
	private $forced_refresh_complete = false;

	/**
	 * Register updater hooks.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'add_update_data' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'add_update_data' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( $this, 'manual_check_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'manual_check_notice' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Add or remove this plugin's update response.
	 *
	 * @param mixed $transient WordPress update transient.
	 * @return mixed
	 */
	public function add_update_data( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$plugin_file        = plugin_basename( AS329_RAI_PLUGIN_FILE );
		$transient->response = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->no_update = isset( $transient->no_update ) && is_array( $transient->no_update ) ? $transient->no_update : array();
		$release            = $this->get_latest_release( $this->is_forced_check() );

		unset( $transient->response[ $plugin_file ], $transient->no_update[ $plugin_file ] );

		if ( ! is_array( $release ) || ! version_compare( $release['version'], AS329_RAI_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ $plugin_file ] = (object) array(
			'id'           => self::REPOSITORY_URL,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'new_version'  => $release['version'],
			'url'          => $release['release_url'],
			'package'      => $release['package_url'],
			'requires'     => self::WORDPRESS_VERSION,
			'requires_php' => self::PHP_VERSION,
		);

		return $transient;
	}

	/**
	 * Supply the WordPress plugin-details modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   API arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release( false );

		if ( ! is_array( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'rAIven Connector',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'AlphaSys',
			'homepage'      => self::REPOSITORY_URL,
			'download_link' => $release['package_url'],
			'requires'      => self::WORDPRESS_VERSION,
			'requires_php'  => self::PHP_VERSION,
			'sections'      => array(
				'description' => 'Native WordPress AI provider and private administrator chat console for rAIven.',
				'changelog'   => nl2br( esc_html( $release['body'] ) ),
			),
		);
	}

	/**
	 * Add GitHub and manual-check links to the active plugin row.
	 *
	 * @param string[] $links Existing metadata links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( AS329_RAI_PLUGIN_FILE ) !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$plugins_url = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		$check_url   = wp_nonce_url(
			add_query_arg( self::CHECK_QUERY_KEY, '1', $plugins_url ),
			self::CHECK_QUERY_KEY
		);

		$links[] = '<a href="' . esc_url( self::REPOSITORY_URL ) . '" rel="noopener noreferrer">GitHub</a>';
		$links[] = '<a href="' . esc_url( $check_url ) . '">Check for updates</a>';

		return $links;
	}

	/**
	 * Run a nonce-protected manual update check from the Plugins screen.
	 */
	public function handle_manual_check() {
		if ( empty( $_GET[ self::CHECK_QUERY_KEY ] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to check plugin updates.', 'raiven-connector' ) );
		}

		check_admin_referer( self::CHECK_QUERY_KEY );
		$this->clear_release_state();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$transient = get_site_transient( 'update_plugins' );
		$transient = $this->add_update_data( is_object( $transient ) ? $transient : new stdClass() );
		set_site_transient( 'update_plugins', $transient );

		$plugin_file = plugin_basename( AS329_RAI_PLUGIN_FILE );
		$result      = isset( $transient->response[ $plugin_file ] ) ? 'available' : 'current';

		if ( get_site_transient( self::ERROR_TRANSIENT ) ) {
			$result = 'failed';
		}

		$plugins_url = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'as329_rai_update_check', $result, $plugins_url ) );
		exit;
	}

	/**
	 * Show the result of an explicit update check.
	 */
	public function manual_check_notice() {
		if ( empty( $_GET['as329_rai_update_check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['as329_rai_update_check'] ) );
		$notices = array(
			'available' => array( 'warning', 'An rAIven Connector update is available below.' ),
			'current'   => array( 'success', 'rAIven Connector is current.' ),
			'failed'    => array( 'error', 'The update check could not be completed. Please try again later.' ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $result ][0] ),
			esc_html( $notices[ $result ][1] )
		);
	}

	/**
	 * Clear caches after WordPress successfully updates this plugin.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrader options.
	 */
	public function clear_cache_after_update( $upgrader, $options ) {
		if (isset($upgrader->result) && (is_wp_error($upgrader->result) || false === $upgrader->result)) { return; }

		if ( 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
        if (!empty($options['plugin'])) { $plugins[] = $options['plugin']; }

		if ( in_array( plugin_basename( AS329_RAI_PLUGIN_FILE ), $plugins, true ) ) {
			$this->clear_release_state();
		}
	}

	/**
	 * Read the latest valid release using the required fallback order.
	 *
	 * @param bool $force Whether to bypass plugin-specific caches.
	 * @return array|false
	 */
	private function get_latest_release( $force ) {
		if ( $force && ! $this->forced_refresh_complete ) {
			$this->clear_release_state();
			$this->forced_refresh_complete = true;
		} else {
			$cached = get_site_transient( self::RELEASE_TRANSIENT );

			if ( is_array( $cached ) && $this->is_valid_release( $cached ) ) {
				return $cached;
			}

			if ( get_site_transient( self::BACKOFF_TRANSIENT ) ) {
				return false;
			}
		}

		$release = $this->get_manifest_release();

		if ( false === $release ) {
			$release = $this->get_redirect_release();
		}

		if ( false === $release ) {
			$release = $this->get_api_release();
		}

		if ( false === $release ) {
			set_site_transient( self::BACKOFF_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
			return false;
		}

		delete_site_transient( self::ERROR_TRANSIENT );
		delete_site_transient( self::BACKOFF_TRANSIENT );
		$duration = version_compare( $release['version'], AS329_RAI_VERSION, '>' ) ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
		set_site_transient( self::RELEASE_TRANSIENT, $release, $duration );

		return $release;
	}

	/**
	 * Fetch the repository-controlled update manifest.
	 *
	 * @return array|false
	 */
	private function get_manifest_release() {
		$response = wp_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'rAIven-Connector/' . AS329_RAI_VERSION ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->store_error( $response );
			return false;
		}

		$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
		$version  = is_array( $manifest ) ? $this->normalise_version( $manifest['version'] ?? '' ) : '';

		if ( '' === $version ) {
			$this->store_error( $response, 'json_error' );
			return false;
		}

		return array(
			'version'     => $version,
			'body'        => sanitize_textarea_field( (string) ( $manifest['body'] ?? '' ) ),
			'release_url' => self::REPOSITORY_URL . '/releases/tag/v' . rawurlencode( $version ),
			'package_url' => self::REPOSITORY_URL . '/releases/download/v' . rawurlencode( $version ) . '/' . self::ASSET_NAME,
		);
	}

	/**
	 * Read the public latest-release redirect without consuming API quota.
	 *
	 * @return array|false
	 */
	private function get_redirect_release() {
		$response = wp_remote_get(
			self::REPOSITORY_URL . '/releases/latest',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'User-Agent' => 'rAIven-Connector/' . AS329_RAI_VERSION ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_error( $response );
			return false;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) || ! is_string( $location ) ) {
			$this->store_error( $response );
			return false;
		}

		$path = (string) wp_parse_url( $location, PHP_URL_PATH );

		if (wp_parse_url($location, PHP_URL_HOST) !== 'github.com' || ! preg_match( '#^/' . self::OWNER . '/' . self::REPO . '/releases/tag/([^/]+)$#', $path, $matches ) ) {
			$this->store_error( $response, 'redirect_error' );
			return false;
		}

		$version = $this->normalise_version( rawurldecode( $matches[1] ) );

		if ( '' === $version ) {
			$this->store_error( $response, 'version_error' );
			return false;
		}

		return array(
			'version'     => $version,
			'body'        => 'See the GitHub release for details.',
			'release_url' => self::REPOSITORY_URL . '/releases/tag/v' . rawurlencode( $version ),
			'package_url' => self::REPOSITORY_URL . '/releases/download/v' . rawurlencode( $version ) . '/' . self::ASSET_NAME,
		);
	}

	/**
	 * Use GitHub's public API only as a last resort.
	 *
	 * @return array|false
	 */
	private function get_api_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'rAIven-Connector/' . AS329_RAI_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->store_error( $response );
			return false;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$version = is_array( $data ) ? $this->normalise_version( $data['tag_name'] ?? '' ) : '';
		$package = '';

		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				$package = esc_url_raw( (string) ( $asset['browser_download_url'] ?? '' ) );
				break;
			}
		}

		if ( '' === $version || '' === $package ) {
			$this->store_error( $response, 'release_error' );
			return false;
		}

		return array(
			'version'     => $version,
			'body'        => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
			'release_url' => esc_url_raw( (string) ( $data['html_url'] ?? self::REPOSITORY_URL ) ),
			'package_url' => $package,
		);
	}

	/**
	 * Store short-lived diagnostics separately from valid release data.
	 *
	 * @param array|WP_Error $response HTTP response or error.
	 * @param string         $type     Optional diagnostic type.
	 */
	private function store_error( $response, $type = '' ) {
		if ( is_wp_error( $response ) ) {
			$diagnostic = array(
				'type'       => 'wp_error',
				'message'    => $response->get_error_message(),
				'checked_at' => time(),
			);
		} else {
			$diagnostic = array(
				'type'       => $type ?: 'http_error',
				'code'       => wp_remote_retrieve_response_code( $response ),
				'message'    => wp_remote_retrieve_response_message( $response ),
				'checked_at' => time(),
			);
		}

		set_site_transient( self::ERROR_TRANSIENT, $diagnostic, 10 * MINUTE_IN_SECONDS );
		delete_site_transient( self::RELEASE_TRANSIENT );
	}

	/**
	 * Determine whether WordPress or the plugin requested a forced check.
	 *
	 * @return bool
	 */
	private function is_forced_check() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		if ( ! empty( $_REQUEST[ self::CHECK_QUERY_KEY ] ) || isset( $_REQUEST['force-check'] ) ) {
			return true;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		return in_array( $action, array( 'update-selected', 'upgrade-plugin', 'do-plugin-upgrade' ), true );
	}

	/**
	 * Strip a leading v and validate a semantic release version.
	 *
	 * @param mixed $version Candidate version.
	 * @return string
	 */
	private function normalise_version( $version ) {
		if (!is_string($version)) { return ''; }
        $version = ltrim( trim( $version ), 'vV' );

		return preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : '';
	}

	/**
	 * Validate cached release structure.
	 *
	 * @param array $release Cached release.
	 * @return bool
	 */
	private function is_valid_release( $release ) {
		return '' !== $this->normalise_version( $release['version'] ?? '' )
			&& ! empty( $release['release_url'] )
			&& ! empty( $release['package_url'] )
			&& isset( $release['body'] );
	}

	/**
	 * Clear plugin-specific release, diagnostic, and backoff state.
	 */
	private function clear_release_state() {
		delete_site_transient( self::RELEASE_TRANSIENT );
		delete_site_transient( self::ERROR_TRANSIENT );
		delete_site_transient( self::BACKOFF_TRANSIENT );
	}
}

new AS329_RAI_GitHub_Updater();

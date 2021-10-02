<?php

namespace IdeoLogix\DigitalLicenseManagerUpdaterWP\Core;

use IdeoLogix\DigitalLicenseManagerUpdaterWP\Application;
use IdeoLogix\DigitalLicenseManagerUpdaterWP\Http\Response;

class Updater {

	/**
	 * The Application
	 * @var Application
	 */
	protected $application;

	/**
	 * Updater constructor.
	 *
	 * @param Application $application
	 */
	public function __construct( $application ) {

		$this->application = $application;

		if(!function_exists('add_filter')) {
			throw new \Exception('The library is not supported. Please make sure you initialize it within WordPress environment.');
		}

		add_filter( 'plugins_api', array( $this, 'modify_plugin_details' ), 10, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_plugins_transient' ), 10, 1 );
		add_action( 'in_plugin_update_message-' . $this->application->getEntity()->getBasename(), array( $this, 'modify_plugin_update_message' ), 10, 2 );
	}

	/**
	 * Called when WP updates the 'update_plugins' site transient. Used to inject CV plugin update info.
	 *
	 * @param $plugin_data
	 * @param $response
	 */
	public function modify_plugin_update_message( $plugin_data, $response ) {

		$purchaseUrl = $this->application->getEntity()->getPurchaseUrl();
		$settingsUrl = $this->application->getEntity()->getSettingsUrl();

		echo '<style>.cv-indent-left {padding-left: 25px;}</style>';

		if ( ! empty( $this->activation_token ) ) {
			$license = $this->application->getClient()->prepareLicense( $this->application->getEntity()->getActivationToken() );
			$expired = isset( $license['license']['is_expired'] ) ? (bool) $license['license']['is_expired'] : true;
			if ( $expired ) {
				$expires_at = isset( $license['license']['expires_at'] ) ? $license['license']['expires_at'] : '';
				$expires_at = $expires_at ? Utilities::getFormattedDate( $expires_at ) : array();
				$expired_on = isset( $expires_at['default_format'] ) ? 'Your license expired on <u>' . $expires_at['default_format'] . '</u>' : 'Your license expired';
				echo '<br/>';
				echo sprintf(
					'<strong class="cv-indent-left">Important</strong>: %s. To continue with updates please <a target="_blank" href="%s">purchase new license key</a> and activate it in the <a href="%s">settings</a> page.',
					$expired_on,
					$purchaseUrl,
					$settingsUrl
				);
			}
		} else {
			echo '<br/>';
			echo sprintf(
				'<strong class="cv-indent-left">Important</strong>: To enable updates, please activate your license key on the <a href="%s">settings</a> page. Need license key? <a target="_blank" href="%s">Purchase one now!</a>.',
				$purchaseUrl,
				$settingsUrl
			);
		}
	}

	/**
	 *  Returns the plugin data visible in the 'View details' popup
	 *
	 * @param $transient
	 *
	 * @return mixed
	 */
	public function modify_plugins_transient( $transient ) {

		// bail early if no response (error)
		if ( ! isset( $transient->response ) ) {
			return $transient;
		}

		// force-check (only once)
		$force_check = isset( $_GET['force-check'] ) && 1 === (int) $_GET['force-check'];

		// fetch updates (this filter is called multiple times during a single page load)
		$update = $this->check_update( $force_check );

		// append
		if ( is_array( $update ) && isset( $update['new_version'] ) ) {
			$transient->response[ $this->application->getEntity()->getBasename() ] = (object) $update;
		}

		// return
		return $transient;
	}

	/**
	 * Gather the plugin detials
	 *
	 * @param $result
	 * @param null $action
	 * @param null $args
	 *
	 * @return object
	 */
	public function modify_plugin_details( $result, $action = null, $args = null ) {

		// Only for 'plugin_information' action
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		// Find plugin via slug
		if ( isset( $args->slug ) ) {
			$slug = $args->slug;
		} elseif ( isset( $args['slug'] ) ) {
			$slug = $args['slug'];
		} else {
			$slug = '';
		}
		if ( $this->application->getEntity()->getSlug() !== $slug ) {
			return $result;
		}


		// query api
		$response = $this->application->getClient()->info( $this->application->getEntity()->getId(), $this->application->getEntity()->getActivationToken(), 'wp' );
		if ( $response->isError() ) {
			return $result;
		}


		$response = $this->format_plugin_details( $response );
		if ( ! $response ) {
			return $result;
		}

		return (object) $response;
	}


	/**
	 * Check for updates.
	 *
	 * @param $force
	 *
	 * @return mixed
	 */
	private function check_update( $force = false ) {

		$transient_key    = $this->application->getClient()->getUpdateCacheKey( $this->application->getEntity()->getId() );
		$transient_expiry = MINUTE_IN_SECONDS * 40;

		// Using Force?
		if ( $force ) {
			delete_transient( $transient_key );
		}

		// Check for updates
		$update = get_transient( $transient_key );
		if ( false === $update ) {
			$update   = array();
			$response = $this->application->getClient()->info( $this->application->getEntity()->getId(), $this->application->getEntity()->getActivationToken(), 'wp', true );
			if ( ! $response->isError() ) {
				$update = $this->format_plugin_update( $response );
				set_transient( $transient_key, $update, $transient_expiry );
			}
		}

		return $update;
	}

	/**
	 * Format site update
	 *
	 * @param Response $response
	 *
	 * @return array
	 */
	private function format_plugin_update( $response ) {

		if ( empty( $response ) ) {
			return array();
		}

		$update      = array();
		$data        = $response->getData();
		$new_version = isset( $data['details']['stable_tag'] ) ? $data['details']['stable_tag'] : '';
		$tested      = isset( $data['details']['tested'] ) ? $data['details']['tested'] : '';

		if ( version_compare( $new_version, $this->application->getEntity()->getVersion() ) === 1 ) {
			$update = array(
				'slug'        => $this->application->getEntity()->getSlug(),
				'plugin'      => $this->application->getEntity()->getBasename(),
				'url'         => $this->application->getEntity()->getPurchaseUrl(),
				'new_version' => $new_version,
				'tested'      => $tested,
			);
			if ( isset( $data['download_url'] ) && ! empty( $data['download_url'] ) ) {
				global $wp_version;
				$params = array(
					'meta' => array(
						'wp_version'  => $wp_version,
						'php_version' => PHP_VERSION,
						'web_server'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : null,
					)
				);

				$update['package'] = add_query_arg( $params, $data['download_url'] );
			}
		}

		return $update;
	}

	/**
	 * Format the plugin details
	 *
	 * @param Response $response
	 *
	 * @return null
	 */
	private function format_plugin_details( $response ) {

		if ( empty( $response ) ) {
			return null;
		}

		$details = $response->getData( 'details' );
		if ( empty( $details ) || ! is_array( $details ) ) {
			return null;
		}

		return $details;
	}
}

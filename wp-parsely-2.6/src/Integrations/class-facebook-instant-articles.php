<?php
/**
 * Facebook Instant Articles integration class
 *
 * @package Parsely\Integrations
 * @since 2.6.0
 */

namespace Parsely\Integrations;

/**
 * Integrates Parse.ly tracking with the Facebook Instant Articles plugin.
 *
 * @since 2.6.0 Moved from Parsely class to this file.
 */
final class Facebook_Instant_Articles implements Integration {
	const REGISTRY_IDENTIFIER   = 'parsely-analytics-for-wordpress';
	const REGISTRY_DISPLAY_NAME = 'Parse.ly Analytics';

	/**
	 * Apply the hooks that integrate the plugin or theme with the Parse.ly plugin.
	 *
	 * @since 2.6.0
	 */
	public function integrate() {
		if ( defined( 'IA_PLUGIN_VERSION' ) ) {
			add_action( 'instant_articles_compat_registry_analytics', array( $this, 'insert_parsely_tracking' ) );
		}
	}

	/**
	 * Add Parse.ly tracking to Facebook instant articles.
	 *
	 * @since 2.6.0
	 *
	 * @param array $registry The registry info for fbia.
	 * @return void
	 */
	public function insert_parsely_tracking( &$registry ) {
		$parsely = new \Parsely();
		if ( $parsely->api_key_is_missing() ) {
			return;
		}

		$registry[ self::REGISTRY_IDENTIFIER ] = array(
			'name'    => self::REGISTRY_DISPLAY_NAME,
			'payload' => $this->get_embed_code( $parsely->get_api_key() ),
		);
	}

	/**
	 * Get the payload / embed code.
	 *
	 * @since 2.6.0
	 *
	 * @param string $api_key API key.
	 * @return string Embedded code.
	 */
	public function get_embed_code( $api_key ) {
		return '<script>
			PARSELY = {
				autotrack: false,
				onload: function() {
					PARSELY.beacon.trackPageView({
						urlref: \'http://facebook.com/instantarticles\'
					});
					return true;
				}
			}
		</script>
		<script data-cfasync="false" id="parsely-cfg" data-parsely-site="' . esc_attr( $api_key ) . '" src="//cdn.parsely.com/keys/' . esc_attr( $api_key ) . '/p.js"></script>';
	}
}

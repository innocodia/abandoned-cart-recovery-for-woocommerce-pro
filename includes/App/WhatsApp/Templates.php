<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approved WhatsApp templates from Meta, cached for an hour, shaped for the
 * template picker: how many body slots ({{1}}, {{2}}…) to map, and whether the
 * template has a URL button with a variable ({{recovery_path}}).
 *
 * Templates themselves are created and approved in Meta's WhatsApp Manager.
 */
class Templates {

	const CACHE = 'acrfwp_wa_templates';

	/**
	 * @param bool $refresh Skip the cache.
	 * @return array|\WP_Error List of templates.
	 */
	public static function all( bool $refresh = false ) {
		$waba_id = (string) Settings::get( 'waba_id' );
		if ( '' === $waba_id || '' === Settings::get( 'access_token' ) ) {
			return new \WP_Error(
				'acrfwp_whatsapp_not_configured',
				__( 'Save the WhatsApp Business Account ID and access token first.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array( 'status' => 400 )
			);
		}

		$cached = $refresh ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = ( new Client() )->templates( $waba_id );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'acrfwp_whatsapp_error', $result['error'], array( 'status' => 502 ) );
		}

		$list = array();
		foreach ( (array) $result['data'] as $template ) {
			if ( is_array( $template ) && ! empty( $template['name'] ) ) {
				$list[] = self::shape( $template );
			}
		}

		set_transient( self::CACHE, $list, HOUR_IN_SECONDS );

		return $list;
	}

	/**
	 * Position of a template's link button among its buttons (0 when unknown),
	 * from the cached list.
	 */
	public static function button_index( string $name, string $language ): int {
		$all = self::all();
		if ( is_wp_error( $all ) ) {
			return 0;
		}

		foreach ( $all as $template ) {
			if ( $name === $template['name'] && $language === $template['language'] && $template['button'] ) {
				return (int) $template['button']['index'];
			}
		}

		return 0;
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * One Graph template → picker item.
	 *
	 * @param array $template Graph template.
	 */
	public static function shape( array $template ): array {
		$body        = '';
		$header      = null;
		$button      = null;
		$unsupported = '';

		foreach ( (array) ( $template['components'] ?? array() ) as $component ) {
			$type = strtoupper( (string) ( $component['type'] ?? '' ) );

			if ( 'BODY' === $type ) {
				$body = (string) ( $component['text'] ?? '' );
			} elseif ( 'HEADER' === $type ) {
				$format = strtoupper( (string) ( $component['format'] ?? 'TEXT' ) );
				$header = 'TEXT' === $format ? (string) ( $component['text'] ?? '' ) : '';
				if ( 'TEXT' !== $format ) {
					$unsupported = __( 'Templates with an image, video or document header aren\'t supported yet.', 'abandoned-cart-recovery-for-woocommerce-pro' );
				} elseif ( self::slots( (string) $header ) ) {
					$unsupported = __( 'Templates with a variable in the header aren\'t supported yet.', 'abandoned-cart-recovery-for-woocommerce-pro' );
				}
			} elseif ( 'BUTTONS' === $type ) {
				foreach ( (array) ( $component['buttons'] ?? array() ) as $index => $item ) {
					if ( 'URL' !== strtoupper( (string) ( $item['type'] ?? '' ) ) ) {
						continue;
					}
					$url = (string) ( $item['url'] ?? '' );
					if ( false === strpos( $url, '{{1}}' ) ) {
						continue;
					}
					if ( null !== $button ) {
						$unsupported = __( 'Only one link button with a variable is supported.', 'abandoned-cart-recovery-for-woocommerce-pro' );
						continue;
					}
					$base   = str_replace( '{{1}}', '', $url );
					$button = array(
						'index'             => (int) $index,
						'text'              => (string) ( $item['text'] ?? '' ),
						'url'               => $url,
						'base'              => $base,
						'base_matches_site' => trailingslashit( $base ) === trailingslashit( home_url( '/' ) ),
					);
				}
			}
		}

		return array(
			'name'               => (string) $template['name'],
			'language'           => (string) ( $template['language'] ?? '' ),
			'category'           => strtolower( (string) ( $template['category'] ?? '' ) ),
			'header'             => $header,
			'body'               => $body,
			'slots'              => self::slots( $body ),
			'button'             => $button,
			'supported'          => '' === $unsupported,
			'unsupported_reason' => $unsupported,
		);
	}

	/** Number of {{n}} slots in a text (the highest n). */
	public static function slots( string $text ): int {
		preg_match_all( '/\{\{\s*(\d+)\s*\}\}/', $text, $matches );

		return $matches[1] ? max( array_map( 'intval', $matches[1] ) ) : 0;
	}
}

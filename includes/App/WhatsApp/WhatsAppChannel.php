<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFW\App\Messaging\Renderer;

/**
 * Sends WhatsApp follow-up steps through Meta's Cloud API.
 *
 * - `acrfw_followup_channels`: marks WhatsApp available while Pro runs.
 * - `acrfw_followup_send`: the free plugin's FollowupSender has already done
 *   every shared check (template, unsubscribe, GDPR, blacklist, conditions,
 *   coupon) and built the merge-tag values and signed links. This class only
 *   checks what is WhatsApp's own, then sends the approved template:
 *   1. connection saved (else skipped: not_configured);
 *   2. the cart's phone turns into a valid E.164 number (else invalid_phone);
 *   3. real sends only: the cart ticked the WhatsApp opt-in when that
 *      checkbox is on (no_whatsapp_optin), and the number hasn't replied
 *      STOP (whatsapp_opted_out);
 *   4. a template name is set (whatsapp_template_missing);
 *   5. body slots {{1}}… and the optional link-button suffix are filled from
 *      the template's `variables` with the free Renderer.
 *
 * Meta's message ID goes back to the free plugin, which stores it so the
 * status webhook (Webhook) can mark the message delivered, read or failed.
 */
class WhatsAppChannel {

	public static function init(): void {
		add_filter( 'acrfw_followup_channels', array( self::class, 'channels' ) );
		add_filter( 'acrfw_followup_send', array( self::class, 'send' ), 10, 3 );
	}

	/**
	 * @param array $channels Channel key => { label, available, pro }.
	 */
	public static function channels( $channels ): array {
		$channels = (array) $channels;
		if ( isset( $channels['whatsapp'] ) ) {
			$channels['whatsapp']['available'] = true;
		}

		return $channels;
	}

	/**
	 * acrfw_followup_send callback.
	 *
	 * @param bool|array $sent    Result so far.
	 * @param string     $channel Channel of the step.
	 * @param array      $context cart, step, template, values, to, message_id, is_test.
	 * @return bool|array
	 */
	public static function send( $sent, $channel, $context ) {
		if ( 'whatsapp' !== $channel ) {
			return $sent;
		}

		$cart     = (object) ( $context['cart'] ?? array() );
		$template = (array) ( $context['template'] ?? array() );
		$values   = (array) ( $context['values'] ?? array() );
		$is_test  = ! empty( $context['is_test'] );

		if ( ! Settings::configured() ) {
			return self::skip( 'not_configured' );
		}

		// A test goes to the number the admin typed: read it in the store's country.
		$details = json_decode( (string) ( $cart->user_details ?? '' ), true );
		$country = $is_test ? '' : (string) ( $details['billing']['country'] ?? '' );
		$to      = PhoneNumber::to_e164( (string) ( $context['to'] ?? '' ), $country );
		if ( '' === $to ) {
			return self::skip( 'invalid_phone' );
		}

		if ( ! $is_test ) {
			if ( acrfw_setting( 'gdpr.enable_whatsapp_optin', false ) && '1' !== (string) ( $cart->whatsapp_optin ?? '' ) ) {
				return self::skip( 'no_whatsapp_optin' );
			}
			if ( OptOuts::has( $to ) ) {
				return self::skip( 'whatsapp_opted_out' );
			}
		}

		$name = (string) ( $template['provider_template'] ?? '' );
		if ( '' === $name ) {
			return self::skip( 'whatsapp_template_missing' );
		}
		$language = (string) ( $template['provider_language'] ?? '' );
		$language = '' !== $language ? $language : 'en_US';

		$result = ( new Client() )->send_template(
			(string) Settings::get( 'phone_number_id' ),
			$to,
			$name,
			$language,
			self::components( $template['variables'] ?? null, $values, Templates::button_index( $name, $language ) )
		);

		if ( ! $result['ok'] ) {
			return array(
				'sent'   => false,
				'reason' => $result['error'],
			);
		}

		return array(
			'sent'                => true,
			'provider_message_id' => $result['id'],
		);
	}

	/**
	 * Template parameters from the stored variables map.
	 *
	 * @param mixed $variables { "1": "{{first_name}}", …, "button": "{{recovery_path}}" }.
	 * @param array $values    Merge-tag values for this send.
	 * @param int   $index     Position of the link button among the template's buttons.
	 */
	public static function components( $variables, array $values, int $index = 0 ): array {
		$variables = is_string( $variables ) ? json_decode( $variables, true ) : $variables;
		$variables = is_array( $variables ) ? $variables : array();

		$body   = array();
		$button = null;
		foreach ( $variables as $slot => $text ) {
			$value = self::parameter( Renderer::text( (string) $text, $values ) );
			if ( 'button' === $slot ) {
				$button = $value;
			} elseif ( ctype_digit( (string) $slot ) ) {
				$body[ (int) $slot ] = $value;
			}
		}
		ksort( $body );

		$components = array();
		if ( $body ) {
			$components[] = array(
				'type'       => 'body',
				'parameters' => array_map(
					static function ( $text ) {
						return array(
							'type' => 'text',
							'text' => $text,
						);
					},
					array_values( $body )
				),
			);
		}

		if ( null !== $button ) {
			$components[] = array(
				'type'       => 'button',
				'sub_type'   => 'url',
				'index'      => (string) $index,
				'parameters' => array(
					array(
						'type' => 'text',
						'text' => $button,
					),
				),
			);
		}

		return $components;
	}

	/**
	 * Meta rejects empty parameters, new lines, tabs and more than four spaces
	 * in a row.
	 *
	 * @param string $text Rendered value.
	 */
	private static function parameter( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return '' === $text ? '-' : $text;
	}

	private static function skip( string $reason ): array {
		return array(
			'sent'    => false,
			'skipped' => true,
			'reason'  => $reason,
		);
	}
}

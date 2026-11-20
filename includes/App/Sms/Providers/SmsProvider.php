<?php
namespace ACRFWP\App\Sms\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One SMS gateway. Twilio is the first; another provider is one more class
 * (roadmap F-29), with no change to SmsChannel or the webhooks.
 */
interface SmsProvider {

	public function id(): string;

	/**
	 * @param string $to         E.164 recipient.
	 * @param string $body       Message text.
	 * @param string $status_url Where the provider posts delivery updates.
	 * @return array{ok: bool, id: string, error: string, code: int, opted_out: bool, invalid_phone: bool}
	 */
	public function send( string $to, string $body, string $status_url ): array;

	/** @return array{ok: bool, account_name: string, account_status: string, sender_ok: bool|null, error: string} */
	public function verify(): array;

	/** Whether a webhook request really comes from the provider. */
	public function valid_signature( \WP_REST_Request $request, string $url ): bool;

	/** @return array{id: string, status: string, error: string}|null status: delivered|failed|other */
	public function parse_status( \WP_REST_Request $request ): ?array;

	/** @return array{from: string, body: string, keyword: string}|null keyword: stop|start|'' */
	public function parse_inbound( \WP_REST_Request $request ): ?array;
}

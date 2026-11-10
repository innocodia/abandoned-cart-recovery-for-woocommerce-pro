<?php
namespace ACRFWP\App\Conditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The values a cart has for each condition field (the free plugin's
 * ACRFW\App\Followups\ConditionFields).
 *
 * Built once per send. Anything that needs a lookup — product terms, the
 * customer's order history — is computed only when a condition asks for it,
 * then reused for the other conditions of the same send.
 */
class ConditionContext {

	/** @var object */
	private $cart;

	/** @var array<string, mixed> */
	private $cache = array();

	public function __construct( object $cart ) {
		$this->cart = $cart;
	}

	/**
	 * @param string $field Field key.
	 * @return mixed float for number fields, Y-m-d string for dates ('' when none),
	 *               string for text/single-select, list of strings for list fields.
	 */
	public function value( string $field ) {
		if ( ! array_key_exists( $field, $this->cache ) ) {
			$this->cache[ $field ] = $this->compute( $field );
		}

		return $this->cache[ $field ];
	}

	private function compute( string $field ) {
		$cart = $this->cart;

		switch ( $field ) {
			// Cart.
			case 'cart_subtotal':
				return (float) array_sum( array_map( 'floatval', array_column( $this->items(), 'line_subtotal' ) ) );
			case 'cart_total':
				return (float) ( $cart->cart_total ?? 0 );
			case 'cart_items_count':
				// Distinct products/variations, like Disco's "Items Count".
				return (float) count( $this->line_product_ids() );
			case 'cart_items_quantity':
				return (float) array_sum( array_map( 'intval', array_column( $this->items(), 'quantity' ) ) );
			case 'cart_total_weight':
				return $this->total_weight();
			case 'products_in_cart':
				return $this->product_ids();
			case 'categories_in_cart':
				return $this->term_ids( 'product_cat' );
			case 'tags_in_cart':
				return $this->term_ids( 'product_tag' );
			case 'checkout_type':
				return (string) ( $cart->checkout_type ?? '' );
			case 'cart_currency':
				return (string) ( $cart->currency ?? '' );

			// Customer.
			case 'customer_name':
				return ! empty( $cart->user_id ) ? array( (string) (int) $cart->user_id ) : array();
			case 'customer_is_logged_in':
				return empty( $cart->user_id ) ? 'no' : 'yes';
			case 'customer_user_role':
				return (string) ( $cart->user_role ?? '' );
			case 'customer_email':
				return (string) ( $cart->email ?? '' );
			case 'customer_has_phone':
				return '' !== trim( (string) ( $cart->phone ?? '' ) ) ? 'yes' : 'no';
			case 'customer_country':
				return strtoupper( $this->address( 'billing', 'country' ) );
			case 'customer_state':
				return $this->address( 'billing', 'state' );
			case 'customer_city':
				return $this->address( 'billing', 'city' );
			case 'customer_zip':
				return $this->address( 'billing', 'postcode' );
			case 'customer_shipping_country':
				return strtoupper( $this->address( 'shipping', 'country' ) );

			// Customer Purchase History.
			case 'customer_history_is_first_order':
				return 0 === $this->order_history()['count'] ? 'yes' : 'no';
			case 'customer_history_last_order_date':
				return $this->order_history()['last_date'];
			case 'customer_history_last_order_amount':
				return (float) $this->order_history()['last_amount'];
			case 'customer_history_total_order_made':
				return (float) $this->order_history()['count'];
			case 'customer_history_total_amount_sold':
				return (float) $this->order_history()['total'];
			case 'customer_history_total_quantity_sold':
				return (float) $this->order_history()['quantity'];
		}

		return '';
	}

	private function items(): array {
		if ( ! isset( $this->cache['__items'] ) ) {
			$items                   = json_decode( (string) ( $this->cart->cart_contents ?? '' ), true );
			$this->cache['__items'] = is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
		}

		return $this->cache['__items'];
	}

	/** Product and variation IDs in the cart, as strings (both, for matching). */
	private function product_ids(): array {
		$ids = array();
		foreach ( $this->items() as $item ) {
			$ids[] = (string) (int) ( $item['product_id'] ?? 0 );
			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = (string) (int) $item['variation_id'];
			}
		}

		return array_values( array_unique( array_diff( $ids, array( '0' ) ) ) );
	}

	/** One ID per cart line: the variation when there is one, else the product. */
	private function line_product_ids(): array {
		$ids = array();
		foreach ( $this->items() as $item ) {
			$id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) ( $item['product_id'] ?? 0 );
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}

		return array_keys( $ids );
	}

	/** Sum of product weight × quantity, using the products' current weights. */
	private function total_weight(): float {
		$weight = 0.0;
		foreach ( $this->items() as $item ) {
			$id      = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) ( $item['product_id'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : null;
			if ( $product && $product->has_weight() ) {
				$weight += (float) $product->get_weight() * max( 1, (int) ( $item['quantity'] ?? 1 ) );
			}
		}

		return $weight;
	}

	/** Term IDs of the cart's parent products in a taxonomy, as strings. */
	private function term_ids( string $taxonomy ): array {
		$ids = array();
		foreach ( $this->items() as $item ) {
			$terms = wp_get_post_terms( (int) ( $item['product_id'] ?? 0 ), $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				$ids = array_merge( $ids, array_map( 'strval', $terms ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function address( string $type, string $key ): string {
		if ( ! isset( $this->cache['__details'] ) ) {
			$details                   = json_decode( (string) ( $this->cart->user_details ?? '' ), true );
			$this->cache['__details'] = is_array( $details ) ? $details : array();
		}

		return (string) ( $this->cache['__details'][ $type ][ $key ] ?? '' );
	}

	/**
	 * Paid orders already placed with the cart's email (guest or registered),
	 * newest first. Disco keys this on the logged-in customer; a background send
	 * has no logged-in user, and the email also covers guest checkouts.
	 *
	 * @return array{count: int, total: float, quantity: int, last_date: string, last_amount: float}
	 */
	private function order_history(): array {
		if ( isset( $this->cache['__orders'] ) ) {
			return $this->cache['__orders'];
		}

		$history = array(
			'count'       => 0,
			'total'       => 0.0,
			'quantity'    => 0,
			'last_date'   => '',
			'last_amount' => 0.0,
		);
		$email   = (string) ( $this->cart->email ?? '' );

		if ( is_email( $email ) && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $email,
					'status'        => wc_get_is_paid_statuses(),
					'limit'         => -1,
				)
			);

			$newest = null;
			$key    = array( -1, -1 );

			foreach ( $orders as $order ) {
				++$history['count'];
				$history['total'] += (float) $order->get_total();

				foreach ( $order->get_items() as $line ) {
					$history['quantity'] += (int) $line->get_quantity();
				}

				// Newest by creation time; the higher ID breaks same-second ties.
				$created   = $order->get_date_created();
				$order_key = array( $created ? $created->getTimestamp() : 0, $order->get_id() );
				if ( $order_key > $key ) {
					$key    = $order_key;
					$newest = $order;
				}
			}

			if ( $newest ) {
				$created                = $newest->get_date_created();
				$history['last_date']   = $created ? $created->date( 'Y-m-d' ) : '';
				$history['last_amount'] = (float) $newest->get_total();
			}
		}

		$this->cache['__orders'] = $history;

		return $history;
	}
}

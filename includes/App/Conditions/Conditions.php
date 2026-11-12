<?php
namespace ACRFWP\App\Conditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use ACRFW\App\Followups\ConditionFields;

/**
 * Audience rules for a follow-up step (acrfw_sequences.conditions) — Pro.
 *
 * The free plugin stores conditions and keeps the field list
 * (ACRFW\App\Followups\ConditionFields) and the dropdown / search APIs; it
 * asks two filters, answered here:
 * - acrfw_validate_conditions: validate() on save.
 * - acrfw_conditions_match:    matches() before each real send.
 *
 * Same shape and evaluation as the custom-product-badge-for-woocommerce
 * condition editor:
 *
 *   [
 *     {
 *       "id": "…",                      // optional, kept for the editor
 *       "base_operator": "and"|"or",    // joins this group to the previous one
 *       "base_filters": [
 *         {
 *           "id": "…",                  // optional
 *           "compare_with": "cart_total", // field key (ConditionFields)
 *           "condition": "greater_equal", // condition key
 *           "compare": "50",              // value; [min, max] for between; [from, to] (Y-m-d) for date_between; list for in_list
 *           "operator": "and"|"or"        // joins this filter to the previous one
 *         }
 *       ]
 *     }
 *   ]
 *
 * Filters are combined left to right with each filter's operator, then groups
 * left to right with each group's base_operator (the first one's operator is
 * ignored). NULL or an empty list = the step goes to every abandoned cart.
 */
class Conditions {

	const OPERATORS = array( 'and', 'or' );

	public static function init(): void {
		add_filter( 'acrfw_validate_conditions', array( self::class, 'on_validate' ), 10, 2 );
		add_filter( 'acrfw_conditions_match', array( self::class, 'on_match' ), 10, 3 );
	}

	/**
	 * acrfw_validate_conditions callback.
	 *
	 * @param array|\WP_Error $result Free's default ("needs Pro").
	 * @param mixed           $raw    Conditions as sent.
	 * @return array|\WP_Error Normalised groups (array() for none), or the validation error.
	 */
	public static function on_validate( $result, $raw ) {
		$valid = self::validate( $raw );

		return null === $valid ? array() : $valid;
	}

	/**
	 * acrfw_conditions_match callback.
	 *
	 * @param bool|null $match      Free's default (null: nobody evaluated).
	 * @param object    $cart       Cart row.
	 * @param array     $conditions Stored condition groups.
	 */
	public static function on_match( $match, $cart, $conditions ): bool {
		return self::matches( (object) $cart, is_array( $conditions ) && $conditions ? $conditions : null );
	}

	/**
	 * Normalise and validate a conditions payload.
	 *
	 * @param mixed $raw Array, JSON string or null.
	 * @return array|null|WP_Error Normalised groups, null for "none", or an error.
	 */
	public static function validate( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? null : json_decode( $raw, true );
		}

		if ( null === $raw || array() === $raw ) {
			return null;
		}

		if ( ! is_array( $raw ) || array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			return self::error( __( 'Conditions must be a list of condition groups.', 'abandoned-cart-recovery-for-woocommerce-pro' ) );
		}

		$fields = ConditionFields::definitions();
		$groups = array();

		foreach ( $raw as $g => $group ) {
			if ( ! is_array( $group ) || ! isset( $group['base_filters'] ) || ! is_array( $group['base_filters'] ) ) {
				/* translators: %d: group number */
				return self::error( sprintf( __( 'Group %d: base_filters must be a list.', 'abandoned-cart-recovery-for-woocommerce-pro' ), $g + 1 ) );
			}

			$base_operator = strtolower( (string) ( $group['base_operator'] ?? 'and' ) );
			if ( ! in_array( $base_operator, self::OPERATORS, true ) ) {
				/* translators: %d: group number */
				return self::error( sprintf( __( 'Group %d: base_operator must be "and" or "or".', 'abandoned-cart-recovery-for-woocommerce-pro' ), $g + 1 ) );
			}

			$filters = array();
			foreach ( $group['base_filters'] as $f => $filter ) {
				$clean = self::validate_filter( is_array( $filter ) ? $filter : array(), $fields );

				if ( is_string( $clean ) ) {
					/* translators: 1: group number, 2: condition number, 3: error message */
					return self::error( sprintf( __( 'Group %1$d, condition %2$d: %3$s', 'abandoned-cart-recovery-for-woocommerce-pro' ), $g + 1, $f + 1, $clean ) );
				}

				$filters[] = $clean;
			}

			if ( $filters ) {
				$groups[] = array_merge(
					self::id( $group ),
					array(
						'base_operator' => $base_operator,
						'base_filters'  => $filters,
					)
				);
			}
		}

		return $groups ? $groups : null;
	}

	/**
	 * Whether a cart satisfies a step's conditions.
	 *
	 * @param object     $cart   acrfw_abandoned_carts row.
	 * @param array|null $groups Normalised conditions (validate() output).
	 */
	public static function matches( object $cart, ?array $groups ): bool {
		if ( empty( $groups ) ) {
			return true;
		}

		$result  = true;
		$first   = true;
		$context = new ConditionContext( $cart );

		foreach ( $groups as $group ) {
			$passed = self::check_group( (array) ( $group['base_filters'] ?? array() ), $context );

			if ( $first ) {
				$result = $passed;
				$first  = false;
			} elseif ( 'or' === ( $group['base_operator'] ?? 'and' ) ) {
				$result = $result || $passed;
			} else {
				$result = $result && $passed;
			}
		}

		return $result;
	}

	// -------------------------------------------------------------------------

	private static function check_group( array $filters, ConditionContext $context ): bool {
		$result = true;
		$first  = true;
		$fields = ConditionFields::definitions();

		foreach ( $filters as $filter ) {
			$key    = (string) $filter['compare_with'];
			$actual = $context->value( $key );
			$passed = 'date' === ( $fields[ $key ]['type'] ?? '' )
				? self::check_date( $filter, (string) $actual )
				: self::check( $filter, $actual );

			if ( $first ) {
				$result = $passed;
				$first  = false;
			} elseif ( 'or' === ( $filter['operator'] ?? 'and' ) ) {
				$result = $result || $passed;
			} else {
				$result = $result && $passed;
			}
		}

		return $result;
	}

	/**
	 * @param array $filter Normalised filter.
	 * @param mixed $actual The cart's value: float, string, or list of strings.
	 */
	private static function check( array $filter, $actual ): bool {
		$compare = $filter['compare'];

		switch ( $filter['condition'] ) {
			case 'equal':
				return is_float( $actual ) ? abs( $actual - (float) $compare ) < 0.0001 : 0 === strcasecmp( (string) $actual, (string) $compare );
			case 'not_equal':
				return is_float( $actual ) ? abs( $actual - (float) $compare ) >= 0.0001 : 0 !== strcasecmp( (string) $actual, (string) $compare );
			case 'greater':
				return (float) $actual > (float) $compare;
			case 'greater_equal':
				return (float) $actual >= (float) $compare;
			case 'lesser':
				return (float) $actual < (float) $compare;
			case 'lesser_equal':
				return (float) $actual <= (float) $compare;
			case 'between':
				return (float) $actual >= (float) $compare[0] && (float) $actual <= (float) $compare[1];
			case 'contain':
				return '' !== (string) $compare && false !== stripos( (string) $actual, (string) $compare );
			case 'not_contain':
				return false === stripos( (string) $actual, (string) $compare );
			case 'start_with':
				return 0 === stripos( (string) $actual, (string) $compare );
			case 'end_with':
				return '' !== (string) $compare && 0 === substr_compare( (string) $actual, (string) $compare, -strlen( (string) $compare ), null, true );
			case 'in_list':
				// List fields (products, categories, tags): the cart has at least one of them.
				return (bool) array_intersect( (array) $actual, $compare );
			case 'not_in_list':
				return ! array_intersect( (array) $actual, $compare );
		}

		return false;
	}

	/**
	 * Date conditions on Y-m-d strings. A customer with no orders has no date:
	 * only "not equal" passes then.
	 */
	private static function check_date( array $filter, string $actual ): bool {
		$compare = $filter['compare'];

		if ( '' === $actual ) {
			return 'not_equal' === $filter['condition'];
		}

		switch ( $filter['condition'] ) {
			case 'equal':
				return $actual === $compare;
			case 'not_equal':
				return $actual !== $compare;
			case 'greater':
				return $actual > $compare;
			case 'greater_equal':
				return $actual >= $compare;
			case 'lesser':
				return $actual < $compare;
			case 'lesser_equal':
				return $actual <= $compare;
			case 'date_between':
				return $actual >= $compare[0] && $actual <= $compare[1];
		}

		return false;
	}

	/** @return array|string Normalised filter, or an error message. */
	private static function validate_filter( array $filter, array $fields ) {
		$key = (string) ( $filter['compare_with'] ?? '' );

		if ( ! isset( $fields[ $key ] ) ) {
			/* translators: %s: field key */
			return sprintf( __( 'unknown field "%s".', 'abandoned-cart-recovery-for-woocommerce-pro' ), $key );
		}

		$type      = $fields[ $key ]['type'];
		$condition = (string) ( $filter['condition'] ?? '' );

		if ( ! in_array( $condition, ConditionFields::BY_TYPE[ $type ], true ) ) {
			/* translators: 1: condition key, 2: field title */
			return sprintf( __( '"%1$s" can\'t be used with %2$s.', 'abandoned-cart-recovery-for-woocommerce-pro' ), $condition, $fields[ $key ]['title'] );
		}

		$operator = strtolower( (string) ( $filter['operator'] ?? 'and' ) );
		if ( ! in_array( $operator, self::OPERATORS, true ) ) {
			return __( 'operator must be "and" or "or".', 'abandoned-cart-recovery-for-woocommerce-pro' );
		}

		$compare = self::normalise_compare( $fields[ $key ], $condition, $filter['compare'] ?? null );
		if ( null === $compare ) {
			/* translators: %s: field title */
			return sprintf( __( 'enter a valid value for %s.', 'abandoned-cart-recovery-for-woocommerce-pro' ), $fields[ $key ]['title'] );
		}

		return array_merge(
			self::id( $filter ),
			array(
				'compare_with' => $key,
				'condition'    => $condition,
				'compare'      => $compare,
				'operator'     => $operator,
			)
		);
	}

	/** @return mixed Normalised value, or null when it doesn't fit the field. */
	private static function normalise_compare( array $field, string $condition, $compare ) {
		if ( 'number' === $field['type'] ) {
			if ( 'between' === $condition ) {
				if ( ! is_array( $compare ) || 2 !== count( $compare ) || ! is_numeric( $compare[0] ?? null ) || ! is_numeric( $compare[1] ?? null ) ) {
					return null;
				}
				$min = (float) $compare[0];
				$max = (float) $compare[1];
				return $min <= $max ? array( $min, $max ) : null;
			}
			return is_numeric( $compare ) ? (float) $compare : null;
		}

		if ( 'date' === $field['type'] ) {
			if ( 'date_between' === $condition ) {
				if ( ! is_array( $compare ) || 2 !== count( $compare ) ) {
					return null;
				}
				$from = self::normalise_date( $compare[0] ?? null );
				$to   = self::normalise_date( $compare[1] ?? null );
				return null !== $from && null !== $to && $from <= $to ? array( $from, $to ) : null;
			}
			return self::normalise_date( $compare );
		}

		if ( 'string' === $field['type'] ) {
			return is_scalar( $compare ) && '' !== trim( (string) $compare ) ? sanitize_text_field( (string) $compare ) : null;
		}

		$options = is_array( $field['input_type'] ) ? $field['input_type'] : array();
		$manual  = 'manual' === ( $options['option_type'] ?? '' );
		$allowed = $manual ? array_map( 'strval', array_keys( (array) $options['options'] ) ) : array();

		if ( 'select_single' === $field['type'] ) {
			$value = is_scalar( $compare ) ? (string) $compare : '';
			return in_array( $value, $allowed, true ) ? $value : null;
		}

		// select_multiple: a list of option keys (manual) or numeric IDs (api).
		if ( ! is_array( $compare ) || ! $compare ) {
			return null;
		}

		$values = array();
		foreach ( $compare as $item ) {
			// The editor may send { id, name } objects for api options.
			$value = is_array( $item ) ? (string) ( $item['id'] ?? ( $item['value'] ?? '' ) ) : (string) $item;

			if ( $manual ? ! in_array( $value, $allowed, true ) : ! ctype_digit( $value ) ) {
				return null;
			}
			$values[] = $value;
		}

		return array_values( array_unique( $values ) );
	}

	/** A date from the editor (YYYY-MM-DD, or a datetime-local value) as Y-m-d. */
	private static function normalise_date( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return null;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
	}

	/** Keep the editor's own row id so groups/filters round-trip. */
	private static function id( array $item ): array {
		$id = isset( $item['id'] ) && is_scalar( $item['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $item['id'] ) : '';

		return '' !== $id ? array( 'id' => substr( $id, 0, 64 ) ) : array();
	}

	private static function error( string $message ): WP_Error {
		return new WP_Error( 'acrfw_invalid_conditions', $message, array( 'status' => 400 ) );
	}
}

<?php
/**
 * Cart / checkout context adapter.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Answers three questions about the current checkout: which products are in
 * play, whether this is a renewal, and which email address to use.
 *
 * FluentCart's checkout hooks hand over a payload whose exact shape is not part
 * of a documented contract, so every read here is defensive: the payload is
 * scanned for the keys we need, then the known FluentCart helper entry points
 * are tried, and finally a filter lets a site override the answer outright.
 */
class Cart_Context {

	/**
	 * Maximum depth for payload scans.
	 */
	const MAX_DEPTH = 6;

	/**
	 * The payload handed to us by FluentCart, if any.
	 *
	 * @var mixed
	 */
	private $payload;

	/**
	 * Product/variation pairs found in the cart.
	 *
	 * @var array[]|null
	 */
	private $pairs = null;

	/**
	 * How the pairs were resolved, for diagnostics.
	 *
	 * @var string
	 */
	private $strategy = '';

	/**
	 * Constructor.
	 *
	 * @param mixed $payload Hook payload, if the hook provided one.
	 */
	public function __construct( $payload = null ) {
		$this->payload = $payload;
	}

	/**
	 * The raw payload.
	 *
	 * @return mixed
	 */
	public function payload() {
		return $this->payload;
	}

	/**
	 * Product/variation pairs in the cart.
	 *
	 * @return array[] Each entry: array{product_id:int, variation_id:int}
	 */
	public function pairs() {
		if ( null !== $this->pairs ) {
			return $this->pairs;
		}

		$pairs          = $this->pairs_from_payload( $this->payload );
		$this->strategy = $pairs ? 'hook payload' : '';

		if ( empty( $pairs ) ) {
			foreach ( $this->cart_sources() as $label => $source ) {
				try {
					$data  = call_user_func( $source );
					$found = $this->pairs_from_payload( $data );

					if ( ! empty( $found ) ) {
						$pairs          = $found;
						$this->strategy = $label;
						break;
					}
				} catch ( \Throwable $e ) {
					Plugin::log( 'Cart source failed: ' . $label, $e->getMessage() );
				}
			}
		}

		/**
		 * Filter the product/variation pairs detected for the current checkout.
		 *
		 * Use this when FluentCart's payload shape changes, or to hard-code the
		 * cart contents while testing.
		 *
		 * @param array[]      $pairs   Each entry: array{product_id:int, variation_id:int}.
		 * @param Cart_Context $context This context object.
		 */
		$pairs = apply_filters( 'blt_fluent/cart_pairs', $pairs, $this );

		if ( '' === $this->strategy ) {
			$this->strategy = $pairs ? 'filter' : 'undetected';
		}

		$this->pairs = $pairs;

		return $this->pairs;
	}

	/**
	 * Product IDs in the cart.
	 *
	 * @return int[]
	 */
	public function product_ids() {
		$ids = array();

		foreach ( $this->pairs() as $pair ) {
			if ( ! empty( $pair['product_id'] ) ) {
				$ids[] = (int) $pair['product_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * How the cart contents were resolved.
	 *
	 * @return string
	 */
	public function strategy() {
		if ( null === $this->pairs ) {
			$this->pairs();
		}

		return $this->strategy;
	}

	/**
	 * FluentCart entry points that may expose the current cart.
	 *
	 * Only callables that actually exist on this install are returned.
	 *
	 * @return callable[] Keyed by a human readable label.
	 */
	private function cart_sources() {
		$candidates = array(
			'fluent_cart_get_cart()'          => 'fluent_cart_get_cart',
			'fluent_cart_get_current_cart()'  => 'fluent_cart_get_current_cart',
			'Helper::getCart()'               => array( '\FluentCart\App\Helpers\Helper', 'getCart' ),
			'Helper::getCartData()'           => array( '\FluentCart\App\Helpers\Helper', 'getCartData' ),
			'CartHelper::getCart()'           => array( '\FluentCart\App\Services\Cart\CartHelper', 'getCart' ),
			'CartHelper::getCurrentCart()'    => array( '\FluentCart\App\Services\Cart\CartHelper', 'getCurrentCart' ),
			'CartService::getCart()'          => array( '\FluentCart\App\Services\CartService', 'getCart' ),
		);

		/**
		 * Filter the list of FluentCart cart accessors to try.
		 *
		 * @param array $candidates Label => callable.
		 */
		$candidates = apply_filters( 'blt_fluent/cart_sources', $candidates );

		$sources = array();

		foreach ( $candidates as $label => $candidate ) {
			if ( is_callable( $candidate ) ) {
				$sources[ $label ] = $candidate;
			}
		}

		return $sources;
	}

	/**
	 * Pull product/variation pairs out of an arbitrary payload.
	 *
	 * @param mixed $data  Payload.
	 * @param int   $depth Current depth.
	 * @return array[]
	 */
	private function pairs_from_payload( $data, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}

		$data = self::to_array( $data );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$pairs = array();

		$product_id = self::first_scalar( $data, array( 'product_id', 'post_id', 'object_id' ) );

		if ( $product_id ) {
			$pairs[] = array(
				'product_id'   => (int) $product_id,
				'variation_id' => (int) self::first_scalar( $data, array( 'variation_id', 'product_variation_id', 'variant_id' ) ),
			);
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$pairs = array_merge( $pairs, $this->pairs_from_payload( $value, $depth + 1 ) );
			}
		}

		return self::unique_pairs( $pairs );
	}

	/**
	 * Whether this checkout is a renewal rather than an initial purchase.
	 *
	 * @return bool
	 */
	public function is_renewal() {
		$order_type = $this->order_type();

		$is_renewal = ( '' !== $order_type && 'initial' !== $order_type );

		/**
		 * Filter the renewal decision.
		 *
		 * FluentCart documents the order_type key but not the values it takes on
		 * a real renewal, so this is deliberately easy to override.
		 *
		 * @param bool         $is_renewal Whether this is a renewal.
		 * @param string       $order_type The detected order type.
		 * @param Cart_Context $context    This context object.
		 */
		return (bool) apply_filters( 'blt_fluent/is_renewal', $is_renewal, $order_type, $this );
	}

	/**
	 * The detected order type. Defaults to 'initial' per FluentCart.
	 *
	 * @return string
	 */
	public function order_type() {
		$order_type = self::deep_find( $this->payload, array( 'order_type' ) );

		if ( ! is_string( $order_type ) || '' === $order_type ) {
			return 'initial';
		}

		return strtolower( $order_type );
	}

	/**
	 * The best email address available for this checkout.
	 *
	 * @return string Empty string when none is known.
	 */
	public function email() {
		$candidates = array();

		$from_payload = self::deep_find(
			$this->payload,
			array( 'billing_email', 'customer_email', 'email', 'user_email' )
		);

		if ( is_string( $from_payload ) ) {
			$candidates[] = $from_payload;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $user && $user->user_email ) {
				$candidates[] = $user->user_email;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( is_email( $candidate ) ) {
				return sanitize_email( $candidate );
			}
		}

		return '';
	}

	/**
	 * Cast objects to arrays where possible.
	 *
	 * @param mixed $data Any value.
	 * @return mixed Array when convertible, otherwise the original value.
	 */
	public static function to_array( $data ) {
		if ( is_array( $data ) ) {
			return $data;
		}

		if ( ! is_object( $data ) ) {
			return $data;
		}

		if ( method_exists( $data, 'toArray' ) ) {
			try {
				$converted = $data->toArray();

				if ( is_array( $converted ) ) {
					return $converted;
				}
			} catch ( \Throwable $e ) {
				// Fall through to a plain cast.
				unset( $e );
			}
		}

		if ( $data instanceof \JsonSerializable ) {
			try {
				$converted = $data->jsonSerialize();

				if ( is_array( $converted ) ) {
					return $converted;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return get_object_vars( $data );
	}

	/**
	 * First non-empty scalar among the given keys of an array.
	 *
	 * @param array    $data Array to look in.
	 * @param string[] $keys Keys to try, in order.
	 * @return string|int|float|bool|null
	 */
	public static function first_scalar( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== $data[ $key ] ) {
				return $data[ $key ];
			}
		}

		return null;
	}

	/**
	 * Depth-limited search for the first scalar stored under any of $keys.
	 *
	 * @param mixed    $data  Haystack.
	 * @param string[] $keys  Keys to look for.
	 * @param int      $depth Current depth.
	 * @return string|null
	 */
	public static function deep_find( $data, array $keys, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}

		$data = self::to_array( $data );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$found = self::first_scalar( $data, $keys );

		if ( null !== $found ) {
			return (string) $found;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$nested = self::deep_find( $value, $keys, $depth + 1 );

				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Depth-limited search for the first array stored under a given key.
	 *
	 * Used to find our submitted values whether they sit at the top level of the
	 * request or nested inside a wrapper FluentCart added.
	 *
	 * @param mixed  $data  Haystack.
	 * @param string $key   Key to look for.
	 * @param int    $depth Current depth.
	 * @return array Empty array when not found.
	 */
	public static function deep_find_array( $data, $key, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}

		$data = self::to_array( $data );

		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( isset( $data[ $key ] ) ) {
			$found = self::to_array( $data[ $key ] );

			if ( is_array( $found ) ) {
				return $found;
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$found = self::deep_find_array( $value, $key, $depth + 1 );

				if ( ! empty( $found ) ) {
					return $found;
				}
			}
		}

		return array();
	}

	/**
	 * De-duplicate product/variation pairs.
	 *
	 * @param array[] $pairs Pairs.
	 * @return array[]
	 */
	private static function unique_pairs( array $pairs ) {
		$unique = array();

		foreach ( $pairs as $pair ) {
			if ( empty( $pair['product_id'] ) ) {
				continue;
			}

			$key = (int) $pair['product_id'] . ':' . (int) $pair['variation_id'];

			$unique[ $key ] = array(
				'product_id'   => (int) $pair['product_id'],
				'variation_id' => (int) $pair['variation_id'],
			);
		}

		return array_values( $unique );
	}
}

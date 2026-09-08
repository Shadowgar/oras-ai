<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only least-privilege adapter for current Observer Pass product facts.
 */
final class ORAS_AI_WooCommerce_Connector implements ORAS_AI_Live_Connector_Interface {

	const CONNECTOR = 'woocommerce';
	const SUBJECT   = 'observer-pass';
	const MAX_PRODUCTS = 20;

	private $product_loader;
	private $now_provider;

	public function __construct( $product_loader = null, $now_provider = null ) {
		$this->product_loader = is_callable( $product_loader ) ? $product_loader : null;
		$this->now_provider   = is_callable( $now_provider ) ? $now_provider : null;
	}

	public function supports( ORAS_AI_Live_Request $request ) {
		return null !== $this->route( $request );
	}

	public function fetch( ORAS_AI_Live_Request $request ) {
		$route = $this->route( $request );
		if ( null === $route ) {
			return ORAS_AI_Live_Result::unknown( 'product_query_not_supported' );
		}
		if ( ! in_array( 'public', $request->allowed_visibilities(), true ) ) {
			return ORAS_AI_Live_Result::denied( 'product_visibility_denied' );
		}

		try {
			$records = null === $this->product_loader
				? $this->load_from_woocommerce()
				: call_user_func( $this->product_loader, self::SUBJECT );
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Live_Result::unknown( 'product_lookup_failed' );
		}

		if ( is_wp_error( $records ) ) {
			return 'oras_ai_woocommerce_unavailable' === $records->get_error_code()
				? ORAS_AI_Live_Result::unavailable( 'woocommerce_connector_unavailable' )
				: ORAS_AI_Live_Result::unknown( $this->lookup_error_reason( $records->get_error_code() ) );
		}
		if ( ! is_array( $records ) ) {
			return ORAS_AI_Live_Result::unknown( 'product_data_malformed' );
		}

		$matches       = array();
		$saw_relevant  = false;
		$saw_ambiguous = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				return ORAS_AI_Live_Result::unknown( 'product_data_malformed' );
			}
			if ( ! $this->is_observer_pass( $record ) ) {
				continue;
			}

			$saw_relevant = true;
			$option       = $this->option_for( $record );
			if ( '' === $option ) {
				$saw_ambiguous = true;
				continue;
			}
			if ( 'all' !== $route['option'] && $option !== $route['option'] ) {
				continue;
			}

			$normalized = $this->normalize_product( $record, $option, $route['fields'] );
			if ( is_wp_error( $normalized ) ) {
				return ORAS_AI_Live_Result::unknown( $normalized->get_error_code() );
			}
			if ( isset( $matches[ $option ] ) ) {
				return ORAS_AI_Live_Result::unknown( 'ambiguous_product_match' );
			}
			$matches[ $option ] = $normalized;
		}

		if ( $saw_ambiguous ) {
			return ORAS_AI_Live_Result::unknown( 'ambiguous_product_variation' );
		}
		if ( empty( $matches ) ) {
			return ORAS_AI_Live_Result::unknown( $saw_relevant ? 'observer_pass_option_not_found' : 'observer_pass_not_found' );
		}

		$ordered = array();
		foreach ( array( 'annual', 'daily' ) as $option ) {
			if ( isset( $matches[ $option ] ) ) {
				$ordered[ $option ] = $matches[ $option ];
			}
		}

		$facts = array();
		foreach ( $ordered as $option => $product ) {
			foreach ( $route['fields'] as $field ) {
				$fact = $this->make_fact( $product, $option, $field );
				if ( is_wp_error( $fact ) ) {
					return ORAS_AI_Live_Result::unknown( 'product_data_malformed' );
				}
				$facts[] = $fact;
			}
		}

		return ORAS_AI_Live_Result::success( $facts );
	}

	private function route( ORAS_AI_Live_Request $request ) {
		if ( ORAS_AI_Retrieval_Request::INTENT_HISTORICAL === $request->intent() ) {
			return null;
		}

		$question = strtolower( trim( wp_strip_all_tags( $request->question(), true ) ) );
		if ( ! preg_match( '/\bobserver\s+pass(?:es)?\b/', $question ) ) {
			return null;
		}
		if ( ! preg_match( '/\b(how much|price|cost|available|availability|stock|in stock|buy|purchase|purchasable|where)\b/', $question ) ) {
			return null;
		}

		$is_annual = (bool) preg_match( '/\bannual\b/', $question );
		$is_daily  = (bool) preg_match( '/\bdaily\b/', $question );
		$option    = $is_annual && ! $is_daily ? 'annual' : ( $is_daily && ! $is_annual ? 'daily' : 'all' );
		$fields    = array();
		if ( preg_match( '/\b(how much|price|cost)\b/', $question ) ) {
			$fields[] = 'price';
		}
		if ( preg_match( '/\b(available|availability|stock|in stock|buy|purchase|purchasable|where)\b/', $question ) ) {
			$fields[] = 'availability';
			$fields[] = 'purchasable';
		}

		return array(
			'option' => $option,
			'fields' => $fields,
		);
	}

	private function is_observer_pass( array $record ) {
		$text = implode(
			' ',
			array(
				(string) ( $record['name'] ?? '' ),
				(string) ( $record['slug'] ?? '' ),
				(string) ( $record['parent_name'] ?? '' ),
				(string) ( $record['parent_slug'] ?? '' ),
			)
		);
		$text = strtolower( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );

		return (bool) preg_match( '/\bobserver\s+pass(?:es)?\b/', $text );
	}

	private function option_for( array $record ) {
		$text = implode(
			' ',
			array(
				(string) ( $record['name'] ?? '' ),
				(string) ( $record['slug'] ?? '' ),
				(string) ( $record['parent_name'] ?? '' ),
				(string) ( $record['parent_slug'] ?? '' ),
			)
		);
		$text      = strtolower( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
		$is_annual = (bool) preg_match( '/\bannual\b/', $text );
		$is_daily  = (bool) preg_match( '/\bdaily\b/', $text );

		if ( $is_annual === $is_daily ) {
			return '';
		}

		return $is_annual ? 'annual' : 'daily';
	}

	private function normalize_product( array $record, $option, array $fields ) {
		$status = sanitize_key( $record['status'] ?? '' );
		if ( 'publish' !== $status ) {
			return new WP_Error( 'product_unavailable', __( 'The product is unavailable.', 'oras-ai-assistant' ) );
		}

		$id      = absint( $record['id'] ?? 0 );
		$url     = trim( (string) ( $record['canonical_url'] ?? '' ) );
		$type    = sanitize_key( $record['type'] ?? '' );
		$allowed = array( 'simple', 'variation' );
		if ( 0 === $id || '' === $url || ! in_array( $type, $allowed, true ) ) {
			return new WP_Error( 'product_data_malformed', __( 'The product data was malformed.', 'oras-ai-assistant' ) );
		}

		$product = array(
			'id'            => $id,
			'title'         => ucfirst( $option ) . ' Observer Pass',
			'type'          => $type,
			'canonical_url' => $url,
			'modified_gmt'  => sanitize_text_field( (string) ( $record['modified_gmt'] ?? '' ) ),
			'retrieved_at'  => sanitize_text_field( $this->now_value() ),
		);

		if ( in_array( 'price', $fields, true ) ) {
			$current  = $this->decimal( $record['current_price'] ?? null );
			$currency = strtoupper( trim( (string) ( $record['currency'] ?? '' ) ) );
			if ( null === $current || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				return new WP_Error( 'invalid_product_price', __( 'The product price was invalid.', 'oras-ai-assistant' ) );
			}

			$on_sale = true === ( $record['on_sale'] ?? null );
			$product['current_price'] = $current;
			$product['currency']      = $currency;
			$product['on_sale']       = $on_sale;
			if ( $on_sale ) {
				$regular = $this->decimal( $record['regular_price'] ?? null );
				$sale    = $this->decimal( $record['sale_price'] ?? null );
				if ( null === $regular || null === $sale || ! $this->same_decimal( $sale, $current ) ) {
					return new WP_Error( 'invalid_product_price', __( 'The product price was invalid.', 'oras-ai-assistant' ) );
				}
				$product['regular_price'] = $regular;
			}
		}

		if ( in_array( 'availability', $fields, true ) ) {
			$stock_status = sanitize_key( $record['stock_status'] ?? '' );
			if ( ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) || ! is_bool( $record['in_stock'] ?? null ) ) {
				return new WP_Error( 'invalid_product_availability', __( 'The product availability was invalid.', 'oras-ai-assistant' ) );
			}
			$product['stock_status'] = $stock_status;
			$product['in_stock']     = $record['in_stock'];
		}

		if ( in_array( 'purchasable', $fields, true ) ) {
			if ( ! is_bool( $record['purchasable'] ?? null ) ) {
				return new WP_Error( 'invalid_product_purchasability', __( 'The product purchasability was invalid.', 'oras-ai-assistant' ) );
			}
			$product['purchasable'] = $record['purchasable'];
		}

		return $product;
	}

	private function make_fact( array $product, $option, $field ) {
		return ORAS_AI_Live_Fact::from_array(
			array(
				'fact_key'            => 'product:observer-pass-' . $option . ':' . $field,
				'source_title'        => $product['title'],
				'source_wp_object_id' => $product['id'],
				'source_type'         => 'variation' === $product['type'] ? 'product_variation' : 'product',
				'canonical_url'       => $product['canonical_url'],
				'relevant_text'       => $this->fact_text( $product, $field ),
				'visibility'          => 'public',
				'source_modified_gmt' => $product['modified_gmt'],
				'retrieved_at'        => $product['retrieved_at'],
			)
		);
	}

	private function fact_text( array $product, $field ) {
		if ( 'price' === $field ) {
			$text = sprintf( '%1$s current price is %2$s %3$s.', $product['title'], $product['current_price'], $product['currency'] );
			if ( $product['on_sale'] ) {
				$text = sprintf(
					'%1$s current price is %2$s %3$s (active sale; regular price %4$s %3$s).',
					$product['title'],
					$product['current_price'],
					$product['currency'],
					$product['regular_price']
				);
			}
			return $text;
		}
		if ( 'availability' === $field ) {
			return sprintf(
				'%1$s stock status %2$s; in stock: %3$s.',
				$product['title'],
				$product['stock_status'],
				$product['in_stock'] ? 'yes' : 'no'
			);
		}
		if ( 'purchasable' === $field ) {
			return sprintf( '%1$s purchasable: %2$s.', $product['title'], $product['purchasable'] ? 'yes' : 'no' );
		}

		return '';
	}

	private function decimal( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );

		return preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value ) ? $value : null;
	}

	private function same_decimal( $left, $right ) {
		return $this->comparable_decimal( $left ) === $this->comparable_decimal( $right );
	}

	private function comparable_decimal( $value ) {
		$parts    = explode( '.', (string) $value, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		$integer  = '' === $integer ? '0' : $integer;

		return '' === $fraction ? $integer : $integer . '.' . $fraction;
	}

	private function now_value() {
		return null === $this->now_provider ? current_time( 'mysql' ) : call_user_func( $this->now_provider );
	}

	private function lookup_error_reason( $code ) {
		$reasons = array(
			'oras_ai_products_ambiguous' => 'ambiguous_product_match',
			'oras_ai_products_invalid'   => 'product_data_malformed',
		);

		return $reasons[ $code ] ?? 'product_lookup_failed';
	}

	private function load_from_woocommerce() {
		if (
			! function_exists( 'wc_get_products' )
			|| ! function_exists( 'wc_get_product' )
			|| ! function_exists( 'get_woocommerce_currency' )
		) {
			return new WP_Error( 'oras_ai_woocommerce_unavailable', __( 'WooCommerce is unavailable.', 'oras-ai-assistant' ) );
		}

		$products = array();
		foreach ( array( 'Observer Pass', 'Annual Observer Pass', 'Daily Observer Pass' ) as $name ) {
			$named_products = wc_get_products(
				array(
					'status'  => 'publish',
					'name'    => $name,
					'limit'   => self::MAX_PRODUCTS + 1,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
			if ( ! is_array( $named_products ) ) {
				return new WP_Error( 'oras_ai_products_invalid', __( 'The product lookup failed.', 'oras-ai-assistant' ) );
			}
			foreach ( $named_products as $product ) {
				if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
					return new WP_Error( 'oras_ai_products_invalid', __( 'The product data was malformed.', 'oras-ai-assistant' ) );
				}
				$products[ absint( $product->get_id() ) ] = $product;
			}
			if ( count( $products ) > self::MAX_PRODUCTS ) {
				return new WP_Error( 'oras_ai_products_ambiguous', __( 'The product lookup was ambiguous.', 'oras-ai-assistant' ) );
			}
		}
		$products = array_values( $products );

		$currency = (string) get_woocommerce_currency();
		$records  = array();
		foreach ( $products as $product ) {
			$record = $this->record_from_product( $product, $currency );
			if ( is_wp_error( $record ) ) {
				return $record;
			}

			if ( 'variable' !== (string) $record['type'] ) {
				$records[] = $record;
				continue;
			}

			if ( ! method_exists( $product, 'get_children' ) ) {
				return new WP_Error( 'oras_ai_products_invalid', __( 'The product data was malformed.', 'oras-ai-assistant' ) );
			}
			$children = (array) $product->get_children();
			if ( empty( $children ) || count( $children ) > self::MAX_PRODUCTS ) {
				$records[] = $record;
				continue;
			}

			foreach ( $children as $child_id ) {
				$variation = wc_get_product( absint( $child_id ) );
				$child     = $this->record_from_product( $variation, $currency, $record['name'], $record['slug'] );
				if ( is_wp_error( $child ) ) {
					return $child;
				}
				$records[] = $child;
			}
		}

		return $records;
	}

	private function record_from_product( $product, $currency, $parent_name = '', $parent_slug = '' ) {
		$required = array(
			'get_id',
			'get_name',
			'get_slug',
			'get_type',
			'get_status',
			'get_price',
			'get_regular_price',
			'get_sale_price',
			'is_on_sale',
			'get_stock_status',
			'is_in_stock',
			'is_purchasable',
			'get_permalink',
		);
		if ( ! is_object( $product ) ) {
			return new WP_Error( 'oras_ai_products_invalid', __( 'The product data was malformed.', 'oras-ai-assistant' ) );
		}
		foreach ( $required as $method ) {
			if ( ! method_exists( $product, $method ) ) {
				return new WP_Error( 'oras_ai_products_invalid', __( 'The product data was malformed.', 'oras-ai-assistant' ) );
			}
		}

		$modified = '';
		if ( method_exists( $product, 'get_date_modified' ) ) {
			$date = $product->get_date_modified();
			if ( $date instanceof DateTimeInterface ) {
				$modified = gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
			}
		}

		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'slug'          => $product->get_slug(),
			'parent_name'   => $parent_name,
			'parent_slug'   => $parent_slug,
			'type'          => $product->get_type(),
			'status'        => $product->get_status(),
			'current_price' => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'on_sale'       => true === $product->is_on_sale(),
			'currency'      => $currency,
			'stock_status'  => $product->get_stock_status(),
			'in_stock'      => true === $product->is_in_stock(),
			'purchasable'   => true === $product->is_purchasable(),
			'canonical_url' => $product->get_permalink(),
			'modified_gmt'  => $modified,
		);
	}
}

<?php
/**
 * Order Tax Applicator
 *
 * Applies tax details to order.
 *
 * @package TaxJar\TaxCalculation
 */

namespace TaxJar;

use Automattic\WooCommerce\Utilities\NumberUtil;
use WC_Tax, WC_Abstract_Order;
use \Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Tax_Applicator
 */
class Order_Tax_Applicator implements Tax_Applicator_Interface {

	/**
	 * Order to apply tax to.
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * Tax details to apply to order.
	 *
	 * @var Tax_Details
	 */
	private $tax_details;

	/**
	 * Order_Tax_Applicator constructor.
	 *
	 * @param WC_Order $order Order to apply tax to.
	 */
	public function __construct( $order ) {
		$this->order = $order;
	}

	/**
	 * Apply tax to order.
	 *
	 * @param Tax_Details $tax_details Tax details to apply to order.
	 *
	 * @throws Tax_Calculation_Exception If tax detail does not indicate nexus for the transaction.
	 * @throws Exception If line item tax data not present in details.
	 */
	public function apply_tax( $tax_details ) {
		$this->tax_details = $tax_details;
		$this->check_tax_details_for_nexus();
		$this->remove_existing_tax();
		$this->apply_new_tax();
	}

	/**
	 * Check that response from TaxJar API indicates transaction has nexus.
	 *
	 * @throws Tax_Calculation_Exception If tax detail does not indicate nexus for the transaction.
	 */
	private function check_tax_details_for_nexus() {
		if ( ! $this->tax_details->has_nexus() ) {
			throw new Tax_Calculation_Exception(
				'no_nexus',
				__( 'Tax response for order does not have nexus.', 'taxjar' )
			);
		}
	}

	/**
	 * Remove existing tax on order.
	 */
	private function remove_existing_tax() {
		$this->order->remove_order_items( 'tax' );
	}

	/**
	 * Apply new tax to order.
	 *
	 * @throws Exception If line item tax data not present in details.
	 */
	private function apply_new_tax() {
		$this->apply_tax_to_line_items();
		$this->apply_tax_to_fees();
		$this->apply_tax_to_shipping_items();
		$this->order->update_taxes();
		$this->update_totals();
		$this->order->save();
	}

	/**
	 * Apply tax to order line items.
	 *
	 * @throws Exception If line item tax data not present in details.
	 */
	private function apply_tax_to_line_items() {
		foreach ( $this->order->get_items() as $item_key => $item ) {
			$this->create_rate_and_apply_to_product_line_item( $item_key, $item );
		}
	}

	/**
	 * Create WooCommerce tax rate and apply it to product line item.
	 *
	 * @param integer               $item_key Index of line item.
	 * @param WC_Order_Item_Product $item Item to create rate for.
	 *
	 * @throws Exception If line item tax data not present in details.
	 */
	private function create_rate_and_apply_to_product_line_item( $item_key, $item ) {
		$wc_rate   = $this->create_product_tax_rate( $item_key, $item );
		$tax_rates = $this->prepare_tax_rates_for_application( $wc_rate );
		$taxes     = array(
			'total'    => WC_Tax::calc_tax( $item->get_total(), $tax_rates, false ),
			'subtotal' => WC_Tax::calc_tax( $item->get_subtotal(), $tax_rates, false ),
		);
		$item->set_taxes( $taxes );
	}

	/**
	 * Creates a WooCommerce tax rate for the given item.
	 *
	 * @param integer               $item_key Array key of line item.
	 * @param WC_Order_Item_Product $item Item to create rate for.
	 *
	 * @throws Exception If line item tax data not present in details.
	 *
	 * @return array
	 */
	private function create_product_tax_rate( $item_key, $item ) {
		$line_item_tax_rate = $this->get_product_line_item_tax_rate( $item_key, $item );
		$tax_class          = $item->get_tax_class();
		return Rate_Manager::add_rate(
			$line_item_tax_rate,
			$tax_class,
			$this->tax_details->is_shipping_taxable(),
			$this->tax_details->get_location()
		);
	}

	/**
	 * Prepares the tax rate for application to item.
	 *
	 * @param array $wc_rate Tax rate.
	 *
	 * @return array
	 */
	private function prepare_tax_rates_for_application( $wc_rate ) {
		return array(
			$wc_rate['id'] => array(
				'rate'     => (float) $wc_rate['tax_rate'],
				'label'    => $wc_rate['tax_rate_name'],
				'shipping' => $wc_rate['tax_rate_shipping'] ? 'yes' : 'no',
				'compound' => 'no',
			),
		);
	}

	/**
	 * Gets tax rate from tax details for product line item.
	 *
	 * @param integer               $item_key Index of line item.
	 * @param WC_Order_Item_Product $item Item to get tax rate for.
	 *
	 * @throws Exception If line item tax data not present in details.
	 *
	 * @return float|int
	 */
	private function get_product_line_item_tax_rate( $item_key, $item ) {
		$product_id           = $item->get_product_id();
		$line_item_key        = $product_id . '-' . $item_key;
		$tax_detail_line_item = $this->tax_details->get_line_item( $line_item_key );

		if ( false === $tax_detail_line_item ) {
			throw new Exception( 'Line item not present in tax details.' );
		}

		return 100 * $tax_detail_line_item->get_tax_rate();
	}

	/**
	 * Apply tax to order fees.
	 */
	private function apply_tax_to_fees() {
		foreach ( $this->order->get_items( 'fee' ) as $fee_key => $fee ) {
			$this->create_rate_and_apply_to_fee_line_item( $fee_key, $fee );
		}
	}

	/**
	 * Create WooCommerce tax rate and apply it to fee item.
	 *
	 * @param integer           $fee_key Index of fee item.
	 * @param WC_Order_Item_Fee $fee Fee to apply tax to.
	 */
	private function create_rate_and_apply_to_fee_line_item( $fee_key, $fee ) {
		$fee_tax_rate = $this->get_tax_rate_for_fee_line_item( $fee_key, $fee );
		$tax_class    = $fee->get_tax_class();
		$wc_rate      = Rate_Manager::add_rate(
			$fee_tax_rate,
			$tax_class,
			$this->tax_details->is_shipping_taxable(),
			$this->tax_details->get_location()
		);

		$tax_rates = $this->prepare_tax_rates_for_application( $wc_rate );
		$taxes     = array( 'total' => WC_Tax::calc_tax( $fee->get_total(), $tax_rates, false ) );
		$fee->set_taxes( $taxes );
	}

	/**
	 * Gets the tax rate from tax details for fee item.
	 *
	 * @param integer           $fee_key Index of fee item.
	 * @param WC_Order_Item_Fee $fee Fee item to get tax rate for.
	 *
	 * @return float|int
	 */
	private function get_tax_rate_for_fee_line_item( $fee_key, $fee ) {
		$fee_details_id       = 'fee-' . $fee_key;
		$tax_detail_line_item = $this->tax_details->get_line_item( $fee_details_id );
		return 100 * $tax_detail_line_item->get_tax_rate();
	}

	/**
	 * Apply tax to shipping items.
	 */
	private function apply_tax_to_shipping_items() {
		foreach ( $this->order->get_shipping_methods() as $item ) {
			$this->apply_tax_to_shipping_item( $item );
		}
	}

	/**
	 * Create WooCommerce tax rate for shipping and apply tax to a shipping item.
	 * If shipping is not taxable remove taxes from shipping item.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item to apply tax to.
	 */
	private function apply_tax_to_shipping_item( $item ) {
		if ( $this->tax_details->is_shipping_taxable() ) {
			$wc_rate = Rate_Manager::add_rate(
				$this->get_shipping_tax_rate(),
				'',
				$this->tax_details->is_shipping_taxable(),
				$this->tax_details->get_location()
			);

			$tax_rates = $this->prepare_tax_rates_for_application( $wc_rate );
			$taxes     = array( 'total' => WC_Tax::calc_tax( $item->get_total(), $tax_rates, false ) );
			$item->set_taxes( $taxes );
		} else {
			$this->apply_zero_tax_to_item( $item );
		}
	}

	/**
	 * Get the shipping tax rate from tax details.
	 *
	 * @return float|int
	 */
	private function get_shipping_tax_rate() {
		return 100 * $this->tax_details->get_shipping_tax_rate();
	}

	/**
	 * Removes tax from shipping item.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item to remove tax from.
	 */
	private function apply_zero_tax_to_item( $item ) {
		$item->set_taxes( false );
	}

	/**
	 * Update order totals after applying tax.
	 */
	private function update_totals() {
		$tax_sums = $this->sum_taxes();
		$this->order->set_discount_tax( wc_round_tax_total( $tax_sums['cart_subtotal_tax'] - $tax_sums['cart_total_tax'] ) );
		$this->order->set_total( NumberUtil::round( $this->get_order_total(), wc_get_price_decimals() ) );
	}

	/**
	 * Aggregate taxes applied to order.
	 *
	 * @return array
	 */
	private function sum_taxes() {
		$tax_sums = array(
			'cart_subtotal_tax' => 0,
			'cart_total_tax'    => 0,
		);

		foreach ( $this->order->get_items() as $item ) {
			$taxes = $item->get_taxes();

			foreach ( $taxes['total'] as $tax_rate_id => $tax ) {
				$tax_sums['cart_total_tax'] += (float) $tax;
			}

			foreach ( $taxes['subtotal'] as $tax_rate_id => $tax ) {
				$tax_sums['cart_subtotal_tax'] += (float) $tax;
			}
		}

		return $tax_sums;
	}

	/**
	 * Get order total.
	 *
	 * @return float
	 */
	private function get_order_total() {
		$cart_total     = $this->get_cart_total_for_order();
		$tax_total      = $this->order->get_cart_tax() + $this->order->get_shipping_tax();
		$fees_total     = $this->order->get_total_fees();
		$shipping_total = $this->order->get_shipping_total();
		return $cart_total + $tax_total + $fees_total + $shipping_total;
	}

	/**
	 * Get cart total (sum of item subtotals) of order.
	 *
	 * @return float
	 */
	private function get_cart_total_for_order() {
		$field = 'total';
		$items = array_map(
			function ( $item ) use ( $field ) {
				return wc_add_number_precision( $item[ $field ], false );
			},
			array_values( $this->order->get_items() )
		);

		return wc_remove_number_precision( WC_Abstract_Order::get_rounded_items_total( $items ) );
	}
}

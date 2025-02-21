<?php

namespace FlourishWooCommercePlugin\Handlers;

defined( 'ABSPATH' ) || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersSyncNow;

class HandlerOrdersOutbound
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    
    public function register_hooks() {
        // Hook to handle COD payment success
         add_filter('woocommerce_can_reduce_order_stock', '__return_false'); 
         add_filter('wc_order_statuses', [$this,'add_custom_order_status_to_wc']);
         add_filter('woocommerce_cod_process_payment_order_status', [$this,'set_cod_order_status_to_draft'], 10, 2);
         add_filter('wc_order_is_editable', function ($is_editable, $order) {
            // Disallow editing for 'processing' and 'pending payment' statuses
            if (in_array($order->get_status(), ['processing', 'pending','completed'])) {
                return false;
            }
            // Allow editing for 'draft' status
            if ($order->get_status() === 'draft' || $order->get_status() === 'checkout-draft') {
                return true;
            }
            return $is_editable;
        }, 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this,'update_held_stock_on_order'], 10, 1 );

    }
    
     
    function set_cod_order_status_to_draft($status, $order) {
        return 'wc-checkout-draft'; // Ensure "draft" is registered as a valid status
    }
    
     
    public function add_custom_order_status_to_wc($order_statuses) {
        if (is_account_page()   && !is_admin()) {  
                $order_statuses['checkout-draft'] = _x('Draft', 'Order status', 'your-text-domain');
            }
            return $order_statuses;
                
        }
    
    
    public function update_held_stock_on_order( $order_id ) {
            if ( ! $order_id ) {
                return;
            }
        
            // Get the order object
            $order = wc_get_order( $order_id );
            //$all_meta = $order->get_meta_data();
            // Loop through order items
            HandlerOrdersSyncNow::adjust_variation_stock($order,'decrease');
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id(); // Get the product ID
                $order_qty = $item->get_quantity(); // Get the quantity ordered
            
                $product = wc_get_product( $product_id );
                 // If it's a simple product, adjust stock for the product
                 $current_held_stock = (int) get_post_meta( $product_id, '_held_stock', true );

                if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
                    // If it's a variable or variation product, adjust stock for the variation and attributes
                    $variation_id = $item->get_variation_id();
                    if ( $variation_id ) { 
						$product = wc_get_product( $variation_id );
                        // Get variation attributes
                $attributes = $product->get_attributes();

                foreach ($attributes as $attribute_slug => $attribute_value) {
                    $taxonomy = $attribute_slug;

                    // Fetch term details
                    $term = get_term_by('slug', $attribute_value, $taxonomy);
                    
                        if ( $term ) {
                            $term_quantity = get_term_meta( $term->term_id, 'quantity', true );
                            $adjust_quantity = $order_qty * (int) $term_quantity; 
                        }
                     
                }
                    }
                }  
                else
                {
                    $adjust_quantity=$order_qty;
                }

                $new_held_stock = max( 0, $current_held_stock - $adjust_quantity ); // Prevent negative values
                update_post_meta( $product_id, '_held_stock', $new_held_stock );
            }
            
        }
        
    
    public static function create_address_object($wc_order, $type)
    {
        return (object)[
            'address_line_1' => $wc_order->{"get_{$type}_address_1"}(),
            'address_line_2' => $wc_order->{"get_{$type}_address_2"}(),
            'city' => $wc_order->{"get_{$type}_city"}(),
            'state' => $wc_order->{"get_{$type}_state"}(),
            'zip_code' => $wc_order->{"get_{$type}_postcode"}(),
            'country' => 'United States',
        ];
    }

    public static function create_destination_object($wc_order, $billing_address)
    {
        $order_id = $wc_order->get_id();
        $license_number = $_POST['license'] ?? get_user_meta($order_id, 'license', true);

        // Check if $license_number is an array and get the first element
        if (is_array($license_number)) {
            $license_value = isset($license_number[0]) ? $license_number[0] : $license_number; // Default to empty string if not set
        } else {
            // If it's not an array, just assign the value directly
            $license_value = $license_number;
        }
        return [
            'type' => 'Dispensary',
            'name' => $wc_order->get_shipping_company() ?: $wc_order->get_billing_company()?:$wc_order->get_billing_first_name(),
            'company_email' => $wc_order->get_billing_email(),
            'company_phone_number' => $wc_order->get_billing_phone(),
            'address_line_1' => $wc_order->get_shipping_address_1() ?: $wc_order->get_billing_address_1(),
            'address_line_2' => $wc_order->get_shipping_address_2() ?: $wc_order->get_billing_address_2(),
            'city' => $wc_order->get_shipping_city() ?: $wc_order->get_billing_city(),
            'state' => $wc_order->get_shipping_state() ?: $wc_order->get_billing_state(),
            'zip_code' => $wc_order->get_shipping_postcode() ?: $wc_order->get_billing_postcode(),
            'country' => 'United States',
            'license_number' => $license_value,
            'billing' => $billing_address,
            'external_id' => $wc_order->get_user_id(),
        ];
    }

    public static function get_order_lines($wc_order,$action)
    {
        $order_lines = [];
        $order_id = $wc_order->get_id();
        $total_reserved_stock = 0; // Initialize total reserved stock

        foreach ($wc_order->get_items() as $item) {
            $variation_id = $item->get_variation_id();
            $product = wc_get_product($variation_id ? $variation_id : $item->get_product_id());
            $line_item_id = $item->get_id(); // Get the line item ID
            $case_quantity = 0; // Default case quantity

            // Check if the product is a variation
            if ($variation_id) {
                // Get variation attributes
                $attributes = $product->get_attributes();

                foreach ($attributes as $attribute_slug => $attribute_value) {
                    // Ensure attribute slug starts with "pa_"
                    //$taxonomy = 'pa_' . str_replace('base-uom-', '', $attribute_slug);
                    $taxonomy = $attribute_slug;
                    //if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $attribute_value, $taxonomy);
                        if ($term) {
                            $case_quantity = get_term_meta($term->term_id, 'quantity', true) ?: 1; // Default to 1 if not set
                            error_log("Term ID: {$term->term_id}, Case Quantity: {$case_quantity}");
                        } else {
                            error_log("No term found for attribute {$taxonomy} and value {$attribute_value}");
                        }
                    
                }
                
            }

            if ($product && $product->get_sku()) {
                // Calculate total weight using case quantity
                $weight = (float)$case_quantity; 
                $quantity = $item->get_quantity();
                $product_id = $item->get_product_id();
                $total_weight = $weight > 0 ? $weight * $quantity : $quantity;
                $total_reserved_stock += $total_weight;
                if($action == 'create')
                {
                    $line_item_stock = (int) get_post_meta($line_item_id, '_reserved_stock', true); 
                    $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
                    $reversed_stock_decrease = $reserved_stock - $line_item_stock; 
                    update_post_meta($product_id, '_reserved_stock', $reversed_stock_decrease); 
                    
                }
                
                $order_lines[] = (object)[
                    'sku' => $product->get_sku(),
                    'order_qty' => $total_weight,
                ];

            }
        }
        
        return $order_lines;

    }
   
    public static function get_customer_notes($wc_order)
    {
        $notes = [];
        if ($customer_note = $wc_order->get_customer_note()) {
            $notes[] = (object)[
                'subject' => 'Customer Note from WooCommerce',
                'note' => $customer_note,
                'internal_only' => false,
            ];
        }
        return $notes;
    }

    public static function validate_facility_config($flourish_api, $facility_id, $sale_rep_id, &$order)
    {
        $facility_config = $flourish_api->fetch_facility_config($facility_id);
        if (empty($facility_config)) {
            throw new \Exception("No facility config found for facility ID: " . $facility_id);
        }

        if ($facility_config['sales_rep_required_for_outbound']) { 
            if (!$sale_rep_id) {
                throw new \Exception("Sales rep is required for outbound orders for this facility");
            }
            $order['sales_rep'] = (object)['id' => $sale_rep_id];
        }
    }

}

<?php
namespace FlourishWooCommercePlugin\Admin;

use FlourishWooCommercePlugin\Handlers\HandlerOutboundMultipleCart;

class WoocommerceSettingsCustomFields
{
    public function __construct()
    {
        // Register the necessary WooCommerce hooks.
        $this->register_hooks();
    }
 
    public function register_hooks()
    {
        // Add custom Stock Reservation Time field to WooCommerce Settings (Inventory Tab)
        add_filter('woocommerce_get_settings_products', [$this, 'add_stock_reservation_time_setting']);
        // Save the Stock Reservation Time setting
        add_action('woocommerce_update_options_products', [$this, 'save_stock_reservation_time_setting']);
        add_action('woocommerce_after_cart_item_quantity_update', [$this,'adjust_stock_on_cart_update'],10,3);
        // Store reservation time in session
        add_filter('woocommerce_loop_add_to_cart_link',[$this, 'replace_add_to_cart_with_view_cart'], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [$this, 'store_reservation_time_in_cart'], 10, 2);
        add_action('wp_footer', [$this, 'disable_add_to_cart_button_for_existing_items'],99);
        // Handle stock adjustments on adding/removing cart items
        add_action('woocommerce_add_to_cart', [$this, 'reduce_stock_on_add'], 10, 2);  
        // Display remaining reservation time in the cart
        add_filter('woocommerce_get_item_data', [$this, 'display_remaining_reservation_time'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [$this,'change_variation_max_qty_in_cart'], 10, 3);
       // add_action('woocommerce_cart_loaded_from_session', [$this, 'change_variation_max_qty_in_cart'], 10, 3);
         add_action('wp_ajax_get_dynamic_attribute_data', [$this, 'ajax_get_dynamic_attribute_data']);
        add_action('wp_ajax_nopriv_get_dynamic_attribute_data', [$this,'ajax_get_dynamic_attribute_data']);
        add_action('woocommerce_single_product_summary', [$this,'custom_single_product_quantity_box']);
        add_action('woocommerce_before_cart', [$this,'remove_expired_cart_items']);
        //  cart_cleanup_item_reservation_timeout
        add_action('wp_ajax_restore_stock_on_remove', [$this,'restore_stock_on_remove']);
        add_action('wp_ajax_nopriv_restore_stock_on_remove', [$this,'restore_stock_on_remove']);
        add_action('wp_ajax_cart_cleanup_item_reservation_timeout', [$this,'cart_cleanup_item_reservation_timeout']);
        add_action('wp_ajax_nopriv_cart_cleanup_item_reservation_timeout', [$this,'cart_cleanup_item_reservation_timeout']);

        } 
    public function replace_add_to_cart_with_view_cart($button, $product) {
            if (!$product->is_in_stock()) {
                // Return the default button if the product is out of stock
                return $button;
            }
        
            // Check if the product is already in the cart
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product->get_id()) {
                    // Replace "Add to Cart" with "View Cart" button
                    $cart_url = wc_get_cart_url();
                    return '<a href="' . esc_url($cart_url) . '" class="button wc-forward">' . __('View Cart', 'woocommerce') . '</a>';
                }
            }
        
            // Return the default button if the product is not in the cart
            return $button;
        }
    public function custom_single_product_quantity_box() {
        global $product; 
    
        if ($product->is_type('variable')) {
            ?>
             
            <script>
                jQuery(document).ready(function ($) {
                    $('form.variations_form').on('show_variation', function (event, variation) {
                        var $quantityInput = $('input.qty');
                        $('.stock.in-stock:first').hide(); 
                        // Get selected variation and product IDs
                        var selectedVariationId = variation.variation_id; 
                        var selectedProductId = $('input[name="product_id"]').val();

                        // AJAX call to fetch dynamic attribute data for the selected variation
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            method: 'POST',
                            data: {
                                action: 'get_dynamic_attribute_data',
                                variation_id: selectedVariationId,
                                product_id: selectedProductId
                            },
                            success: function (response) {
                                if (response.success) {
                                    var maxQty = response.data.maxQty; 
                                    var stockMessage = response.data.stockMessage;
                                    var stockQty = response.data.stock_quantity+ ' in stock';
                                    // Update quantity input max attribute
                                    $quantityInput.attr('max', maxQty);
                                    $quantityInput.val(1);
                                    // Remove all existing stock messages
                                    $('.woocommerce-variation-availability p.stock').not(':first').remove();

                                    // Update or add the stock message dynamically
                                    var $stockMessageContainer = $('.woocommerce-variation-availability p.stock:first');
                                    if ($stockMessageContainer.length) {
                                        $stockMessageContainer.text(stockQty).show(); // Update text and show the first stock message
                                    } else {
                                        $('.woocommerce-variation-add-to-cart').before( stockQty );
                                    }

                                    // Hide default WooCommerce stock messages if present elsewhere
                                    $('.woocommerce-variation-availability p.stock').slice(1).hide();
                                } else {
                                    console.error(response.data.message);
                                }
                            },
                            error: function (xhr, status, error) {
                                console.error('Error:', error);
                            }
                        });
                    });
                });
            </script>
            <?php
        }
        else
        {
?>
            <script>
            jQuery(document).ready(function ($) {
                 
                    var $quantityInput = $('input.qty');
                    console.log($quantityInput);
                    // Pass the selected variation ID to fetch attribute data dynamically 
                    var selectedProductId=$('button.single_add_to_cart_button').val();

                    // AJAX call to fetch dynamic attribute data for the selected variation
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        method: 'POST',
                        data: {
                            action: 'get_dynamic_attribute_data', 
                            product_id:selectedProductId
                        },
                        success: function (response) {
                            if (response.success) {   
                                console.log(response);
                                var stockMessage = response.data.stockMessage;
                                $quantityInput.val(1);
                                // Display stock message dynamically
                            $('#custom-stock-message').remove(); // Remove previous message
                            $('form.cart').before('<p id="custom-stock-message" class="custom-stock-message">' + stockMessage + '</p>');
                            $('.stock.in-stock:first').hide();
                                 
                            } else {
                                console.error(response.data.message);
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Error:', error);
                        }
                    });
                });
 

        </script>
        <?php
        }
    }

    public function cart_cleanup_item_reservation_timeout() {
        // Clean up expired saved carts
        //WC()->cart->get_cart(); // This will load the cart from session
        //WC()->cart->calculate_totals(); 
         
        $this->check_cart_item_expiration();
        $save_cart_expire = new HandlerOutboundMultipleCart;
        $save_cart_expire->mc_remove_expired_saved_carts();
    }
    

    /**
     * AJAX handler for fetching dynamic attribute data.
     */
public function ajax_get_dynamic_attribute_data() {
    // Ensure the request comes with a valid variation ID
    if (!isset($_POST['variation_id'])) {
            $product_id = intval($_POST['product_id']);
            $product = wc_get_product($product_id);
             // Retrieve stock quantity for the variation
            $total_stock = $product->get_stock_quantity();
            $held_stock= get_post_meta( $product_id, '_held_stock', true) ?: 0;
            $total_qty=$total_stock-$held_stock;
            $stock_message = $total_qty > 0 
             ? sprintf(
                 '<div class="woocommerce-variation-availability"><p class="stock in-stock">%d in stock</p></div>',
                 $total_qty
             )
             : '<div class="woocommerce-variation-availability"><p class="stock out-of-stock">Out of stock</p></div>';
             wp_send_json_success([
                'stock_quantity' => $total_qty, 
                'stockMessage' => $stock_message,
            ]);
    }
    else
    {

    $variation_id = intval($_POST['variation_id']);
     
    $product = wc_get_product($variation_id);

    if (!$product || !$product->is_type('variation')) {
        wp_send_json_error(['message' => 'Invalid product type'], 400);
    }

    // Retrieve stock quantity for the variation
    $total_stock = $product->get_stock_quantity();
   $held_stock= get_post_meta($_POST['product_id'], '_held_stock', true) ?: 0;
   $total_qty=$total_stock-$held_stock;
    if ($total_qty <= 0) {
        wp_send_json_error(['message' => 'No stock available'], 400);
    }

    // Prepare attribute data for the selected variation
    $variation_attributes = $product->get_attributes(); 

    foreach ($variation_attributes as $attribute_key => $attribute_value) {
        // Remove "attribute_" prefix to get the taxonomy
        $taxonomy = str_replace('attribute_', '', $attribute_key);

        // Get the term by its slug or name
        $term = get_term_by('slug', $attribute_value, $taxonomy);

        if ($term) {
            // Retrieve the custom meta data (e.g., 'quantity')
            $pack_size = get_term_meta($term->term_id, 'quantity', true) ?: 1;

            // Calculate the max quantity
            $max_qty = floor($total_qty / $pack_size);
             // Prepare the stock message
             $stock_message = $total_qty > 0 
             ? sprintf(
                 '<div class="woocommerce-variation-availability"><p class="stock in-stock">%d in stock</p></div>',
                 $total_qty
             )
             : '<div class="woocommerce-variation-availability"><p class="stock out-of-stock">Out of stock</p></div>';
        }
    }

    // Send JSON response
    wp_send_json_success([
        'stock_quantity' => $total_qty,
        'maxQty'=>$max_qty, 
        'stockMessage' => $stock_message,
    ]);
}

}

    
    public function change_variation_max_qty_in_cart($product_quantity, $cart_item_key, $cart_item) {
        $product_id = $cart_item['product_id']; 
        $product = wc_get_product($product_id);
        $total_qty=0;
        $total_stock = $product->get_stock_quantity();
        $held_stock= get_post_meta($product_id, '_held_stock', true) ?: 0;
        $total_qty=$total_stock-$held_stock;
       
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation']))
        {
            
                    foreach ($cart_item['variation'] as $attribute_key => $attribute_value) { 
                    // Clean attribute key (remove "attribute_").
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    // Get the term by its name in the corresponding taxonomy.
                    $term = get_term_by('name', $attribute_value, $taxonomy);

                    if ($term) {
                        // Get the custom term quantity meta.
                        $pack_size =get_term_meta($term->term_id, 'quantity', true) ?: 0;
                        
                        $total_cart_qty=($cart_item['quantity']*$pack_size)+$total_qty; 
                        $max_qty=floor($total_cart_qty/$pack_size);  
                    }
                }
            
        }
        else
        {
            $max_qty=$cart_item['quantity']+$total_qty;
        }

        $product_quantity = sprintf(
            '<div class="quantity">
                <label class="screen-reader-text" for="quantity_%1$s">Quantity</label>
                <input type="button" value="-" class="qty_button minus">
                <input type="number" id="quantity_%1$s" name="cart[%2$s][qty]" value="%3$s" min="1" max="%4$s" step="1" class="input-text qty text" size="4" pattern="[0-9]*" inputmode="numeric" aria-labelledby="quantity-label">
                <input type="button" value="+" class="qty_button plus">
            </div>',
            esc_attr($cart_item_key), // Unique ID for the input
            esc_attr($cart_item_key), // Name attribute
            esc_attr($cart_item['quantity']), // Current quantity
            esc_attr($max_qty) // Max value
        ); 
         
    
        return $product_quantity;
    } 
    /**
     * Disable the Add to Cart button for products already in the cart.
     */
    public function disable_add_to_cart_button_for_existing_items() {
        if (is_product()) {
            global $product;
    
            // Prepare cart data to pass to JavaScript
            $cart_items = [];
            foreach (WC()->cart->get_cart() as $cart_item) {
                $cart_items[] = [
                    'product_id'   => $cart_item['product_id'],
                    'variation_id' => $cart_item['variation_id'],
                ];
            }
    
            ?>
            
            <script type="text/javascript">

document.addEventListener('DOMContentLoaded', function () {
    var cartItems = <?php echo json_encode($cart_items); ?>;
    var productId = <?php echo $product->get_id(); ?>;

    function checkIfVariationInCart() {
        // Use setTimeout to delay the execution of the logic inside the function
        setTimeout(function() {
            var $form = jQuery('.woocommerce-variation-add-to-cart'); // Select the form container
            var variationId = $form.find('input.variation_id').val(); // Get variation ID from hidden input
            if (!$form.length)
            { 
            var $form = jQuery('.cart');  
            }
             
            var $button = $form.find('button.single_add_to_cart_button');
            var $qty_button = $form.find('.quantity');
            var $stock_notice=$form.find('.stock-notice');
            var isInCart = false;
        
            // Check if the selected variation is in the cart
            cartItems.forEach(function (item)
            {
                if (item.product_id == productId && item.variation_id == parseInt(variationId)) {
                    isInCart = true;
                }
                else if(item.product_id == productId && item.variation_id == 0)
                {
                    isInCart = true;
                }
            });
         
            if (isInCart)
            {
                              
                $qty_button.hide(); // Hide quantity box
                $button.hide(); 
                $stock_notice.hide();
                // Hide the add-to-cart button
              // Add "Already in Cart" message and "View Cart" button
                var cartUrl = '<?php echo esc_url(wc_get_cart_url()); ?>'; // Get WooCommerce cart URL
                 // Check if the container already exists
                if (jQuery('#already-in-cart-container').length === 0) {
                // Create the container and add it to the DOM
                $button.after(
                '<div id="already-in-cart-container">' +
                    '<div class="woocommerce-notices-wrapper">' +
                        '<div class="woocommerce-message" role="alert" tabindex="-1">' +
                            '<?php _e("Already item in Cart", "your-text-domain"); ?>' +
                            '<a href="' + cartUrl + '" class="button wc-forward" id="view-cart-link" style="float:right;margin-left:30px;">' +
                                '<?php _e("View Cart", "your-text-domain"); ?>' +
                            '</a>' +
                        '</div>' +
                    '</div>' +
                '</div>'
                );
                }

                // Smoothly fade in the container
                jQuery('#already-in-cart-container').fadeIn('fast');
                }
                else
                {
                $qty_button.show(); // Show quantity box
                $button.show();// Hide the add-to-cart button
                $stock_notice.show(); 
                // Smoothly fade out and remove the container if it exists
               if (jQuery('#already-in-cart-container').length) {  
                jQuery('#already-in-cart-container').fadeOut('fast', function () {
                jQuery(this).remove(); // Remove after fade-out
                }); 

               }

                // Re-add the default "Add to Cart" button if it doesn't exist
                if (jQuery('.single_add_to_cart_button').length === 0) {
                $qty_button.after(
                '<button type="submit" class="single_add_to_cart_button button alt">' +
                    '<?php _e("Add to Cart", "your-text-domain"); ?>' +
                '</button>'
                );
                }
            }
        }, 500); // Delay of 500 milliseconds (you can adjust this time as needed)
    }

    // Check on page load
    checkIfVariationInCart();

    // Recheck when variation is changed
    jQuery(document.body).on('change', 'table.variations select', function () {
        checkIfVariationInCart();
    });
    jQuery(document.body).on('updated_cart_totals', function() {
    checkIfVariationInCart();
});

});

            </script>
            <?php
        }
    }
     
    public function adjust_stock_on_cart_update($cart_item_key, $new_quantity, $old_quantity) {
        // Get the updated cart item using the cart item key.
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        // Ensure the cart item exists and fetch the product ID.
        if (!$cart_item) {
            return;
        }
        $product_id = isset($cart_item['variation_id']) && $cart_item['variation_id'] 
            ? $cart_item['variation_id'] 
            : $cart_item['product_id'];
        $parent_id = $cart_item['product_id'];
        // Calculate the quantity difference.
        $quantity_difference = $new_quantity - $old_quantity;
        $held_stock = get_post_meta($parent_id, '_held_stock', true);
        // Retrieve the total stock of the parent product.
         
        $stock=get_post_meta($parent_id, '_stock', true);
        $parent_stock =  $stock-$held_stock;
        // --- Variation pack size validation logic ---
        // Retrieve all variations of the parent product.
        $args = array(
            'post_type' => 'product_variation',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'post_parent' => $parent_id, // Parent product ID
        );
        $product = wc_get_product($parent_id);  
        $varia = wc_get_product($product_id);    
        //validation update cart
        $attributes = $product->get_attributes();
        $variations = get_posts($args);
        $total_variation_qty = 0;
        $variation_pack_sizes = []; // Store pack sizes for each variation.
        $total_variation_qty = 0;  // To store the total quantity of all variations in the cart.
        if ($product->is_type('simple'))
        {
            //simple product
        }
        else
        {
            $total_variation_qty = 0;
            $variation_pack_sizes = [];
	   // First, calculate the total quantities of variations in the cart.
            foreach ($variations as $variation) {
                $variation_id = $variation->ID;
                $variation_qty = 0;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['variation_id'] == $variation_id) {
                        $variation_qty += $cart_item['quantity'];
                    }
                }
                $total_variation_qty += $variation_qty;
            }
	            // Loop through each variation to validate its stock with the pack size.
            foreach ($variations as $variation) {
                $variation_id = $variation->ID;
                $post_excerpt = get_post_field('post_excerpt', $variation_id);
                // Extract the pack size value from the post excerpt.
                $output = strpos($post_excerpt, ':') !== false ? trim(explode(':', $post_excerpt, 2)[1]) : '';
                // Get the pack size for the current variation.
                $pack_size = 0;
                $attributes = wc_get_product_variation_attributes($variation_id);
                foreach ($attributes as $attribute_key => $attribute_value) {
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    if ($attribute_value === $output) {
                        $term = get_term_by('name', $attribute_value, $taxonomy);
                        if ($term) {
                            $pack_size = get_term_meta($term->term_id, 'quantity', true) ?: 0;
                        }
                    }
                }
                $variation_pack_sizes[$variation_id] = $pack_size;
                // Get the quantity of this variation in the cart.
                $variation_qty = 0;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['variation_id'] == $variation_id) {
                        $variation_qty += $cart_item['quantity'];
                        $variation_product = wc_get_product($cart_item['variation_id']);

                        if ($variation_product) {
                            // Get the variation name
                            $variation_name = $variation_product->get_name();
                        }
                    }
                }
                // Calculate the max allowed quantity for this variation.
                if ($pack_size > 0 && $variation_qty !== 0) {
                    $max_allowed_qty = floor($parent_stock / $pack_size);
                    $allowed_qty_variation = $max_allowed_qty + $old_quantity;
                    if ($quantity_difference > $max_allowed_qty) {
                        wc_add_notice(
                            sprintf(__('The maximum allowed quantity for "%s" is %d.', 'text-domain'),$variation_name,$allowed_qty_variation ),
                            'notice'
                        );
                        wp_safe_redirect(wc_get_cart_url());
                        //exit;
                    }
                }
            }
           
	 }
 
        // --- Stock adjustment logic ---
        // quantity update in update cart
        if ($quantity_difference !== 0) {
            $adjust_quantity = $quantity_difference*$pack_size;
            // Adjust stock for the parent product.
            $adjust_quantity = $quantity_difference;
            $cart_item = WC()->cart->get_cart_item($cart_item_key);
            // If the product is a variation, adjust based on term quantity.
            if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                    // Clean attribute key (remove "attribute_").
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    // Get the term by its name in the corresponding taxonomy.
                    $term = get_term_by('name', $attribute_value, $taxonomy);
                    if ($term) {
                        // Get the custom term quantity meta.
                        $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                        if ($term_quantity) {
                            // Adjust quantity based on the term quantity.
                            $adjust_quantity = $quantity_difference * (int)$term_quantity;
                        }
                    }
                }
            }
            // Adjust stock based on the calculated adjustment quantity.
            if ($adjust_quantity > 0) {
                $this->adjust_stock($parent_id, -$adjust_quantity);
            } else {
                $this->adjust_stock($parent_id, abs($adjust_quantity));
            }
	// Update the saved quantity for the specific cart item.
            WC()->cart->cart_contents[$cart_item_key]['saved_cart_quantity'] = $new_quantity;
        }
        // Save the cart session after modifications.
        WC()->cart->set_session();
        WC()->cart->calculate_totals();
    }

    public function add_stock_reservation_time_setting($settings)
    {
        foreach ($settings as $index => $setting) {
            if (isset($setting['id']) && $setting['id'] === 'woocommerce_hold_stock_minutes') {
                $settings = array_merge(
                    array_slice($settings, 0, $index + 1),
                    [
                        [
                            'name'     => __('Stock Reservation Time in the cart (minutes)', 'woocommerce'),
                            'desc'     => __('Set the time (in minutes) to reserve stock in the cart.', 'woocommerce'),
                            'id'       => 'stock_reservation_time',
                            'type'     => 'number',
                            'desc_tip' => true,
                            'default'  => 20,
                            'custom_attributes' => ['min' => 1],
                            'css'      => 'width: 100px;',
                        ],
                    ],
                    array_slice($settings, $index + 1)
                );
                break;
            }
        }
        return $settings;
    }

    public function save_stock_reservation_time_setting()
    {
        if (isset($_POST['stock_reservation_time'])) {
            update_option('stock_reservation_time', sanitize_text_field($_POST['stock_reservation_time']));
        }
    }

    public function store_reservation_time_in_cart($cart_item_data, $product_id)
    {
        $reservation_time = get_option('stock_reservation_time', 20);
        if ($reservation_time) {
            $cart_item_data['reservation_expiration_time'] = time() + ($reservation_time * 60);
        }
        
        return $cart_item_data;
    }

    public function check_cart_item_expiration() {
        error_log('Checking cart item expiration');
    
        // Check if the cart exists and is not empty
        if (!WC()->cart || WC()->cart->is_empty()) {
            error_log('Cart is empty or unavailable');
            wp_send_json_success('Cart is empty or not available.');
            return;
        }
    
        $cart_items_to_remove = [];
        $items_removed = false;
        // Iterate through cart items
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
           
            // Check expiration condition
            if (isset($cart_item['reservation_expiration_time']) && $cart_item['reservation_expiration_time'] < time()) {
                error_log('Processing expired item: ' . print_r($cart_item, true));
    
                // Process only unprocessed items
                if (empty($cart_item['processed_expired'])) {
                    $cart_item['processed_expired'] = true; // Mark as processed
                   
                    if (WC()->cart->get_cart_item($cart_item_key)) {
                        error_log("Cart item exists for key: $cart_item_key");
                        $remaining_time = $cart_item['reservation_expiration_time'] - time();

                        // Remove item if reservation time has expired
                        if ($remaining_time <= 0) {
                            WC()->cart->remove_cart_item($cart_item_key);
                            $items_removed = true;
                            $this->adjust_stock_for_expired_cart_item($cart_item);
                        }
                        
                    } else {
                        error_log("Cart item not found for key: $cart_item_key");
                    }
                    // Handle stock adjustment
                    $cart_items_to_remove[] = $cart_item_key; // Mark for removal
                }
            }
        }
    
         // Return response
        if ($items_removed) {
            WC()->cart->calculate_totals();
            WC()->cart->set_session();
            wp_send_json_success(['message' => __('Expired items have been removed from your cart.', 'woocommerce')]);
        } else {
            wp_send_json_success(['message' => __('No expired items found in your cart.', 'woocommerce')]);
        }
        // Refresh the cart to reflect changes
        WC()->cart->set_session(); // Save the cart session
        WC()->cart->calculate_totals(); // Recalculate totals
    
        wp_send_json_success('Cart processed successfully.');
    }
    

    public function remove_expired_cart_items() {
        $cart = WC()->cart->get_cart();

        foreach ($cart as $cart_item_key => $cart_item) {
            if (isset($cart_item['reservation_expiration_time'])) {
                $remaining_time = $cart_item['reservation_expiration_time'] - time();

                // Remove item if reservation time has expired
                if ($remaining_time <= 0) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    wc_add_notice(__('An item has been removed from your cart as its reservation time has expired.', 'woocommerce'), 'notice');
                }
            }
        }
    }

    // Centralized stock adjustment logic to avoid redundancy
private function adjust_stock_for_expired_cart_item($cart_item)
{
    if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
        foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
            $taxonomy = str_replace('attribute_', '', $attribute_key);
            $term = get_term_by('name', $attribute_value, $taxonomy);
            if ($term) {
                $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                $this->adjust_stock($cart_item['product_id'], $adjust_quantity);
            }
        }
    } else {
        $this->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
    }
}
    public function display_remaining_reservation_time($item_data, $cart_item)
{
    if (isset($cart_item['reservation_expiration_time'])) {
        $remaining_time = $cart_item['reservation_expiration_time'] - time();
        
        if ($remaining_time > 0) {
            // Check if the "Reservation Time" key already exists
            $key_exists = false;
            foreach ($item_data as $data) {
                if ($data['key'] === __('This item will be reserved shortly', 'woocommerce')) {
                    $key_exists = true;
                    break;
                }
            }

            // Add "Reservation Time" only if it doesn't already exist
            if (!$key_exists) {
                $item_data[] = [
                    'key'   => __('This item will be reserved shortly', 'woocommerce'),
                    'value' => sprintf('<span class="reservation-timer" data-remaining-time="%d"></span>', $remaining_time),
                ];
            }
        }
    }

    return $item_data;
}

    public function reduce_stock_on_add($cart_item_key, $product_id)
    {
        // Retrieve the cart item using the cart item key
        $cart_item = WC()->cart->get_cart_item($cart_item_key);        
        if (!$cart_item) {
            return;
        }
        // Get the cart quantity
        $cart_quantity = $cart_item['quantity'];
        // Check if the saved_cart_item key does not exist        
        if(!isset($cart_item['saved_cart_item'])){
            // Check if the cart item has a variation
            if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {                
                foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                    // Clean attribute key (remove "attribute_")
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    // Get the term by its name in the corresponding taxonomy
                    $term = get_term_by('name', $attribute_value, $taxonomy);
                    if ($term) {
                        $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                        $adjust_quantity = $cart_quantity * (int)$term_quantity;
                        $this->adjust_stock($product_id, -$adjust_quantity);
                        return; // Exit after adjusting stock
                    }
                }
            } else {
                // If no variation, adjust stock based on cart quantity directly
                $this->adjust_stock($product_id, -$cart_quantity);
            }
        }
    }

    public function restore_stock_on_remove()
    {
         // Validate nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woocommerce-cart')) {
       // wp_send_json_error(['error' => 'Invalid nonce.']);
    }
 
    // Get POST data
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
 
    if (!$product_id || !$cart_item_key) {
        wp_send_json_error(['error' => 'Invalid data provided.']);
    }
 
    // Access the cart instance
    $cart = WC()->cart;
    $cart_item = $cart->get_cart()[$cart_item_key] ?? null;
 
    if (!$cart_item) {
        wp_send_json_error(['error' => 'Cart item not found.']);
    }
 
    // Check if the product manages stock
    $product = wc_get_product($product_id);
    if ($product && $product->managing_stock()) {
        // Determine restore quantity
        $restore_quantity = 0;
 
        // Handle variations if applicable
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                // Clean attribute key (remove "attribute_")
                $taxonomy = str_replace('attribute_', '', $attribute_key);
                // Get the term by its name in the corresponding taxonomy
                $term = get_term_by('name', $attribute_value, $taxonomy);
 
                if ($term) {
                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                    $restore_quantity += $cart_item['quantity'] * (int) $term_quantity;
                }
            }
        } else {
            // Use default quantity for simple products
            $restore_quantity = $cart_item['quantity'];
        }
         // Adjust stock
        $this->adjust_stock($product_id, $restore_quantity);
         
        // Remove the item from the cart
        $cart->remove_cart_item($cart_item_key);
        // Recalculate cart totals
        $cart->calculate_totals();
        do_action('woocommerce_cart_updated');
        // Return success message
        wp_send_json_success(['message' => 'Item removed and stock restored successfully.']);
    } else {
        wp_send_json_error(['error' => 'Product does not manage stock or is invalid.']);
    }
 
    }
     

    // Helper function to adjust stock
    public function adjust_stock($product_id, $quantity_change) {
    // Get the product object using the product ID
    $product = wc_get_product($product_id);
    
    if ($product && $product->managing_stock()) {
        // Get the current stock quantity of the product 
     // $current_stock = $product->get_stock_quantity();
      $current_stock=  get_post_meta($product_id, '_held_stock', true) ?: 0;  
        // If stock is managed, adjust the stock based on the quantity change
        $new_stock = $current_stock + $quantity_change;

        // Only update the stock if the new stock is different from the current stock
        if ($current_stock !== $new_stock) {
            
        $new_held_stock = $current_stock - $quantity_change;
    
        // Ensure held stock doesn't go below zero
        $new_held_stock = max(0, $new_held_stock);
    
        // Update the held stock meta
        update_post_meta($product_id, '_held_stock', $new_held_stock);
            error_log("Stock updated for Product ID {$product_id}: New Stock = {$new_stock}, Change = {$quantity_change}");
        }
         
    } else {
        // Log if stock is not managed or product doesn't exist
        error_log("Stock not updated for Product ID {$product_id}: Product does not manage stock.");
    }
   
}

    
 
}

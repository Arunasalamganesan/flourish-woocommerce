<?php
namespace FlourishWooCommercePlugin\CustomFields;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;
use FlourishWooCommercePlugin\API\FlourishAPI;

class License
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

     

    /**
     * Registers all hooks for adding, validating, and saving the custom License field.
     */
    public function register_hooks()
    {
        add_action('enqueue_block_assets', [$this, 'enqueue_custom_checkout_fields']);
        add_filter('woocommerce_checkout_fields', [$this, 'add_billing_shipping_field_to_checkout_form']);
         add_action('woocommerce_checkout_update_order_meta', [$this, 'save_billing_shipping_field']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_shipping_phone_license_in_admin']);
        // Hook to add license fields in the user profile
        add_action( 'show_user_profile', [ $this, 'add_license_field_to_user_edit' ] );
        add_action( 'edit_user_profile', [ $this, 'add_license_field_to_user_edit' ] );
         // Register REST API endpoint for get License
         add_action('rest_api_init', [$this, 'register_get_license_endpoint']); 
         add_action('wp_ajax_update_user_licenses', [$this, 'update_user_licenses_callback']);
         add_filter( 'woocommerce_shipping_fields', [$this,'remove_shipping_name_fields']);
         add_action('wp_ajax_ship_destination_from_flourish',[$this, 'ship_destination_from_flourish']);
         // Always display shipping address fields and remove "Ship to a different address?" checkbox
          add_filter( 'woocommerce_cart_needs_shipping_address', '__return_true' );
          add_action( 'wp_head', [$this, 'hide_ship_to_different_address_checkbox']);
          add_action( 'woocommerce_before_checkout_shipping_form',[$this,'add_shipping_details_heading' ],10 );
           // Adding the filter
           add_filter('woocommerce_checkout_get_value', [$this, 'filter_checkout_get_value'], 10, 2);


    }
    
    public function filter_checkout_get_value($input, $key) {
        $fields_to_clear = [ 
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_phone',
            'billing_country', 
            'shipping_company',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_state',
            'shipping_postcode',
            'shipping_country',
            'shipping_phone',
             
        ];

        if (in_array($key, $fields_to_clear)) {
            return ''; // Clear the value for specified fields
        }

        return $input; // Return the original value for other fields
    }
 
public function add_shipping_details_heading() {
    echo '<h4>' . __( 'Shipping Details', 'woocommerce' ) . '</h4>';
}
   public function display_shipping_phone_license_in_admin( $order ) { 
    $shipping_phone = get_post_meta( $order->get_id(), '_shipping_phone', true );
    $license_number = get_post_meta( $order->get_id(), 'license', true );
 
    if ( $shipping_phone ) {
        echo '<p><strong>' . __( 'Shipping Phone:', 'woocommerce' ) . '</strong> ' . esc_html( $shipping_phone ) . '</p>';
    }
    if ( $license_number ) {
        echo '<p><strong>' . __( 'License Number:', 'woocommerce' ) . '</strong> ' . esc_html( $license_number ) . '</p>';
    }
}
// Hide the "Ship to a different address?" checkbox using CSS
    public function hide_ship_to_different_address_checkbox() {
        echo '<style>
            .woocommerce-shipping-fields__field-wrapper,.shipping_address {
                display: block !important;
            }
           .woocommerce-shipping-fields__field-wrapper .woocommerce-shipping-fields h3,
        .woocommerce-shipping-fields .woocommerce-form__input-checkbox,#ship-to-different-address {
                display: none !important;
            }
        </style>';
    }
    

    public function  remove_shipping_name_fields( $fields ) {
        unset( $fields['shipping_first_name'] );
        unset( $fields['shipping_last_name'] );
        return $fields;
    }
    public function ship_destination_from_flourish()
    {
        if (empty($_POST['license'])) {
        wp_send_json_error(['message' => 'License value is missing']);
        return;
        }

        $api_key = $this->existing_settings['api_key'] ?? '';
        $username = $this->existing_settings['username'] ?? '';
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        // Initialize API
        $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id);

        try {
        $existing_destination = $flourish_api->fetch_destination_by_license($_POST['license']);
        if ($existing_destination && is_array($existing_destination)) {
            wp_send_json_success(['data' => $existing_destination]);
        } else {
            wp_send_json_error(['message' => 'No destination found for License: ' . $_POST['license']]);
        }
        } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Error fetching destination: ' . $e->getMessage()]);
        }
    }


       
    public function register_get_license_endpoint()
    {
        register_rest_route('custom-endpoint', '/get-license', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_license_via_rest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [
                    'required'          => true,
                    'validate_callback' => function ($param, $request, $key) {
                        return is_email($param); // Validate email format
                    },
                ],
            ],
        ]); 
    }

    public function get_license_via_rest(\WP_REST_Request $request)
    {
    $email = $request->get_param('email');

    // Validate email
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!$email || !is_email($email)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Invalid email address provided',
        ], 400);
    }

    // Fetch user by email
    $user = get_user_by('email', $email);
    if (!$user) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'User not found for the provided email',
        ], 404);
    }

    // Fetch license data from user meta
    $licenses = get_user_meta($user->ID, 'license', true); // Unserialize happens automatically
    if ($licenses && is_array($licenses)) {
        return new WP_REST_Response([
            'status'   => 'success',
            'licenses' => $licenses, // Return as array
        ], 200);
    }

    return new WP_REST_Response([
        'status'  => 'error',
        'message' => 'No License found for the given email',
    ], 404);
    }

    
    /**
     * / Ensure it only loads on WooCommerce checkout pages
     */
    public function enqueue_custom_checkout_fields()
    {
        
        if (function_exists('is_checkout') && is_checkout()) {
            wp_enqueue_script('jquery'); // Ensure jQuery is loaded
            
            wp_enqueue_script(
                'custom-checkout-license',
                plugin_dir_url(__DIR__) . '../assets/js/custom-checkout-license.js', // Adjust path
                ['wp-hooks', 'wc-blocks-checkout'], // Dependencies
                '1.0',
                true
            ); 
            wp_enqueue_style(
                'custom-checkout-fields-style',
                plugin_dir_url(__DIR__) . '../assets/css/style.css', // Adjusted path
                array(),
                '1.0.0'
            ); 
            wp_localize_script(
                'custom-checkout-license',
                'licenseData',
                [
                    'getApiUrl' => esc_url_raw(rest_url('custom-endpoint/get-license')),
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('license_management_nonce'),
                ]
            );
             // Adding the loading spinner HTML to the page dynamically
            add_action('wp_footer', function() {
                if (is_checkout()) {
                    echo '<div id="loading_overlay" style="display: none;">
                <div class="spinner"></div>
                <div class="loading-message">Please wait... Don\'t refresh the page.</div>
              </div>';
                }
            });

        }

    }

    
    
    /**
     * Saves the license & shipping phone field as user meta during customer creation.
     */
    public function save_billing_shipping_field($order_id)
    {
        if (isset($_POST['license'])) {
            update_post_meta($order_id, 'license', sanitize_text_field($_POST['license']));
        }
         if ( ! empty( $_POST['shipping_phone'] ) ) {
            update_post_meta( $order_id, '_shipping_phone', sanitize_text_field( $_POST['shipping_phone'] ) );
        }
    }
        
   
    /**
     * Adds the license & shipping phone field to the checkout form.
     */
    public function add_billing_shipping_field_to_checkout_form($fields)
    {
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $licenses = get_user_meta($user_id, 'license', true);
            $licenses = maybe_unserialize($licenses);
    
            if (!is_array($licenses) || empty($licenses)) {
                // Add a hidden license field to avoid validation errors
                $fields['billing']['license'] = array(
                    'label'       => __('License', 'woocommerce'),
                    'type'        => 'hidden',
                    'required'    => true,
                    'class'       => array('form-row-wide'),
                    'priority'    => 8,
                );
    
                // Add a custom error notice
                add_action('woocommerce_before_checkout_billing_form', function () {
                    echo '<div class="license-warning" style="color: red; font-weight: bold; margin-top: 10px;">' .
                         __('No license numbers available. You cannot place an order without a valid license.', 'woocommerce') .
                         '</div>';
                });
    
                // Block checkout submission with an error notice
                add_action('woocommerce_checkout_process', function () {
                    wc_add_notice(__('No license numbers available. You cannot place an order without a valid license.', 'error'));
                });
            } 
            else            
            {

                    if (isset($fields['billing'])) {
                                // Licenses available - display a dropdown
                        $license_options = array('' => __('Select your license', 'woocommerce'));
                        foreach ($licenses as $license) {
                            $license_options[$license] = $license;
                        }
                
                        $fields['billing']['license'] = array(
                            'label'       => __('License', 'woocommerce'),
                            'type'        => 'select',
                            'required'    => true,
                            'class'       => array('form-row-wide'),
                            'options'     => $license_options,
                            'priority'    => 8,
                        );
                
                        // Adjust Billing Field Priorities and Placeholders
                        $billing_modifications = [
                            'billing_email'      => ['priority' => 9],
                            'billing_address_1'  => ['placeholder' => 'Address 1'],
                            'billing_address_2'  => ['placeholder' => 'Address 2'],
                            'billing_country'    => ['type' => 'text'],
                            'billing_state'      => ['type' => 'text']
                        ];
                
                        foreach ($billing_modifications as $key => $modification) {
                            if (isset($fields['billing'][$key])) {
                                $fields['billing'][$key] = array_merge($fields['billing'][$key], $modification);
                            }
                        }
                
                        // Make all Billing Fields Readonly Except Specified Fields
                        foreach ($fields['billing'] as $key => $field) {
                            if (!in_array($key, ['billing_first_name', 'billing_last_name', 'billing_phone'])) {
                                $fields['billing'][$key]['custom_attributes'] = ['readonly' => 'readonly'];
                            }
                        }
                    }
                
                   // Adjust Shipping Fields
                    if (isset($fields['shipping'])) {
                        $shipping_modifications = [
                            'shipping_address_1' => ['placeholder' => 'Address 1'],
                            'shipping_address_2' => ['placeholder' => 'Address 2'],
                            'shipping_country'   => ['type' => 'text'],
                            'shipping_state'     => ['type' => 'text']
                        ];

                        foreach ($shipping_modifications as $key => $modification) {
                            if (isset($fields['shipping'][$key])) {
                                $fields['shipping'][$key] = array_merge($fields['shipping'][$key], $modification);
                            }
                        }

                        // Add Company Phone Field
                        $fields['shipping']['shipping_phone'] = array(
                            'type'        => 'tel',
                            'label'       => __('Company Phone', 'woocommerce'),
                            'placeholder' => __('Company phone', 'woocommerce'),
                            'required'    => true,
                            'priority'    => 91,
                        );

                        // Make all Shipping Fields Readonly Except Specified Fields
                        foreach ($fields['shipping'] as $key => $field) {
                            if (!in_array($key, ['shipping_phone'])) {
                                $fields['shipping'][$key]['custom_attributes'] = ['readonly' => 'readonly'];
                            }
                        }
                    }
            }
          }
    
        return $fields;
    }
    
    // Add checkout validation to block orders if no license is selected
    public function check_license_field_to_checkout_process()
    {
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $licenses = get_user_meta($user_id, 'license', true);
            $licenses = maybe_unserialize($licenses);
    
            if (empty($licenses)) {
                wc_add_notice(__('No license numbers available. You cannot place an order without a valid license.', 'woocommerce'), 'error');
            }
        }
    }


    /**
     * Adds the license field to the WordPress user profile edit form.
     */

     function add_license_field_to_user_edit($user) {
        // Fetch licenses from user meta
        $licenses = get_user_meta($user->ID, 'license', true);
        $licenses = !empty($licenses) && is_array($licenses) ? $licenses : []; // Ensure it's an array
        ?>
        <h3><?php esc_html_e('License Management', 'woocommerce'); ?></h3>
        <table class="form-table">
            <!-- Add Licenses -->
            <tr>
                <th><label for="new_license"><?php esc_html_e('Add/Edit License', 'woocommerce'); ?></label></th>
                <td>
                    <input type="text" max="15" name="new_license" id="new_license" class="regular-text" placeholder="<?php esc_attr_e('Enter or edit license number', 'woocommerce'); ?>" required />
                    <button type="button" id="add-update-license" class="button button-primary"><?php esc_html_e('Submit', 'woocommerce'); ?></button>
                     <button type="button" id="delete-license" class="button button-secondary" style="display: none;"><?php esc_html_e('Delete License', 'woocommerce'); ?></button>
                    <button type="button" id="remove-edit-section" class="button" style="display: none;color: red;"><?php esc_html_e('Cancel', 'woocommerce'); ?></button>
                    <p class="description"><?php esc_html_e('Select a license to edit or enter a new one to add.', 'woocommerce'); ?></p>
                </td>
                 
            </tr>
        
         
            <!-- Manage Licenses -->
            <tr id="manage-license-row" style="<?php if (!empty($licenses)) { echo "block";} else { echo "display:none;";}?>">
                    <th><label for="license"><?php esc_html_e('Manage Licenses', 'woocommerce'); ?></label></th>
                    <td>
                    <!-- License Dropdown -->
                    <select name="license" id="license" class="regular-text">
                    <option value=""><?php esc_html_e('Select a license to edit or delete', 'woocommerce'); ?></option>
                    <?php foreach ($licenses as $license) : ?>
                    <option value="<?php echo esc_attr($license); ?>">
                    <?php echo esc_html($license); ?>
                    </option>
                    <?php endforeach; ?>
                    </select>
                    </td>
            </tr> 
        </table>
    
        <?php
    }
    
    /**
     * Save licenses on user update.
     */

     public function update_user_licenses_callback() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
    
        // Check required parameters
        if (!isset($_POST['licenses']) ) {
            wp_send_json_error(['message' => 'Missing parameters']);
            return;
        }
    
        // Sanitize and validate
        $user_id = intval($_POST['user_id']);
        $new_licenses = array_map('sanitize_text_field', (array)$_POST['licenses']);
        $action = sanitize_text_field($_POST['function_action']);
    
        if (!$user_id || empty($new_licenses)) {
            wp_send_json_error(['message' => 'Invalid user ID or licenses']);
            return;
        }
    
        // Fetch existing licenses from user meta
        $existing_licenses = get_user_meta($user_id, 'license', true);
        $existing_licenses = is_array($existing_licenses) ? $existing_licenses : [];
    
        if ($action === 'delete') {
            // Delete license
            if (($key = array_search($new_licenses[0], $existing_licenses, true)) !== false) {
                unset($existing_licenses[$key]);
                $existing_licenses = array_values($existing_licenses); // Reindex array after deletion
                update_user_meta($user_id, 'license', $existing_licenses);
                wp_send_json_success(['message' => 'License deleted successfully', 'licenses' => $existing_licenses]);
            } else {
                wp_send_json_error(['message' => 'License not found']);
            }
        } else {
            $licenses_added = [];
$licenses_updated = [];
$licenses_to_edit = isset($_POST['licenses_to_edit']) ? $_POST['licenses_to_edit'] : null; // License to edit (old value)

foreach ($new_licenses as $new_license) {
    // Check if it's an edit operation
    if ($licenses_to_edit) {
        $key = array_search($licenses_to_edit, $existing_licenses, true); // Find index of old license
        if ($key !== false) {
            $existing_licenses[$key] = $new_license; // Replace old license with new one
            $licenses_updated[] = $new_license;
        } else {
            wp_send_json_error(['message' => 'License to edit does not exist.']);
            return;
        }
    } else { // Handle adding new licenses
        if (!in_array($new_license, $existing_licenses, true)) {
            $existing_licenses[] = $new_license;
            $licenses_added[] = $new_license;
        }
    }
}

// If no licenses were added or updated
if (empty($licenses_added) && empty($licenses_updated)) {
    wp_send_json_error(['message' => 'No changes were made.']);
    return;
}

// Update user meta with the modified licenses
update_user_meta($user_id, 'license', $existing_licenses);

wp_send_json_success([
    'message' => 'Licenses updated successfully',
    'licenses' => $existing_licenses,
    'added_licenses' => $licenses_added,
    'updated_licenses' => $licenses_updated,
]);
        }
    }
   
    

}  
       
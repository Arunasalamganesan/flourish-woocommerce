<?php

namespace FlourishWooCommercePlugin;

defined( 'ABSPATH' ) || exit;

use FlourishWooCommercePlugin\Services\ServiceProvider;


class FlourishWooCommercePlugin
{
    private static $instance;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init($plugin_basename)
    {
        register_activation_hook(plugin_basename(__FILE__), [$this, 'activate']);
        register_deactivation_hook(plugin_basename(__FILE__), [$this, 'deactivate']);
        register_uninstall_hook(plugin_basename(__FILE__), [$this, 'uninstall']);

        $existing_settings = get_option('flourish_woocommerce_plugin_settings');

        if (!$existing_settings) {
            $existing_settings = [];
        }

        // Register services
        $service_provider = new ServiceProvider($existing_settings, $plugin_basename);
        $service_provider->register_services();

       // Add our JavaScript
        add_action('admin_enqueue_scripts', function($hook) {
            // Enqueue the general plugin JavaScript
            wp_enqueue_script(
                'flourish-woocommerce-plugin', 
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/flourish-woocommerce-plugin.js', 
                ['jquery'], 
                '1.0.0', 
                true
            );

            wp_enqueue_script(
                'case-size-js',  
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/flourish-custom-script.js', 
                ['jquery'], 
                '1.0.0', 
                 true
            );

            wp_localize_script('case-size-js', 'ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('case_size_nonce'),
                'deleteNonce' => wp_create_nonce('delete_case_size_nonce'),
            ]);

            // Enqueue scripts only on user profile pages
            if ('profile.php' === $hook || 'user-edit.php' === $hook) {
                wp_enqueue_script(
                    'license-management', 
                    plugin_dir_url(dirname(__FILE__)) . 'assets/js/license-management-outbound.js', 
                    ['jquery'], 
                    '1.0.0', 
                    true
                );

                wp_localize_script('license-management', 'licenseData', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('license_management_nonce'),
                ]);
            }
        });

        add_action('wp_enqueue_scripts', function($hook) {
            // Enqueue custom styles (for front-end)
            wp_enqueue_style(
                'save-cart-style',  // Make sure you are using a unique handle
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/save-cart-style.css', 
                [], // No dependencies
                '1.0.0', // Version
                'all' // Media type
            );
            wp_enqueue_script(
                'reservation-cart-js',  
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/reservation-cart.js', 
                ['jquery'], 
                '1.0.0', 
                 true
            );
            // In PHP (add nonce to localize script)
            wp_localize_script('cart-reservation-script', 'stockAvailability', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cart_reservation_nonce')
            ]);
            wp_enqueue_script(
                'add-to-cart-stock-validation',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/flourish-custom-script.js',
                array('jquery'), // Dependencies if required
                '1.0',
                true
            );   

            wp_localize_script('add-to-cart-stock-validation', 'stockAvailability', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('stock_availability_nonce'),
            ]);
        });
    }
    public function activate()
    {
     
    }

    public function deactivate()
    {
    
    }

    public function uninstall()
    {
    }
}

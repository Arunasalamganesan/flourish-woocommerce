<?php

namespace FlourishWooCommercePlugin\API;

use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;
use WP_REST_Request; // Import the global WP_REST_Request class.
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class FlourishAPI 
{
    const API_LIMIT = 50;

    public $username;
    public $api_key;
    public $url;
    public $facility_id;
    public $auth_header;

    public function __construct($username, $api_key, $url, $facility_id)
    {
        $this->username = $username;
        $this->api_key = $api_key;
        $this->url = $url;
        $this->facility_id = $facility_id;
        $this->auth_header = base64_encode($username . ':' . $api_key);
    }
    /**
     * Fetches products based on optional brand filtering.
     *
     * This function retrieves products, with the option to filter results by specified brands.
     * If no filtering is applied, all available products are fetched.
     *
     * @param bool   $filter_brands Whether to filter products by brand. Defaults to false.
     * @param array  $brands        An array of brand names or IDs to filter by. Defaults to an empty array.
     * @return array An array of fetched products based on the given filters.
     */
    public function fetch_products($filter_brands = false, $brands = [])
    {
        $products = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_products = true;

        while ($has_more_products) {
            $api_url = $this->url . "/external/api/v1/items?active=true&ecommerce_active=true&offset={$offset}&limit={$limit}";

            $headers = [
                'Authorization: Basic ' . $this->auth_header,
            ];

            try
            {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching products: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $flourish_products = $response_data['data'];
                // Grab the inventory for all of them
                foreach ($flourish_products as $key => $flourish_product) {
                    // We need to check if this product belongs to one of the active brands
                    if ($filter_brands && !in_array($flourish_product['brand'], $brands)) {
                        unset($flourish_products[$key]);
                        continue;
                    }

                    $item_id = $flourish_product['id'];
                    $inventory_records = $this->fetch_inventory($item_id);
                    $inventory_quantity = 0;
                    foreach ($inventory_records as $inventory) {
                        // There are item variations sometimes so we'll get more than one inventory record back
                        // for a single item. We only want the one that matches the SKU.
                        if ($inventory['sku'] === $flourish_product['sku']) {
                            $inventory_quantity = $inventory['sellable_qty'];
                            break;
                        }
                    }

                    $flourish_products[$key]['inventory_quantity'] = $inventory_quantity;
                }
                $products = array_merge($products, $flourish_products);
            }

            $has_more_products = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $products;
    }

    public function fetch_facilities()
    {
        $facilities = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_facilities = true;

        while ($has_more_facilities) {
            $api_url = $this->url . "/external/api/v1/facilities?offset={$offset}&limit={$limit}";

            $headers = [
                'Authorization: Basic ' . $this->auth_header,
            ];

            // Use the HttpRequestHelper for the API call
            
            try
            {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching facility: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $facilities = array_merge($facilities, $response_data['data']);
            } 
            $has_more_facilities = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $facilities;
    }
    /**
     * Fetch factility by facility_id
     */
    public function fetch_facility_config($facility_id)
    {
        $facility_config = false;

        $api_url = $this->url . "/external/api/v1/facilities/{$facility_id}";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
        ];

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching facility config: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $facility_config = $response_data['data'];
        } 

        return $facility_config;
    }
    /**
     * Fetch inventory
     */
    public function fetch_inventory($item_id)
    {
        $api_url = $this->url . "/external/api/v1/inventory/summary?item_id=$item_id";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
            'FacilityID: ' . $this->facility_id,
        ];

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching inventory: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            return $response_data['data'];
        } 
    }
    /**
     * Get or create a new customer using the provided customer data.
     */
    public function get_or_create_customer_by_email($customer)
    {
        $api_url = $this->url . "/external/api/v1/customers?email=" . urlencode($customer['email']);
        $headers = [
            'Authorization: Basic ' . $this->auth_header,
        ];

        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching inventory: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            if (count($response_data['data'])) {
                $customer['flourish_customer_id'] = $response_data['data'][0]['id'];
            } else {
                // No customer found, create a new one
                return $this->create_customer($customer, $headers);
            }
        } else {
            throw new \Exception('Invalid API response format.');
        }

        return $customer;
    }

    /**
     * Create a new customer using the provided customer data.
     */
    private function create_customer($customer, $headers)
    {
        // Check Date of Birth
        if (empty($customer['dob'])) {
            wc_add_notice(__('Date of Birth is required. Please update your account details.', 'woocommerce'), 'error');
            throw new \Exception('Date of Birth is required. Please update your account details.');
        }

        // Prepare the URL and headers for the POST request
        $api_url = $this->url . "/external/api/v1/customers";
        $headers[] = 'Content-Type: application/json';

        try {
            // Use HttpRequestHelper to create a customer
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($customer));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching sales rep: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $customer['flourish_customer_id'] = $response_data['data']['id'];
        } 

        return $customer;
    }

    public function create_retail_order($order) {
        $api_url = $this->url . "/external/api/v2/retail-orders";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
            'FacilityID: ' . $this->facility_id,
            'Content-Type: application/json',
        ];

        try {
            // Use HttpRequestHelper to create a retail order
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching retail order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 
        return $response_data['data']['id'];
    }

    public function create_outbound_order($order) {
        // No customer yet, let's create one
        $api_url = $this->url . "/external/api/v1/outbound-orders";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
            'FacilityID: ' . $this->facility_id,
            'Content-Type: application/json',
        ];

        try {
            // Use HttpRequestHelper to create a outbound order
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching outbound order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 

        return $response_data['data']['id'];
    }

    public function fetch_brands()
    {
        $brands = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_brands = true;

        while ($has_more_brands) {
            $api_url = $this->url . "/external/api/v1/brands?offset={$offset}&limit={$limit}";

            $headers = [
                'Authorization: Basic ' . $this->auth_header,
            ];

            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching brands: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $brands = array_merge($brands, $response_data['data']);
            } 
            $has_more_brands = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $brands;
    }

    public function fetch_sales_reps()
    {
        $sales_reps = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_sales_reps = true;

        while ($has_more_sales_reps) {
            $api_url = $this->url . "/external/api/v1/sales-reps?offset={$offset}&limit={$limit}";

            $headers = [
                'Authorization: Basic ' . $this->auth_header,
            ];
            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching sales rep: " . $e->getMessage());
            }
             
            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $sales_reps = array_merge($sales_reps, $response_data['data']);
            } 

            $has_more_sales_reps = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $sales_reps;
    }

    public function fetch_destination_by_license($license)
    {
       // $license_value = isset($license[0]) ? $license[0] : $license;
       $license_value = $license;
        $api_url = $this->url . "/external/api/v1/destinations?license_number=" . urlencode($license_value);

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
        ];

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching destination by lincense: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            if (count($response_data['data'])) {
                return $response_data['data'][0];
            }
        } 
        return false;
    }

    public function fetch_uoms()
    {
        $brands = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_uoms = true;

        while ($has_more_uoms) {
            $api_url = $this->url . "/external/api/v1/uoms?offset={$offset}&limit={$limit}";

            $headers = [
                'Authorization: Basic ' . $this->auth_header,
            ];

            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching UOMS: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $uoms = array_merge($brands, $response_data['data']);
            } 
            $has_more_uoms = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $uoms;
   }
   /**
     * Get the order by id.
     */
    public function get_order_by_id($order_id,$order_type_api)
    {
        $api_url = $this->url . "/external/api/v1/{$order_type_api}/{$order_id}";
        $headers = [
            'Authorization: Basic ' . $this->auth_header,
        ];

        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching get order by id: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order_data = $response_data['data'];
        } else {
            throw new \Exception('Invalid API response format.');
        }

        return $order_data;
    }
    /**
     * Update a existing outbound order
     */
    public function update_outbound_order($order,$flourish_order_id) {
        // No customer yet, let's create one
        $api_url = $this->url . "/external/api/v1/outbound-orders/{$flourish_order_id}";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
            'FacilityID: ' . $this->facility_id,
            'Content-Type: application/json',
        ];

        try {
            // Use HttpRequestHelper to create a outbound order
            $response_http = HttpRequestHelper::make_request($api_url, 'PUT', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching outbound order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 

        return $response_data['data']['id'];
    }
    public function update_retail_order($order,$flourish_order_id) {
        $api_url = $this->url . "/external/api/v1/retail-orders/{$flourish_order_id}";

        $headers = [
            'Authorization: Basic ' . $this->auth_header,
            'FacilityID: ' . $this->facility_id,
            'Content-Type: application/json',
        ];

        try {
            // Use HttpRequestHelper to create a retail order
            $response_http = HttpRequestHelper::make_request($api_url, 'PUT', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching retail order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 
        return $response_data['data']['id'];
    }
}
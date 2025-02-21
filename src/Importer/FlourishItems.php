<?php
 
namespace FlourishWooCommercePlugin\Importer;
 
defined('ABSPATH') || exit;
 
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Attribute;
 
class FlourishItems
{
    public $items = [];
 
    public function __construct($items)
    {
        $this->items = $items;
    }
 
    /**
     * Map Flourish items to WooCommerce products.
     *
     * @return array
     * @throws \Exception
     */
    public function map_items_to_woocommerce_products()
    {
        if (!count($this->items)) {
            throw new \Exception("No items to map.");
        }
 
        return array_map([$this, 'map_flourish_item_to_woocommerce_product'], $this->items);
    }
 
    /**
     * Save items as WooCommerce products.
     *
     * @param array $item_sync_options Options to determine which fields to update.
     * @return int Number of products imported or updated.
     */
    public function save_as_woocommerce_products($item_sync_options = [])
    {
        $imported_count = 0;
        $products = $this->map_items_to_woocommerce_products();
 
        foreach ($products as $product) {
            if (!strlen($product['sku'])) {
                continue;
            }
 
            $wc_product = $this->get_existing_or_new_product($product['sku'], $product['uom']);
            
            $product_id = $this->update_product_attributes($wc_product, $product, $item_sync_options);
           
            if (!empty($product['item_category'])) {
                $this->assign_product_category($product['item_category'], $product_id);
            }
             
 
            do_action('flourish_item_imported', $product, $product_id);
 
            if ($product_id > 0) {
                $imported_count++;
            }
        }
 
        return $imported_count;
    }
 
    private function get_existing_or_new_product($sku, $uom) {
        $product_id = wc_get_product_id_by_sku($sku); // Direct lookup by SKU
 
 
        if ($product_id) {
        $product = wc_get_product($product_id);
 
        if ($product) {
            if ($product->is_type('variation')) {
                return wc_get_product($product->get_parent_id());
            }
            return $product;
        }
 
        error_log("Invalid product for SKU: " . $sku);
        return null;
        }
 
        $is_variable = in_array('pa_' . sanitize_title($uom), array_map(fn($a) => 'pa_' . $a->attribute_name, wc_get_attribute_taxonomies()));
        $new_product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
 
        $new_product->set_sku($sku);
        $new_product->set_status('draft');
 
        if ($new_product->save()) {
        error_log("New product created with ID: " . $new_product->get_id());
        return $new_product;
        }
 
        error_log("Error saving new product for SKU: " . $sku);
        return null;
}
 
 
 
private function update_product_attributes($wc_product, $product, $item_sync_options) {
    $this->save_custom_fields_automated($wc_product, $product); // Keep if optimized
 
    foreach (['name', 'description', 'price'] as $field) {
        if (empty($item_sync_options[$field]) || $item_sync_options[$field]) {
            $setter = 'set_' . $field;
            $wc_product->$setter($product[$field]);
   
            if ($field === 'price') { // Ensure both price and regular price are set
                $wc_product->set_regular_price($product[$field]);
                $wc_product->update_meta_data('_price', $product[$field]);
            }
        }
    }
    $wc_product->set_sku($product['sku']);
 
    if (method_exists($wc_product, 'set_manage_stock')) {
        $wc_product->set_manage_stock(true);
    } else {
        $wc_product->update_meta_data('_manage_stock', 'yes');
    }
 
    $product_id = $wc_product->get_id();
    $reserved_stock = get_post_meta($product_id, '_reserved_stock', true); // No need for (int) cast here
    $flourish_stock = $product['inventory_quantity'];
    $woocommerce_stock = $flourish_stock - ($reserved_stock ? (int)$reserved_stock : 0); // Handle empty reserved stock
    $wc_product->set_stock_quantity($woocommerce_stock);
 
    $product_id = $wc_product->save(); // Save ONCE
 
    if ($wc_product->is_type('variable')) {
        $this->create_attributes($wc_product, $product); // Optimize if needed
        wc_delete_product_transients($wc_product->get_id());
    }
 
    return $product_id;
}
 
private function create_attributes($wc_product, $product) {
    $uom = get_post_meta($wc_product->get_id(), 'uom', true);
 
    if (empty($uom)) {
        update_post_meta($wc_product->get_id(), 'uom', $product['uom']); // Set UOM directly
        return;
    }
 
    $taxonomy = 'pa_' . sanitize_title($uom); // Directly build taxonomy name
    if (taxonomy_exists($taxonomy)) {
        $term_names = wp_list_pluck(get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]), 'slug');
 
        if (!empty($term_names)) {
            $product_attribute = new WC_Product_Attribute();
            $product_attribute->set_name($taxonomy);
            $product_attribute->set_options($term_names);
            $product_attribute->set_visible(true);
            $product_attribute->set_variation(true);
            $wc_product->set_attributes([$product_attribute]); // Set attributes directly (no loop)
            $wc_product->save(); // Save after setting attributes
 
            $this->generate_product_variations($wc_product->get_id(), [$taxonomy => $term_names]); // Pass simplified attributes data
        }
    }
 
    error_log('Attributes synced and variations created for product ID: ' . $wc_product->get_id());
}
 
 
 
private function generate_product_variations($product_id, $attributes_data) {
    $product = wc_get_product($product_id);
 
    if (!$product || !$product->is_type('variable') || $product->get_stock_status() === 'outofstock') {
        return; // Early exit if not variable, out of stock, or invalid product
    }
 
    foreach ($attributes_data as $taxonomy => $options) {
        if (!taxonomy_exists($taxonomy)) {
            $this->create_attribute_taxonomy($taxonomy);
        }
 
        foreach ($options as $option) {
            $option = trim($option);
            if (!empty($option) && !term_exists($option, $taxonomy)) {
                wp_insert_term($option, $taxonomy);
            }
        }
    }
 
    $combinations = $this->get_attribute_combinations($attributes_data);
 
    foreach ($combinations as $combination) {
        $variation_exists = false;
        foreach ($product->get_children() as $variation_id) { // Iterate through children directly
            $existing_variation = wc_get_product($variation_id);
            $attributes_match = true;
 
            foreach ($combination as $taxonomy => $term_name) {
                if (get_post_meta($variation_id, 'attribute_' . $taxonomy, true) !== $term_name) {
                    $attributes_match = false;
                    break;
                }
            }
 
            if ($attributes_match) {
                error_log("Variation already exists for combination: " . implode(', ', $combination));
                $variation_exists = true;
                break;
            }
        }
 
        if ($variation_exists) {
            continue;
        }
 
        $variation_id = wp_insert_post([
            'post_title' => $product->get_name() . ' - ' . implode(', ', $combination),
            'post_name' => 'product-' . $product_id . '-variation-' . sanitize_title(implode('-', $combination)),
            'post_status' => 'publish',
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
        ]);
 
        foreach ($combination as $taxonomy => $term_name) {
            update_post_meta($variation_id, 'attribute_' . $taxonomy, $term_name);
        }
 
        $custom_price_multiplier = 1;
        foreach ($combination as $taxonomy => $term_name) {
            if ($term = get_term_by('name', $term_name, $taxonomy)) {
                if ($quantity = get_term_meta($term->term_id, 'quantity', true)) {
                    $custom_price_multiplier *= (float)$quantity;
                }
            }
        }
 
        $variation_price = (float)$product->get_price() * $custom_price_multiplier;
        update_post_meta($variation_id, '_regular_price', $variation_price);
        update_post_meta($variation_id, '_price', $variation_price);
 
        $variation_stock = get_term_meta(get_term_by('name', $term_name, $taxonomy)->term_id, 'quantity', true); // Simplified stock lookup
        $stock_status = $product->get_stock_quantity() > 0 ? 'instock' : 'outofstock';
        update_post_meta($variation_id, '_stock_status', $stock_status);
        update_post_meta($variation_id, '_manage_stock', 'no');
        update_post_meta($variation_id, '_stock', $stock_status === 'instock' ? ($variation_stock ? (int)$variation_stock : 0) : 0);
 
        if ($index === 0) {
            update_post_meta($product_id, '_default_attributes', $combination);
        }
 
        $index++;
    }
 
    error_log("Variations successfully generated for product ID: $product_id");
}
    /**
     * Generate all possible combinations of attributes.
     *
     * @param array $attributes_data Attribute data in the format ['attribute_slug' => ['term1', 'term2']].
     * @return array An array of combinations where each combination is an associative array.
     */
    private function get_attribute_combinations($attributes_data)
    {
    $combinations = [[]]; // Start with an empty combination
 
    foreach ($attributes_data as $attribute => $terms) {
        $new_combinations = [];
 
        foreach ($combinations as $combination) {
            foreach ($terms as $term) {
                $new_combinations[] = array_merge($combination, [$attribute => $term]);
            }
        }
 
        $combinations = $new_combinations;
    }
 
    return $combinations;
    }
 
    /**
     * Create attribute taxonomy if it doesn't exist
     */
    private function create_attribute_taxonomy($taxonomy)
    {
        // Create the taxonomy for the attribute if it doesn't exist
        $args = array(
            'label' => ucfirst($taxonomy),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => $taxonomy),
        );
   
        register_taxonomy($taxonomy, 'product', $args);
    }
   
 
    /**
     * Save custom fields dynamically.
     *
     * @param WC_Product_Simple $wc_product
     * @param array $product
     */
    private function save_custom_fields_automated($wc_product, $product)
    {
        $fields = [
            'uom' => 'uom',
            'uom_description' => 'uom_description',
            'unit_weight' => 'unit_weight',
            'weight_uom' => 'weight_uom',
            'weight_uom_description' => 'weight_uom_description',
        ];
       
        foreach ($fields as $meta_key => $field_name) {
            if (isset($product[$field_name])) {
                $wc_product->update_meta_data($meta_key, $product[$field_name]);
            }
        }
 
        $wc_product->update_meta_data('flourish_item_id', $product['flourish_item_id']);
    }
 
    /**
     * Assign a category to the WooCommerce product.
     *
     * @param string $category_name The category name to assign.
     * @param int $product_id The WooCommerce product ID.
     * @throws \Exception If there is an error inserting the category term.
     */
    private function assign_product_category($category_name, $product_id) {
        if (empty($category_name)) {
            error_log("Category name is empty. Skipping category assignment.");
            return;
        }
   
        // Check if the term already exists
        $term = term_exists($category_name, 'product_cat');
        if (!$term) {
            // Create the term if it doesn't exist
            $term = wp_insert_term($category_name, 'product_cat');
            if (is_wp_error($term)) {
                error_log("Error inserting category: " . $term->get_error_message());
                return;
            }
        }
   
        // Extract term ID correctly
        $term_id = is_array($term) ? $term['term_id'] : $term;
        if ($term_id) {
            wp_set_object_terms($product_id, (int) $term_id, 'product_cat');
            error_log("Category assigned successfully: " . $category_name . " (ID: $term_id) to Product ID: $product_id");
        } else {
            error_log("Failed to retrieve term ID for category: " . $category_name);
        }
    }
   
 
    /**
     * Map a single Flourish item to a WooCommerce product.
     *
     * @param array $flourish_item
     * @return array
     */
    private function map_flourish_item_to_woocommerce_product($flourish_item)
    {
        return [
            'flourish_item_id' => $flourish_item['id'],
            'item_category' => $flourish_item['item_category'],
            'name' => $flourish_item['item_name'],
            'description' => $flourish_item['item_description'],
            'sku' => $flourish_item['sku'],
            'price' => $flourish_item['price'],
            'uom' => $flourish_item['uom'],
            'uom_description' => $flourish_item['uom_description'],
            'unit_weight' => $flourish_item['unit_weight'],
            'weight_uom' => $flourish_item['weight_uom'],
            'weight_uom_description' => $flourish_item['weight_uom_description'],            
            'inventory_quantity' => $flourish_item['inventory_quantity'],
        ];
    }
}
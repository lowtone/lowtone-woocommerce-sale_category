<?php
/*
 * Plugin Name: Sale Category for WooCommerce
 * Plugin URI: http://wordpress.lowtone.nl/plugins/woocommerce-sale_category/
 * Description: Automatically assign products on sale to a specified category.
 * Version: 1.0
 * Author: Lowtone <info@lowtone.nl>
 * Author URI: http://lowtone.nl
 * License: http://wordpress.lowtone.nl/license
 */
/**
 * @author Paul van der Meijs <code@lowtone.nl>
 * @copyright Copyright (c) 2011-2013, Paul van der Meijs
 * @license http://wordpress.lowtone.nl/license/
 * @version 1.0
 * @package wordpress\plugins\lowtone\woocommerce\sale_category
 */

namespace lowtone\woocommerce\sale_category {

	use lowtone\content\packages\Package,
		lowtone\Util;

	// Includes
	
	if (!include_once WP_PLUGIN_DIR . "/lowtone-content/lowtone-content.php") 
		return trigger_error("Lowtone Content plugin is required", E_USER_ERROR) && false;

	// Init

	$__i = Package::init(array(
			Package::INIT_PACKAGES => array("lowtone"),
			Package::INIT_MERGED_PATH => __NAMESPACE__,
			Package::INIT_SUCCESS => function() {

				// Register single_select_category field

				add_action("woocommerce_init", function() {

					if (!has_action("woocommerce_admin_field_single_select_category")) {

						add_action("woocommerce_admin_field_single_select_category", function($value) {
							global $woocommerce;

							$args = array( 
									"name" => $value["id"],
									"id" => $value["id"],
									"orderby" => "NAME",
									"order" => "ASC",
									"show_option_none" => " ",
									"class" => $value["class"],
									"selected" => absint(woocommerce_settings_get_option($value["id"])),
									"hide_empty" => false,
									"hierarchical" => true,
								);

							if(isset($value["args"]))
								$args = wp_parse_args($value["args"], $args);

							$args = array_merge($args, array(
									"taxonomy" => "product_cat",
									"echo" => false,
								));

							$description 
								= $tip 
								= "";

							if (true === $value["desc_tip"]) 
								$tip = $value["desc"];

							elseif (!empty($value["desc_tip"])) {
								$description = $value["desc"];
								$tip = $value["desc_tip"];
							} 

							elseif (!empty( $value["desc"])) 
								$description = $value["desc"];

							$tip = '<img class="help_tip" data-tip="' . esc_attr($tip) . '" src="' . $woocommerce->plugin_url() . '/assets/images/help.png" height="16" width="16" />';

							echo '<tr valign="top" class="single_select_page">' . 
								sprintf('<th scope="row" class="titledesc">%s %s</th>', esc_html($value["title"]), $tip) . 
								'<td class="forminp">' . 
								str_replace(' id=', " data-placeholder='" . __("Select a category&hellip;", "lowtone_woocommerce_sale_category") .  "' style='" . $value["css"] . "' class='" . $value["class"] . "' id=", wp_dropdown_categories($args)) .
								$description . 
								'</td>' . 
								'</tr>';
						});

					}

					if (!has_action("woocommerce_update_option_single_select_category")) {

						add_action("woocommerce_update_option_single_select_category", function($value) {
							update_option($value["id"], isset($_POST[$value["id"]]) ? $_POST[$value["id"]] : -1);
						});

					}

				});

				// Add sale category input

				add_filter("woocommerce_catalog_settings", function($settings) {
					$settings[] = array(
							"title" => __("Sale Category", "lowtone_woocommerce_sale_category"), 
							"type" => "title", 
							"desc" => __("Products with a 'Sale Price' are automatically assigned to the selected category.", "lowtone_woocommerce_sale_category"), 
							"id" => "sale_category_options", 
						);

					$settings[] = array(
							"title" => __("Sale Category", "lowtone_woocommerce_sale_category"),
							"desc" => __("This category contains all products on sale. When a category is set it will be emptied before all products that are on sale are added.", "lowtone_woocommerce_sale_category"),
							"id" => "lowtone_woocommerce_sale_category_id",
							"type" => "single_select_category",
							"default" => "",
							"class"	=> "chosen_select_nostd",
							"css" => "min-width:300px;",
							"desc_tip"=>  true,
						);

					$settings[] = array( 
							"type" => "sectionend", 
							"id" => "sale_category_options",  
						);

					return $settings;
				});

				// Update the products in the selected category

				add_action("update_option_lowtone_woocommerce_sale_category_id", function() {
					updateSaleCategory();
				});

				// Update categories when a product is saved
				
				add_action("save_post", function($id, $post) {
					if (!class_exists("Woocommerce"))
						return;

					if (Util::isAutoSave())
						return;

					if ("product" !== $post->post_type)
						return;

					if (NULL === ($category = saleCategory()))
						return;

					$product = get_product($id);

					$terms = wp_get_object_terms($id, "product_cat", array("fields" => "ids"));

					$terms = $product->is_on_sale() 
						? array_unique(array_merge($terms, array($category->term_id)))
						: array_diff($terms, array($category->term_id));

					$terms = array_map(function($id) {return (int) $id;}, $terms);
					
					wp_set_object_terms($id, $terms, "product_cat");
				}, 10, 2);

				// For scheduled sale prices
				
				add_action("woocommerce_scheduled_sales", function() {
					updateSaleCategory();
				}, 9999);

				// Register textdomain
				
				add_action("plugins_loaded", function() {
					load_plugin_textdomain("lowtone_woocommerce_sale_category", false, basename(__DIR__) . "/assets/languages");
				});

			}
		));

	// Functions
	
	/**
	 * Get the ID for the selected sale category.
	 * @return int Returns the sale category ID on success or -1 on failure.
	 */
	function saleCategoryId() {
		return $id = get_option("lowtone_woocommerce_sale_category_id") ?: -1;
	}
	
	/**
	 * Get the category object for the sale category.
	 * @return object|NULL Returns the category on success or NULL if no 
	 * category is defined.
	 */
	function saleCategory() {
		return NULL !== ($term = get_term(saleCategoryId(), "product_cat")) && !is_wp_error($term) ? $term : NULL;
	}
	
	/**
	 * Remove all products from the sale category.
	 * @return bool Returns TRUE on success or FALSE on failure.
	 */
	function emptySaleCategory() {
		if (NULL === ($category = saleCategory()))
			return;

		global $wpdb;

		$query = $wpdb->prepare("DELETE FROM `{$wpdb->term_relationships}` WHERE %d = `term_taxonomy_id`", $category->term_taxonomy_id);

		if (false === $wpdb->query($query))
			return false;

		wp_update_term_count($category->term_taxonomy_id, "product_cat");

		return true;
	}

	/**
	 * Update which products are in the sale category.
	 * @return bool Returns TRUE on success or FALSE on failure.
	 */
	function updateSaleCategory() {
		if (NULL === ($category = saleCategory()))
			return false;

		if (false === emptySaleCategory())
			return false;

		foreach (woocommerce_get_product_ids_on_sale() as $id)
			wp_set_object_terms($id, (int) $category->term_id, "product_cat", true);

		return true;
	}

}
# Scontistica Dinamica (Dynamic Discounts)

**Contributors:** Michael Tamanti - Webemento
**Tags:** woocommerce, discounts, dynamic pricing, categories, brands, tags, custom taxonomies, sales
**Requires at least:** 5.0
**Tested up to:** 6.5
**Stable tag:** 1.6.5
**License:** MIT
**License URI:** https://opensource.org/licenses/MIT

## Description

**Scontistica Dinamica (Dynamic Discounts)** is a powerful WooCommerce plugin that allows you to apply dynamic discounts to your products based on various criteria, including categories, brands, product tags, and custom taxonomies. This plugin provides a flexible and efficient way to manage your pricing strategies, offering both percentage and fixed amount discounts.

**Key Features:**

* **Flexible Discount Rules:** Set up discounts based on product categories, brands (supports `product_brand`, `pwb-brand`, `pa_brand`), tags, and any custom taxonomies.
* **Percentage & Fixed Discounts:** Choose between applying a percentage discount or a fixed amount discount to your products.
* **Discount Priority System:** Define a priority for your discounts. When multiple discounts apply to a single product, the one with the highest priority (lowest numerical value) will be applied.
* **Easy Management Interface:** A user-friendly admin interface within WordPress allows you to easily add, edit, activate/deactivate, and delete your dynamic discount rules.
* **Real-time Price Updates:** Discounts are applied instantly to product prices on the shop, product, cart, and checkout pages.
* **Clear Discount Display:** Clearly shows discounted prices with original prices struck through and a percentage badge on product pages, cart, and checkout.
* **Admin Product List Integration:** Adds a "Dynamic Discount" column to the WooCommerce product list in the WordPress admin, showing which discounts are applied at a glance.
* **WooCommerce API Integration:** Utilizes proper WooCommerce hooks and APIs (`woocommerce_product_get_price`, `woocommerce_before_calculate_totals`, etc.) for seamless integration and compatibility.

Streamline your promotional efforts and enhance your WooCommerce store's flexibility with Scontistica Dinamica!

## Installation

1.  **Download** the plugin ZIP file.
2.  **Upload** the plugin to your WordPress site through the 'Plugins' -> 'Add New' -> 'Upload Plugin' page in your WordPress admin area.
3.  **Activate** the plugin through the 'Plugins' menu in WordPress.
4.  **Navigate** to 'Dynamic Discounts' in your WordPress admin sidebar to configure your discount rules.

## Frequently Asked Questions

### How do I add a new discount?
Go to the "Dynamic Discounts" menu in your WordPress admin. Click the "Add New Discount" button. Fill in the discount name, type (percentage or fixed), value, priority, target type (category, brand, tag, or custom taxonomy), and the specific target value. Then click "Save Discount."

### What is "Priority" and how does it work?
The "Priority" field determines which discount is applied when multiple rules could affect the same product. A lower number indicates a higher priority. For example, a discount with priority `1` will be applied over a discount with priority `10`.

### How are brands supported?
The plugin supports common WooCommerce brand taxonomies such as `product_brand`, `pwb-brand`, and `pa_brand`. When you select "Brand" as the target type, the plugin will attempt to find terms from these taxonomies.

### Can I apply discounts to custom taxonomies?
Yes, the plugin allows you to select any public, non-built-in custom taxonomy as a target for your discounts.

### How are discounted prices displayed to customers?
On product pages, in the cart, and at checkout, the original price will be struck through, and the new discounted price will be shown. A red badge indicating the percentage discount (e.g., "-15%") will also be displayed next to the discounted price.

### What happens if I deactivate the plugin?
Upon deactivation, the custom database table created by the plugin (`wp_dynamic_discounts`) will remain intact, preserving your discount rules if you choose to reactivate the plugin later. No discounts will be applied while the plugin is inactive.

## Screenshots

*(Screenshots would typically go here. You would add images of the admin interface, product page with discount, cart with discount, etc.)*

For example:
1.  **Discount Management Page:** A view of the main admin page listing all configured discounts.
2.  **Add New Discount Form:** A screenshot of the form used to create or edit a discount.
3.  **Product Page with Discount:** How a discounted product looks on the frontend.
4.  **Cart Page with Discount:** How a discounted product appears in the WooCommerce cart.

## Changelog

### 1.6.5
* Refactored `apply_discount` and `calculate_product_discount` to use regular price as base.
* Enhanced cart and checkout discount application.
* Improved display of discounted prices in cart and checkout with original price strikethrough and discount percentage badge.
* Added `static $has_run = false;` to `apply_cart_discounts` to prevent multiple applications.

### 1.6.0
* Added "Dynamic Discount" column to the product list in the WordPress admin area.
* Improved logic for displaying applied discounts in the admin product list, including handling variable products.
* Enhanced `get_target_name` and `product_matches_discount` for better compatibility with new taxonomy:term_id format and existing data.

### 1.5.0
* Implemented a discount priority system (lower value = higher priority).
* Added `priority` column to the database table and updated activation logic.
* Modified discount application logic to respect priority.

### 1.4.0
* Improved `get_brands_js` and `get_target_name` to better support various brand taxonomies (`product_brand`, `pwb-brand`, `pa_brand`).
* Enhanced `save_discount` to correctly handle brand target values with or without taxonomy prefix.
* Added more robust error logging for debugging.

### 1.3.0
* Introduced support for custom taxonomies as discount targets.
* Updated `admin_page` and `get_admin_js` to include custom taxonomy selection.
* Added `get_custom_taxonomies_js` for fetching custom taxonomy terms.

### 1.2.0
* Improved `get_categories_js` and `get_tags_js` functions.
* Enhanced `product_matches_discount` and `get_target_name` for consistent handling of taxonomy:term_id format for categories and tags.
* Added debug logging for better traceability.

### 1.1.0
* Added functionality to toggle discounts active/inactive from the admin interface.
* Implemented `toggle_discount` AJAX action.
* Updated admin page display to include toggle switch.

### 1.0.0
* Initial release of the Dynamic Discounts plugin.
* Core functionality for percentage and fixed discounts.
* Discount application to categories and tags.
* Basic admin interface for managing discounts.

## Upgrade Notice

### 1.6.5
This update focuses on refining how discounts are applied and displayed, particularly in the cart and checkout. It's recommended to update to ensure the most accurate price calculations and clear presentation to customers.

### 1.6.0
This version adds a new "Dynamic Discount" column to the WooCommerce product list in the admin. No database migration is strictly required, but existing discounts will benefit from improved display logic.

### 1.5.0
This update introduces the discount priority system. Upon activation, the plugin will attempt to assign a default priority to existing discounts based on their ID. Review your discount priorities after updating to ensure they behave as expected.

### 1.4.0
This version enhances brand support. No specific upgrade steps are needed, but if you use custom brand taxonomies, the plugin will now better recognize and apply discounts to them.

## Contributors

* Michael Tamanti - [Webemento](https://webemento.com)

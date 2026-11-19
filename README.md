# Biduk Price per Weight for WooCommerce

Show shoppers the price per unit of weight and let them compare products using a sidebar filter.

## Features

- Display catalog prices per kilogram, gram, pound, or ounce. The plugin converts from the weight unit configured in WooCommerce and formats prices in the store currency.
- Show the lowest unit price among available variations. Each variation uses its own price and weight.
- Show unit prices across the catalog, limit them to selected product categories, or exclude selected categories. Selections include their subcategories and also govern filter results.
- Let shoppers filter classic product archives by minimum and maximum unit price. Each field shows the currency symbol and selected weight unit, for example `Minimum $/kg`.
- Display the filter alongside WooCommerce’s classic **Filter by price** widget. For manual placement, add **Biduk Price per Weight Filter** under **Appearance → Widgets**.

## Installation

1. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `biduk-price-per-weight-4.1.2.zip` and activate it.
3. Open **WooCommerce → Price per Weight** to choose the display unit and category visibility. The page also links to the WooCommerce settings for store currency and product weight units.
4. Allow WP-Cron to finish indexing existing products before relying on the filter.

## Compatibility

Requires WordPress 6.5 or later, PHP 7.4 or later, and WooCommerce. The sidebar filter works with classic WooCommerce product archives. Product Collection blocks are outside its scope.

Unit prices use the catalog price in the store currency. Customer-specific taxes, checkout discounts, and currency conversion are outside the filter’s scope.

## Version 4.1.2

- Added the current currency symbol and display unit to both filter labels.
- Fixed the **WooCommerce → Price per Weight** menu link.

## License

Developed by **biduk**. Released under the [GNU General Public License, version 2 or later](LICENSE).

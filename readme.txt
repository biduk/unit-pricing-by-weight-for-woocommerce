=== Unit Pricing by Weight for WooCommerce ===
Stable tag: 4.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce

Shows the catalog price per kg, g, lb, or oz. Variable products show the
lowest unit price among their available variations.

Open WooCommerce > Price per Weight to select the displayed
unit and show or hide unit prices by product category. Selected categories
include their children. The tab links to the WooCommerce settings for
store currency and the unit used to enter product weights.

The price and filter use the configured catalog price and store currency.
Customer-specific taxes, currency conversion and discounts applied at
checkout are outside the filter's scope.

The filter appears beside the classic WooCommerce Filter by price widget.
To choose its position or use a different sidebar, add Biduk Price per
Weight Filter under Appearance > Widgets. The filter works on classic
WooCommerce product archives. Product Collection blocks do not use it.

== Upgrading from Bubble Beep Price / Weight ==

Deactivate the old plugin before activating this package. The new plugin
uses its own index and queues a background rebuild on activation.
Allow WP-Cron to run before relying on the archive filter. Existing
product prices and weights are reused. Existing bb_ppk_min and bb_ppk_max
links continue to work.

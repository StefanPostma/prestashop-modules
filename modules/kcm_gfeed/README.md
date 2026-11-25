# kcm_gfeed: Google Shopping feed

Serves an RSS 2.0 product feed for Google Merchant Center at
`/index.php?fc=module&module=kcm_gfeed&controller=feed&token=…` (the URL is shown on the module's
configuration page). One item per product, or per combination (`item_group_id` = product id).

Attributes: id, title, description, link, image_link (+ up to 10 additional images), condition, availability
(in_stock / backorder / out_of_stock from the stock and the "allow orders when out of stock" setting), price
(tax included, default currency), brand (manufacturer), gtin (EAN-13 when valid), mpn (mpn or reference),
identifier_exists, google_product_category (setting, default 899), product_type (category path),
shipping_weight (a configurable default, 1 kg, when the product has no weight: Google requires one for
weight-based rates) and **shipping_label**.

**Collect-only products.** A product counts as collect-only when its carrier restriction contains pickup carriers
only (the carrier list of kcm_pickuponly is reused as the default) or when its weight exceeds the heaviest parcel
you ship (setting, default 23 kg). Such products get `shipping_label` `afhalen`, all other products `verzenden`
(both labels are settings). In Merchant Center scope your shipping policies to the label `verzenden`. Google
requires a shipping price for every product in the online listings, so collect-only products have three modes
(setting, see *Local inventory* below): leave out (default), local only, or send normally.

Products are skipped when inactive, not available for order, hidden (visibility none), without cover image,
(setting, default on) out of stock or (setting) with price 0. Requires the XMLWriter PHP extension (standard).

## Local inventory (1.1.0)
Google's free local listings / local inventory ads need, besides the product feed, a local inventory feed:
`/module/kcm_gfeed/localinventory?token=…` returns tab-delimited text (`store_code`, `id`, `availability`, `quantity`)
with the same ids and the same product selection as the product feed. It needs the store code of the shop in the
Business Profile (module setting); until it is filled in the endpoint answers 409. Collect-only products have three
modes in the settings: leave out (default), local only (in the product feed with `excluded_destination` Free_listings
and Shopping_ads, shown through the local feed) or send normally.

## Markdowns (1.2.0)
Products with an active specific price (reduction) get `g:price` = regular price and `g:sale_price` = reduced price, plus `g:sale_price_effective_date` when the specific price has both a start and end date. Google then shows the discount. Without a specific price nothing changes.

## Google Customer Reviews (1.3.0)
Google Customer Reviews is a Merchant Center programme (add-on). The module adds, through the hook
`displayBeforeBodyClosingTag` (registered on install and whenever the settings page is opened):

- the **survey opt-in** on the order confirmation page: Google asks the customer for permission to send a review
  survey after delivery. Passed to Google: Merchant Center id, order reference, e-mail address, delivery country,
  estimated delivery date (order date plus a number of days, separate settings for the shop country and other
  countries) and the GTINs of the ordered products when they have a valid EAN. Nothing is printed on other pages
  or when an old confirmation page is opened again; access to the confirmation page itself is guarded by
  PrestaShop (secure key in the link), the customer does not have to be logged in. Mention the programme in your privacy
  statement. A payment provider (Mollie and the like) is no problem as long as it sends the customer back to the
  shop's own order confirmation page.
- optionally the **store badge** (`merchantwidget.js`) on every page, bottom right or bottom left. It loads a
  Google script on every page and shows no rating until Google has collected enough reviews; off by default.

Settings: Merchant Center ID, opt-in on/off, the two delivery estimates, badge on/off and its position.

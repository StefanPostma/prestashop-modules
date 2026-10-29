# kcm_pickuponly — honest shipping line for collect-only products

PrestaShop 8/9 module. When a product can only be collected (its only carrier is a pickup
carrier without shipping cost), the cart, the cart modal and the checkout summary still say
**Shipping: Free**. That is misleading: nothing is shipped. This module replaces "Free" with the
pickup carrier's name, e.g. **Shipping: Collect at the shop**, as soon as *every* delivery option
of the cart is a pickup carrier. Carts that can (also) be shipped are left untouched.

On the product page a line under the price tells the customer that the product can only be
collected (hook `displayProductPriceBlock`, product sheet only).

## How it works

- Hook `actionPresentCart`: the presented cart is a lazy array; the module reads the `subtotals`,
  changes `shipping.value` and writes the array back with `offsetSet(..., true)`.
- "Pickup only" is decided with `Cart::getDeliveryOptionList()`, the same list the checkout uses.
  Every carrier in every delivery option must be one of the configured pickup carriers.
- No template changes needed. Works with the classic theme and child themes that print
  `$cart.subtotals.shipping.value`.

## Settings (Modules > Configure)

| Setting | Default |
|---|---|
| Pickup carriers | on install: active carriers whose name contains *ophalen*, *afhalen*, *pickup*, *abhol*, *retrait* or *click* |
| Text instead of "Free" | empty = the carrier name |
| Product page text | `Niet verzendbaar, alleen afhalen (%carrier%)`; `%carrier%` = carrier name, empty = no line |

## Install

Upload the zip in Modules > Upload a module, or copy `kcm_pickuponly/` to `modules/` and install.
`ensureInstalled()` is idempotent (hook + defaults) for scripted installs.

## Limits

- The delivery step still lists the pickup carrier as before.
- The product page line only appears for products with a carrier restriction to pickup carriers.
  Products without restriction (all carriers) are considered shippable.
- Styling: `views/css/kcm_pickuponly.css` (class `kcm-pickup-only`), loaded on the product page.
- A cart with a collect-only product *and* a product that can be shipped is handled by
  PrestaShop itself (one package per carrier set); the module only acts when no shipping
  carrier is available for the whole cart.

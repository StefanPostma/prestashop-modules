<?php
/**
 * Google Shopping feed for PrestaShop 8/9.
 *
 * Serves an RSS 2.0 product feed (Google Merchant Center "scheduled fetch") at
 *   /index.php?fc=module&module=kcm_gfeed&controller=feed&token=…
 * with the usual g: attributes (id, title, description, link, image_link, price, availability, brand,
 * gtin/mpn, condition, shipping_weight, product_type, google_product_category).
 *
 * The point of this module is the g:shipping_label attribute: products that can only be collected get the
 * label "afhalen", everything else "verzenden". In Merchant Center the shipping policies are scoped to
 * those labels, so Google never shows a shipping price for a product that is not shipped.
 * A product counts as collect-only when its carrier restriction only contains pickup carriers (the carrier
 * setting of kcm_pickuponly is reused when that module is installed) or when it weighs more than the
 * heaviest parcel the shop ships (setting, default 23 kg).
 *
 * Since 1.3.0 the module also integrates Google Customer Reviews (a Merchant Center programme): the survey opt-in
 * on the order confirmation page and, optionally, the store badge on every page.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Kcm_Gfeed extends Module
{
    const TOKEN = 'KCM_GFEED_TOKEN';
    const MAX_WEIGHT = 'KCM_GFEED_MAX_WEIGHT';          // kg; heavier products get the pickup label
    const PICKUP_CARRIERS = 'KCM_GFEED_PICKUP_CARRIERS'; // comma separated carrier id_reference values
    const LABEL_SHIP = 'KCM_GFEED_LABEL_SHIP';
    const LABEL_PICKUP = 'KCM_GFEED_LABEL_PICKUP';
    const GOOGLE_CATEGORY = 'KCM_GFEED_GOOGLE_CATEGORY'; // numeric Google product category id
    const SKIP_ZERO_PRICE = 'KCM_GFEED_SKIP_ZERO_PRICE';
    const SKIP_OUT_OF_STOCK = 'KCM_GFEED_SKIP_OOS';      // only products with stock go into the feed
    const SKIP_PICKUP = 'KCM_GFEED_SKIP_PICKUP';          // leave collect-only products out of the feed (Google needs a shipping price for every listed product)
    const PICKUP_MODE = 'KCM_GFEED_PICKUP_MODE';          // skip | local | send: what happens with collect-only products in the product feed
    const STORE_CODE = 'KCM_GFEED_STORE_CODE';            // store code of the Business Profile location, used in the local inventory feed
    const DEFAULT_WEIGHT = 'KCM_GFEED_DEFAULT_WEIGHT';     // kg used as shipping_weight when the product has no weight (Google requires one for weight-based rates)
    const GCR_MERCHANT_ID = 'KCM_GFEED_GCR_MERCHANT_ID';   // Merchant Center account id, needed for Google Customer Reviews
    const GCR_OPTIN = 'KCM_GFEED_GCR_OPTIN';               // survey opt-in on the order confirmation page
    const GCR_DAYS = 'KCM_GFEED_GCR_DAYS';                 // estimated delivery: days after the order, shop country
    const GCR_DAYS_ABROAD = 'KCM_GFEED_GCR_DAYS_ABROAD';   // estimated delivery: days after the order, other countries
    const GCR_BADGE = 'KCM_GFEED_GCR_BADGE';               // Google store badge on every page
    const GCR_BADGE_POSITION = 'KCM_GFEED_GCR_BADGE_POS';  // RIGHT_BOTTOM | LEFT_BOTTOM

    public function __construct()
    {
        $this->name = 'kcm_gfeed';
        $this->tab = 'market_place';
        $this->version = '1.3.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.2.1']; // highest PrestaShop version this module was tested on
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Google Shopping feed', [], 'Modules.Kcmgfeed.Admin');
        $this->description = $this->trans('Product feed for Google Merchant Center with shipping labels for collect-only products.', [], 'Modules.Kcmgfeed.Admin');
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    /** Idempotent defaults (safe to call again after a partial install). */
    public function ensureInstalled(): bool
    {
        $ok = true;
        if (!Configuration::get(self::TOKEN)) {
            $ok = Configuration::updateValue(self::TOKEN, Tools::passwdGen(32)) && $ok;
        }
        if (Configuration::get(self::MAX_WEIGHT) === false) {
            $ok = Configuration::updateValue(self::MAX_WEIGHT, '23') && $ok;
        }
        if (Configuration::get(self::PICKUP_CARRIERS) === false) {
            $pickup = (string) Configuration::get('KCM_PICKUP_CARRIERS'); // from kcm_pickuponly when present
            $ok = Configuration::updateValue(self::PICKUP_CARRIERS, $pickup !== '' ? $pickup : implode(',', $this->guessPickupCarriers())) && $ok;
        }
        if (Configuration::get(self::LABEL_SHIP) === false) {
            $ok = Configuration::updateValue(self::LABEL_SHIP, 'verzenden') && $ok;
        }
        if (Configuration::get(self::LABEL_PICKUP) === false) {
            $ok = Configuration::updateValue(self::LABEL_PICKUP, 'afhalen') && $ok;
        }
        if (Configuration::get(self::GOOGLE_CATEGORY) === false) {
            $ok = Configuration::updateValue(self::GOOGLE_CATEGORY, '899') && $ok; // Vehicles & Parts > Vehicle Parts & Accessories
        }
        if (Configuration::get(self::SKIP_ZERO_PRICE) === false) {
            $ok = Configuration::updateValue(self::SKIP_ZERO_PRICE, 1) && $ok;
        }
        if (Configuration::get(self::SKIP_OUT_OF_STOCK) === false) {
            $ok = Configuration::updateValue(self::SKIP_OUT_OF_STOCK, 1) && $ok;
        }
        if (Configuration::get(self::PICKUP_MODE) === false) {
            // migrate the 1.0.3 on/off setting: off = send them like any other product, otherwise leave them out
            $ok = Configuration::updateValue(self::PICKUP_MODE, (string) Configuration::get(self::SKIP_PICKUP) === '0' ? 'send' : 'skip') && $ok;
        }
        if (Configuration::get(self::STORE_CODE) === false) {
            $ok = Configuration::updateValue(self::STORE_CODE, '') && $ok;
        }
        if (Configuration::get(self::DEFAULT_WEIGHT) === false) {
            $ok = Configuration::updateValue(self::DEFAULT_WEIGHT, '1') && $ok;
        }
        foreach ([self::GCR_MERCHANT_ID => '', self::GCR_OPTIN => 0, self::GCR_DAYS => 5, self::GCR_DAYS_ABROAD => 8, self::GCR_BADGE => 0, self::GCR_BADGE_POSITION => 'RIGHT_BOTTOM'] as $key => $default) {
            if (Configuration::get($key) === false) {
                $ok = Configuration::updateValue($key, $default) && $ok;
            }
        }
        if ($this->id && !$this->isRegisteredInHook('displayBeforeBodyClosingTag')) {
            $ok = $this->registerHook('displayBeforeBodyClosingTag') && $ok; // added in 1.3.0; registered on install and when the settings page is opened
        }

        return $ok;
    }

    public function uninstall()
    {
        foreach ([self::TOKEN, self::MAX_WEIGHT, self::PICKUP_CARRIERS, self::LABEL_SHIP, self::LABEL_PICKUP, self::GOOGLE_CATEGORY, self::SKIP_ZERO_PRICE, self::SKIP_OUT_OF_STOCK, self::SKIP_PICKUP, self::PICKUP_MODE, self::STORE_CODE, self::DEFAULT_WEIGHT, self::GCR_MERCHANT_ID, self::GCR_OPTIN, self::GCR_DAYS, self::GCR_DAYS_ABROAD, self::GCR_BADGE, self::GCR_BADGE_POSITION] as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    /** Public feed URL (https, default shop language). */
    public function getFeedUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'feed', ['token' => (string) Configuration::get(self::TOKEN)], true);
    }

    /** Public URL of the local inventory feed (tab-delimited text). */
    public function getLocalFeedUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'localinventory', ['token' => (string) Configuration::get(self::TOKEN)], true);
    }

    /** @return int[] carrier id_reference values that are pickup carriers */
    public function pickupCarrierRefs(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Configuration::get(self::PICKUP_CARRIERS)))));
    }

    /** Carriers whose name says pickup; default setting when kcm_pickuponly is not installed. */
    private function guessPickupCarriers(): array
    {
        $refs = [];
        foreach ($this->allCarriers() as $c) {
            if (preg_match('/ophalen|afhalen|pick.?up|abhol|retrait|click/i', $c['name'])) {
                $refs[] = (int) $c['id_reference'];
            }
        }

        return array_values(array_unique($refs));
    }

    /** Active, not deleted carriers: id_reference + name (one row per reference). */
    private function allCarriers(): array
    {
        $rows = [];
        foreach (Carrier::getCarriers((int) $this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS) as $c) {
            $rows[(int) $c['id_reference']] = ['id_reference' => (int) $c['id_reference'], 'name' => $c['name'] === '0' ? Configuration::get('PS_SHOP_NAME') : $c['name']];
        }

        return array_values($rows);
    }

    /* ------------------------------------------------------------------ */
    /* Google Customer Reviews                                              */
    /* ------------------------------------------------------------------ */

    /** Survey opt-in on the order confirmation page and (setting) the store badge on every page. */
    public function hookDisplayBeforeBodyClosingTag($params)
    {
        $merchantId = (int) Configuration::get(self::GCR_MERCHANT_ID);
        if ($merchantId <= 0) {
            return '';
        }
        $optin = Configuration::get(self::GCR_OPTIN) ? $this->surveyOptIn($merchantId) : null;
        $badge = (bool) Configuration::get(self::GCR_BADGE);
        if (!$optin && !$badge) {
            return '';
        }
        // HEX flags: the JSON is printed inside a <script> element
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $position = (string) Configuration::get(self::GCR_BADGE_POSITION);
        $this->context->smarty->assign([
            'kcm_gcr_optin' => $optin ? json_encode($optin, $flags) : '',
            'kcm_gcr_lang' => json_encode((string) $this->context->language->iso_code, $flags),
            'kcm_gcr_badge' => $badge ? json_encode(['merchant_id' => $merchantId, 'position' => in_array($position, ['LEFT_BOTTOM', 'RIGHT_BOTTOM'], true) ? $position : 'RIGHT_BOTTOM'], $flags) : '',
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/gcr.tpl');
    }

    /**
     * Data for Google's survey opt-in, or null when this is not the confirmation page of a fresh order.
     * Google sends the survey after the estimated delivery date: order date plus a number of days (settings).
     * Who may see the page is decided by the controller (secure key in the link, checked in init()); the visitor
     * does not have to be logged in, which matters when a customer returns from a banking app in another browser.
     */
    private function surveyOptIn(int $merchantId): ?array
    {
        $controller = $this->context->controller;
        if (!$controller instanceof OrderConfirmationController || empty($controller->id_order)) {
            return null;
        }
        $order = new Order((int) $controller->id_order);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $iso = strtoupper((string) Country::getIsoById((int) $address->id_country));
        if (!Validate::isEmail($customer->email) || !preg_match('/^[A-Z]{2}$/', $iso)) {
            return null;
        }
        $home = (int) $address->id_country === (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $days = max(1, (int) Configuration::get($home ? self::GCR_DAYS : self::GCR_DAYS_ABROAD));
        $delivery = strtotime($order->date_add . ' +' . $days . ' days');
        if (!$delivery || $delivery < strtotime('today')) {
            return null; // an old confirmation page that is opened again
        }
        $data = [
            'merchant_id' => $merchantId,
            'order_id' => (string) ($order->reference ?: $order->id),
            'email' => (string) $customer->email,
            'delivery_country' => $iso,
            'estimated_delivery_date' => date('Y-m-d', $delivery),
        ];
        $products = [];
        foreach ($order->getProductsDetail() as $p) { // plain order lines, no price calculation needed
            $gtin = preg_replace('/\D/', '', (string) ($p['product_ean13'] ?? ''));
            if (in_array(strlen($gtin), [8, 12, 13, 14], true)) {
                $products[$gtin] = ['gtin' => $gtin];
            }
        }
        if ($products) {
            $data['products'] = array_values($products);
        }

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* Back office                                                          */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $this->ensureInstalled(); // seeds settings added in later versions
        $out = '';
        if (Tools::isSubmit('submitKcmGfeed')) {
            $refs = [];
            foreach ($this->allCarriers() as $c) {
                if (Tools::getValue(self::PICKUP_CARRIERS . '_' . $c['id_reference'])) {
                    $refs[] = $c['id_reference'];
                }
            }
            Configuration::updateValue(self::PICKUP_CARRIERS, implode(',', $refs));
            Configuration::updateValue(self::MAX_WEIGHT, (string) max(0, (float) str_replace(',', '.', (string) Tools::getValue(self::MAX_WEIGHT))));
            Configuration::updateValue(self::LABEL_SHIP, $this->cleanLabel(Tools::getValue(self::LABEL_SHIP), 'verzenden'));
            Configuration::updateValue(self::LABEL_PICKUP, $this->cleanLabel(Tools::getValue(self::LABEL_PICKUP), 'afhalen'));
            Configuration::updateValue(self::GOOGLE_CATEGORY, (string) (int) Tools::getValue(self::GOOGLE_CATEGORY));
            Configuration::updateValue(self::SKIP_ZERO_PRICE, (int) (bool) Tools::getValue(self::SKIP_ZERO_PRICE));
            Configuration::updateValue(self::SKIP_OUT_OF_STOCK, (int) (bool) Tools::getValue(self::SKIP_OUT_OF_STOCK));
            $mode = (string) Tools::getValue(self::PICKUP_MODE);
            Configuration::updateValue(self::PICKUP_MODE, in_array($mode, ['skip', 'local', 'send'], true) ? $mode : 'skip');
            Configuration::updateValue(self::STORE_CODE, substr(trim((string) Tools::getValue(self::STORE_CODE)), 0, 64));
            Configuration::updateValue(self::DEFAULT_WEIGHT, (string) max(0, (float) str_replace(',', '.', (string) Tools::getValue(self::DEFAULT_WEIGHT))));
            Configuration::updateValue(self::GCR_MERCHANT_ID, preg_replace('/\D/', '', (string) Tools::getValue(self::GCR_MERCHANT_ID)));
            Configuration::updateValue(self::GCR_OPTIN, (int) (bool) Tools::getValue(self::GCR_OPTIN));
            Configuration::updateValue(self::GCR_DAYS, max(1, (int) Tools::getValue(self::GCR_DAYS)));
            Configuration::updateValue(self::GCR_DAYS_ABROAD, max(1, (int) Tools::getValue(self::GCR_DAYS_ABROAD)));
            Configuration::updateValue(self::GCR_BADGE, (int) (bool) Tools::getValue(self::GCR_BADGE));
            $position = (string) Tools::getValue(self::GCR_BADGE_POSITION);
            Configuration::updateValue(self::GCR_BADGE_POSITION, in_array($position, ['LEFT_BOTTOM', 'RIGHT_BOTTOM'], true) ? $position : 'RIGHT_BOTTOM');
            if (Tools::getValue('kcm_gfeed_new_token')) {
                Configuration::updateValue(self::TOKEN, Tools::passwdGen(32));
            }
            $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Notifications.Success'));
        }
        $out .= '<div class="panel"><h3>' . $this->trans('Local inventory feed URL', [], 'Modules.Kcmgfeed.Admin') . '</h3><p><code>' . htmlspecialchars($this->getLocalFeedUrl()) . '</code></p><p class="help-block">'
            . $this->trans('Tab-delimited text with store_code, id, availability and quantity. Register it in Merchant Center as a local inventory data source (Products > Data sources) once the Business Profile is linked and the store code is filled in below.', [], 'Modules.Kcmgfeed.Admin')
            . '</p></div>';
        $out .= '<div class="panel"><h3>' . $this->trans('Feed URL', [], 'Modules.Kcmgfeed.Admin') . '</h3><p><code>' . htmlspecialchars($this->getFeedUrl()) . '</code></p><p class="help-block">'
            . $this->trans('Add this URL in Google Merchant Center as a scheduled fetch (Products > Data sources). Shipping labels: products that can only be collected get the pickup label; scope your shipping policies in Merchant Center to these labels.', [], 'Modules.Kcmgfeed.Admin')
            . '</p></div>';

        return $out . $this->renderForm();
    }

    private function cleanLabel($value, string $default): string
    {
        $value = strtolower(trim(preg_replace('/[^a-z0-9_-]/i', '', (string) $value)));

        return $value !== '' ? substr($value, 0, 100) : $default;
    }

    private function renderForm(): string
    {
        $carrierInputs = [];
        $values = [];
        $refs = $this->pickupCarrierRefs();
        foreach ($this->allCarriers() as $c) {
            $name = self::PICKUP_CARRIERS . '_' . $c['id_reference'];
            $carrierInputs[] = ['type' => 'switch', 'label' => $c['name'], 'name' => $name, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]];
            $values[$name] = in_array((int) $c['id_reference'], $refs, true) ? 1 : 0;
        }
        $fields = [
            'form' => [
                'legend' => ['title' => $this->displayName, 'icon' => 'icon-cogs'],
                'input' => array_merge([
                    ['type' => 'text', 'label' => $this->trans('Heaviest parcel you ship (kg)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::MAX_WEIGHT, 'class' => 'fixed-width-sm',
                        'desc' => $this->trans('Heavier products get the pickup label. 0 = do not use the weight.', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Shipping weight for products without weight (kg)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::DEFAULT_WEIGHT, 'class' => 'fixed-width-sm',
                        'desc' => $this->trans('Google requires a shipping weight when your rates depend on weight. 0 = omit (such products get disapproved).', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Shipping label for shippable products', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::LABEL_SHIP, 'class' => 'fixed-width-md'],
                    ['type' => 'text', 'label' => $this->trans('Shipping label for collect-only products', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::LABEL_PICKUP, 'class' => 'fixed-width-md'],
                    ['type' => 'text', 'label' => $this->trans('Google product category (numeric id)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GOOGLE_CATEGORY, 'class' => 'fixed-width-sm',
                        'desc' => $this->trans('899 = Vehicles & Parts > Vehicle Parts & Accessories. 0 = omit.', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'switch', 'label' => $this->trans('Skip products with price 0', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::SKIP_ZERO_PRICE, 'is_bool' => true,
                        'values' => [['id' => 'zp_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'zp_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                    ['type' => 'switch', 'label' => $this->trans('Skip products that are out of stock', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::SKIP_OUT_OF_STOCK, 'is_bool' => true,
                        'desc' => $this->trans('Off = out-of-stock products are sent with availability out_of_stock or backorder.', [], 'Modules.Kcmgfeed.Admin'),
                        'values' => [['id' => 'oos_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'oos_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                    ['type' => 'select', 'label' => $this->trans('Collect-only products in the product feed', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::PICKUP_MODE,
                        'desc' => $this->trans('Online listings need a shipping price, so collect-only products are disapproved there. Leave out = not in Google at all. Local only = in the feed but excluded from online listings (free listings, Shopping ads) and shown through the local inventory feed. Send normally = treated like any product.', [], 'Modules.Kcmgfeed.Admin'),
                        'options' => ['query' => [['id' => 'skip', 'name' => $this->trans('Leave out', [], 'Modules.Kcmgfeed.Admin')], ['id' => 'local', 'name' => $this->trans('Local only (needs the local inventory feed)', [], 'Modules.Kcmgfeed.Admin')], ['id' => 'send', 'name' => $this->trans('Send normally', [], 'Modules.Kcmgfeed.Admin')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'label' => $this->trans('Store code (Business Profile)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::STORE_CODE, 'class' => 'fixed-width-lg',
                        'desc' => $this->trans('Store code of the shop location as linked in Merchant Center (Business info > Stores). Needed for the local inventory feed.', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'switch', 'label' => $this->trans('Generate a new feed token', [], 'Modules.Kcmgfeed.Admin'), 'name' => 'kcm_gfeed_new_token', 'is_bool' => true,
                        'desc' => $this->trans('The old URL stops working; update it in Merchant Center.', [], 'Modules.Kcmgfeed.Admin'),
                        'values' => [['id' => 'nt_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'nt_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                    ['type' => 'html', 'name' => 'kcm_gfeed_gcr_title', 'html_content' => '<h4>' . $this->trans('Google Customer Reviews', [], 'Modules.Kcmgfeed.Admin') . '</h4>'],
                    ['type' => 'text', 'label' => $this->trans('Merchant Center ID', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_MERCHANT_ID, 'class' => 'fixed-width-lg',
                        'desc' => $this->trans('The number of your Merchant Center account. Needed for the survey opt-in and the badge; add the Google Customer Reviews add-on in Merchant Center first.', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'switch', 'label' => $this->trans('Survey opt-in on the order confirmation page', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_OPTIN, 'is_bool' => true,
                        'desc' => $this->trans('After an order Google asks the customer for permission to send a review survey. The order reference, e-mail address, delivery country and estimated delivery date are passed to Google; mention this in your privacy statement.', [], 'Modules.Kcmgfeed.Admin'),
                        'values' => [['id' => 'gcro_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'gcro_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                    ['type' => 'text', 'label' => $this->trans('Estimated delivery in the shop country (days after the order)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_DAYS, 'class' => 'fixed-width-sm',
                        'desc' => $this->trans('Google sends the survey after this date, so rather a day late than early.', [], 'Modules.Kcmgfeed.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Estimated delivery in other countries (days after the order)', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_DAYS_ABROAD, 'class' => 'fixed-width-sm'],
                    ['type' => 'switch', 'label' => $this->trans('Google store badge on every page', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_BADGE, 'is_bool' => true,
                        'desc' => $this->trans('Loads a Google script on every page. Until Google has enough reviews the badge shows no rating.', [], 'Modules.Kcmgfeed.Admin'),
                        'values' => [['id' => 'gcrb_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'gcrb_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                    ['type' => 'select', 'label' => $this->trans('Badge position', [], 'Modules.Kcmgfeed.Admin'), 'name' => self::GCR_BADGE_POSITION,
                        'options' => ['query' => [['id' => 'RIGHT_BOTTOM', 'name' => $this->trans('Bottom right', [], 'Modules.Kcmgfeed.Admin')], ['id' => 'LEFT_BOTTOM', 'name' => $this->trans('Bottom left', [], 'Modules.Kcmgfeed.Admin')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'html', 'name' => 'kcm_gfeed_carriers_title', 'html_content' => '<h4>' . $this->trans('Pickup carriers (a product restricted to these carriers only gets the pickup label)', [], 'Modules.Kcmgfeed.Admin') . '</h4>'],
                ], $carrierInputs),
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitKcmGfeed';
        $helper->fields_value = $values + [
            self::MAX_WEIGHT => Configuration::get(self::MAX_WEIGHT),
            self::LABEL_SHIP => Configuration::get(self::LABEL_SHIP),
            self::LABEL_PICKUP => Configuration::get(self::LABEL_PICKUP),
            self::GOOGLE_CATEGORY => Configuration::get(self::GOOGLE_CATEGORY),
            self::SKIP_ZERO_PRICE => (int) Configuration::get(self::SKIP_ZERO_PRICE),
            self::DEFAULT_WEIGHT => Configuration::get(self::DEFAULT_WEIGHT),
            self::SKIP_OUT_OF_STOCK => (int) Configuration::get(self::SKIP_OUT_OF_STOCK),
            self::PICKUP_MODE => Configuration::get(self::PICKUP_MODE) ?: 'skip',
            self::STORE_CODE => Configuration::get(self::STORE_CODE),
            self::GCR_MERCHANT_ID => Configuration::get(self::GCR_MERCHANT_ID),
            self::GCR_OPTIN => (int) Configuration::get(self::GCR_OPTIN),
            self::GCR_DAYS => (int) Configuration::get(self::GCR_DAYS),
            self::GCR_DAYS_ABROAD => (int) Configuration::get(self::GCR_DAYS_ABROAD),
            self::GCR_BADGE => (int) Configuration::get(self::GCR_BADGE),
            self::GCR_BADGE_POSITION => Configuration::get(self::GCR_BADGE_POSITION) ?: 'RIGHT_BOTTOM',
            'kcm_gfeed_new_token' => 0,
        ];

        return $helper->generateForm([$fields]);
    }
}

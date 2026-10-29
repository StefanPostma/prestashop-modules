<?php
/**
 * Pickup only: honest shipping line for collect-only products.
 *
 * When every delivery option of the cart is a pickup carrier (a carrier without shipping cost
 * that the customer uses to collect the order), PrestaShop still prints "Shipping: Free" in the
 * cart, the cart modal and the checkout summary. This module replaces that "Free" with the pickup
 * carrier's name (or a text of your choice), so the customer sees e.g. "Shipping: Collect at the shop".
 *
 * Hook: actionPresentCart (the presented cart is a lazy array; the shipping subtotal is overridden).
 * Settings: which carriers count as pickup carriers, and an optional replacement text.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray;

class Kcm_Pickuponly extends Module
{
    /** comma separated carrier id_reference values that are pickup carriers */
    const CARRIERS_KEY = 'KCM_PICKUP_CARRIERS';
    /** text shown instead of "Free"; empty = the pickup carrier's name */
    const TEXT_KEY = 'KCM_PICKUP_TEXT';

    public function __construct()
    {
        $this->name = 'kcm_pickuponly';
        $this->tab = 'checkout';
        $this->version = '1.0.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Pickup only shipping line', [], 'Modules.Kcmpickuponly.Admin');
        $this->description = $this->trans('Shows the pickup carrier instead of "Free" shipping when the cart can only be collected.', [], 'Modules.Kcmpickuponly.Admin');
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    /** Idempotent: hook registration and default settings (also used after a partial install). */
    public function ensureInstalled(): bool
    {
        $ok = true;
        if (!Hook::isModuleRegisteredOnHook($this, 'actionPresentCart', (int) Context::getContext()->shop->id)) {
            $ok = $this->registerHook('actionPresentCart') && $ok;
        }
        if (Configuration::get(self::CARRIERS_KEY) === false) {
            $ok = Configuration::updateValue(self::CARRIERS_KEY, implode(',', $this->guessPickupCarriers())) && $ok;
        }
        if (Configuration::get(self::TEXT_KEY) === false) {
            $ok = Configuration::updateValue(self::TEXT_KEY, '') && $ok;
        }

        return $ok;
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CARRIERS_KEY);
        Configuration::deleteByName(self::TEXT_KEY);

        return parent::uninstall();
    }

    /* ------------------------------------------------------------------ */
    /* Front office                                                         */
    /* ------------------------------------------------------------------ */

    public function hookActionPresentCart(array $params)
    {
        $presented = $params['presentedCart'] ?? null;
        if (!$presented instanceof CartLazyArray) {
            return;
        }
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || $cart->isVirtualCart()) {
            return;
        }
        $carrierName = $this->pickupOnlyCarrierName($cart);
        if ($carrierName === null) {
            return;
        }
        $subtotals = $presented['subtotals'];
        if (empty($subtotals['shipping'])) {
            return;
        }
        $text = trim((string) Configuration::get(self::TEXT_KEY));
        $subtotals['shipping']['value'] = $text !== '' ? $text : $carrierName;
        $presented->offsetSet('subtotals', $subtotals, true);
    }

    /**
     * Name of the pickup carrier when every delivery option of the cart is a pickup carrier,
     * null when the cart can (also) be shipped or has no delivery options at all.
     */
    private function pickupOnlyCarrierName(Cart $cart): ?string
    {
        $refs = $this->pickupCarrierRefs();
        if (!$refs || !$cart->nbProducts()) {
            return null;
        }
        $defaultCountry = null;
        if (isset($this->context->cookie->id_country)) {
            $defaultCountry = new Country((int) $this->context->cookie->id_country);
        }
        $name = null;
        foreach ($cart->getDeliveryOptionList($defaultCountry) as $addressOptions) {
            foreach ($addressOptions as $option) {
                foreach ($option['carrier_list'] as $idCarrier => $data) {
                    $carrier = $data['instance'] ?? new Carrier((int) $idCarrier);
                    if (!in_array((int) $carrier->id_reference, $refs, true)) {
                        return null;
                    }
                    $name = $name ?? (is_array($carrier->name) ? reset($carrier->name) : $carrier->name);
                }
            }
        }

        return $name;
    }

    /** @return int[] carrier id_reference values */
    private function pickupCarrierRefs(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Configuration::get(self::CARRIERS_KEY)))));
    }

    /** Carriers whose name says pickup; used as the default setting on install. */
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
            $rows[(int) $c['id_reference']] = ['id_reference' => (int) $c['id_reference'], 'name' => $c['name']];
        }

        return array_values($rows);
    }

    /* ------------------------------------------------------------------ */
    /* Back office                                                          */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitKcmPickuponly')) {
            $refs = [];
            foreach ($this->allCarriers() as $c) {
                if (Tools::getValue(self::CARRIERS_KEY . '_' . $c['id_reference'])) {
                    $refs[] = $c['id_reference'];
                }
            }
            Configuration::updateValue(self::CARRIERS_KEY, implode(',', $refs));
            Configuration::updateValue(self::TEXT_KEY, trim(strip_tags((string) Tools::getValue(self::TEXT_KEY))));
            $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Notifications.Success'));
        }

        return $out . $this->renderForm();
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitKcmPickuponly';

        $fields = [
            self::TEXT_KEY => Configuration::get(self::TEXT_KEY),
        ];
        $refs = $this->pickupCarrierRefs();
        foreach ($this->allCarriers() as $c) {
            $fields[self::CARRIERS_KEY . '_' . $c['id_reference']] = in_array($c['id_reference'], $refs, true);
        }
        $helper->fields_value = $fields;

        return $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->displayName, 'icon' => 'icon-truck'],
                'description' => $this->trans('When every delivery option of the cart is one of the carriers ticked below, the shipping line in the cart and checkout shows the carrier name (or the text below) instead of "Free".', [], 'Modules.Kcmpickuponly.Admin'),
                'input' => [
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('Pickup carriers', [], 'Modules.Kcmpickuponly.Admin'),
                        'name' => self::CARRIERS_KEY,
                        'values' => ['query' => $this->allCarriers(), 'id' => 'id_reference', 'name' => 'name'],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Text instead of "Free"', [], 'Modules.Kcmpickuponly.Admin'),
                        'name' => self::TEXT_KEY,
                        'desc' => $this->trans('Leave empty to show the carrier name.', [], 'Modules.Kcmpickuponly.Admin'),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ]]);
    }
}

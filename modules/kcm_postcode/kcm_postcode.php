<?php
/**
 * Accept Dutch postcodes typed without a space ("1234AB", "1234ab") in the PrestaShop address forms.
 *
 * PrestaShop 9 validates the postcode against the country format "NNNN LL" literally (the 1.6
 * shop made the space optional) and the address form also enforces a minimum length of 7.
 * This module normalises a Dutch-looking postcode in the submitted form data to "1234 AB"
 * before the front controller fills the address form, so both checks pass. Your theme should drop the
 * browser-side minlength on the postcode field (see README).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Kcm_Postcode extends Module
{
    public function __construct()
    {
        $this->name = 'kcm_postcode';
        $this->tab = 'checkout';
        $this->version = '1.0.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Dutch postcode without space', [], 'Modules.Kcmpostcode.Admin');
        $this->description = $this->trans('Accepts Dutch postcodes without a space (1234AB becomes 1234 AB) in the address forms.', [], 'Modules.Kcmpostcode.Admin');
    }

    public function install()
    {
        return parent::install() && $this->registerHook('actionFrontControllerInitBefore');
    }

    public function hookActionFrontControllerInitBefore(array $params)
    {
        foreach (['postcode'] as $key) {
            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                continue;
            }
            $v = trim($_POST[$key]);
            if (preg_match('/^(\d{4})\s*([a-zA-Z]{2})$/', $v, $m)) {
                $_POST[$key] = $m[1] . ' ' . strtoupper($m[2]);
            }
        }
    }
}

<?php
/**
 * Cloudflare Turnstile for PrestaShop 8/9: bot check on the customer registration form (account creation
 * page, registration form on the login page, guest step of the checkout) and on the contact form.
 * Each place can be switched on or off in the module settings.
 *
 * The widget is added as an extra form field (hook additionalCustomerFormFields) and rendered by
 * the theme (templates/_partials/form-fields.tpl, type "turnstile"). On submit the token is
 * verified with Cloudflare (hook validateCustomerFormFields); a failed check blocks the form.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Kcm_Turnstile extends Module
{
    const ACTIVE = 'KCM_TURNSTILE_ACTIVE';
    const SITEKEY = 'KCM_TURNSTILE_SITEKEY';
    const SECRET = 'KCM_TURNSTILE_SECRET';
    const FIELD = 'kcm_turnstile';
    const ON_REGISTRATION = 'KCM_TURNSTILE_ON_REGISTRATION'; // account creation + registration form on the login page
    const ON_CHECKOUT = 'KCM_TURNSTILE_ON_CHECKOUT';         // guest / registration step of the checkout
    const ON_CONTACT = 'KCM_TURNSTILE_ON_CONTACT';           // contact form
    const PAGES = ['authentication', 'registration', 'order', 'contact'];

    public function __construct()
    {
        $this->name = 'kcm_turnstile';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Cloudflare Turnstile', [], 'Modules.Kcmturnstile.Admin');
        $this->description = $this->trans('Cloudflare Turnstile bot check on account creation and guest checkout.', [], 'Modules.Kcmturnstile.Admin');
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled() && Configuration::updateValue(self::ACTIVE, 1);
    }

    /** Which places are protected; the settings default to "on" for all three. */
    private function placeEnabled(string $phpSelf): bool
    {
        $map = ['authentication' => self::ON_REGISTRATION, 'registration' => self::ON_REGISTRATION, 'order' => self::ON_CHECKOUT, 'contact' => self::ON_CONTACT];
        if (!isset($map[$phpSelf])) {
            return false;
        }
        $v = Configuration::get($map[$phpSelf]);
        return $v === false || (bool) $v;
    }

    /** Idempotent hook registration (also used to upgrade an installed copy). */
    public function ensureInstalled(): bool
    {
        $ok = true;
        foreach (['additionalCustomerFormFields', 'validateCustomerFormFields', 'actionFrontControllerSetMedia', 'actionFrontControllerInitBefore', 'displayKcmTurnstile'] as $h) {
            if (!Hook::isModuleRegisteredOnHook($this, $h, (int) Context::getContext()->shop->id)) {
                $ok = $this->registerHook($h) && $ok;
            }
        }
        return $ok;
    }

    public function uninstall()
    {
        foreach ([self::ACTIVE, self::SITEKEY, self::SECRET, self::ON_REGISTRATION, self::ON_CHECKOUT, self::ON_CONTACT] as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall();
    }

    /* ---------- configuration page ---------- */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitKcmTurnstile')) {
            Configuration::updateValue(self::ACTIVE, (int) Tools::getValue(self::ACTIVE));
            foreach ([self::ON_REGISTRATION, self::ON_CHECKOUT, self::ON_CONTACT] as $k) {
                Configuration::updateValue($k, (int) Tools::getValue($k));
            }
            Configuration::updateValue(self::SITEKEY, trim((string) Tools::getValue(self::SITEKEY)));
            $secret = trim((string) Tools::getValue(self::SECRET));
            if ($secret !== '') { // empty = keep the stored secret
                Configuration::updateValue(self::SECRET, $secret);
            }
            $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Notifications.Success'));
        }
        return $out . $this->renderForm();
    }

    protected function renderForm()
    {
        $form = ['form' => [
            'legend' => ['title' => $this->trans('Cloudflare Turnstile', [], 'Modules.Kcmturnstile.Admin'), 'icon' => 'icon-shield'],
            'description' => $this->trans('Create a Turnstile widget in the Cloudflare dashboard for your shop domain and paste its site key and secret key here. Choose below where the check is shown. The contact form needs {hook h=\'displayKcmTurnstile\'} in the theme\'s contactform.tpl, the registration forms need the "turnstile" field type in the theme\'s form-fields.tpl (see README).', [], 'Modules.Kcmturnstile.Admin'),
            'input' => [
                ['type' => 'switch', 'label' => $this->trans('Active', [], 'Admin.Global'), 'name' => self::ACTIVE, 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                ['type' => 'switch', 'label' => $this->trans('On account creation (and the registration form on the login page)', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::ON_REGISTRATION, 'is_bool' => true,
                    'values' => [['id' => 'reg_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'reg_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                ['type' => 'switch', 'label' => $this->trans('On the guest / registration step of the checkout', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::ON_CHECKOUT, 'is_bool' => true,
                    'values' => [['id' => 'co_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'co_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                ['type' => 'switch', 'label' => $this->trans('On the contact form', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::ON_CONTACT, 'is_bool' => true,
                    'values' => [['id' => 'ct_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'ct_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                ['type' => 'text', 'label' => $this->trans('Site key', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::SITEKEY, 'required' => true],
                ['type' => 'password', 'label' => $this->trans('Secret key', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::SECRET,
                    'desc' => Configuration::get(self::SECRET) ? $this->trans('A secret key is stored. Leave empty to keep it.', [], 'Modules.Kcmturnstile.Admin') : $this->trans('No secret key stored yet.', [], 'Modules.Kcmturnstile.Admin')],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ]];
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitKcmTurnstile';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $on = function ($k) { $v = Configuration::get($k); return $v === false ? 1 : (int) $v; };
        $helper->tpl_vars = ['fields_value' => [self::ACTIVE => (int) Configuration::get(self::ACTIVE), self::SITEKEY => Configuration::get(self::SITEKEY), self::SECRET => '',
            self::ON_REGISTRATION => $on(self::ON_REGISTRATION), self::ON_CHECKOUT => $on(self::ON_CHECKOUT), self::ON_CONTACT => $on(self::ON_CONTACT)], 'languages' => $this->context->controller->getLanguages(), 'id_language' => $this->context->language->id];
        return $helper->generateForm([$form]);
    }

    /* ---------- front office ---------- */

    private function isEnabledHere(): bool
    {
        $controller = $this->context->controller;
        return (bool) Configuration::get(self::ACTIVE)
            && !empty(Configuration::get(self::SITEKEY))
            && !empty(Configuration::get(self::SECRET))
            && $controller instanceof FrontController
            && in_array($controller->php_self, self::PAGES, true)
            && $this->placeEnabled((string) $controller->php_self)
            && empty($this->context->customer->id); // nothing to protect for logged-in customers
    }

    public function hookActionFrontControllerSetMedia(array $params)
    {
        if (!$this->isEnabledHere()) {
            return;
        }
        $this->context->controller->registerJavascript('kcm-turnstile-api', 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            ['server' => 'remote', 'position' => 'head', 'priority' => 50, 'attributes' => 'async defer']);
    }

    /** Contact page: the theme's contactform.tpl calls {hook h='displayKcmTurnstile'} to render the widget. */
    public function hookDisplayKcmTurnstile(array $params)
    {
        if (!$this->isEnabledHere() || $this->context->controller->php_self !== 'contact') {
            return '';
        }
        return '<div class="cf-turnstile kc-turnstile" data-sitekey="' . htmlspecialchars(Configuration::get(self::SITEKEY)) . '" data-language="' . htmlspecialchars($this->context->language->iso_code) . '" data-theme="light" style="margin:0 0 1rem"></div>';
    }

    /** Contact form has no validation hook: verify the token before the contactform module processes the POST. */
    public function hookActionFrontControllerInitBefore(array $params)
    {
        if (!$this->isEnabledHere() || $this->context->controller->php_self !== 'contact' || !Tools::isSubmit('submitMessage')) {
            return;
        }
        if (!$this->verify((string) Tools::getValue('cf-turnstile-response'))) {
            unset($_POST['submitMessage'], $_GET['submitMessage']); // nothing is sent
            $this->context->smarty->assign('kc_contact_error', $this->context->language->iso_code === 'nl'
                ? 'Uw bericht is niet verzonden: bevestig eerst dat u geen robot bent en probeer het opnieuw.'
                : 'Your message was not sent: please confirm that you are not a robot and try again.');
        }
    }

    public function hookAdditionalCustomerFormFields(array $params)
    {
        if (!$this->isEnabledHere() || $this->context->controller->php_self === 'contact') {
            return [];
        }
        $field = new FormField();
        $field->setName(self::FIELD);
        $field->setType('turnstile');
        $field->setLabel('');
        $field->setAvailableValues(['sitekey' => Configuration::get(self::SITEKEY), 'language' => $this->context->language->iso_code]);
        return [$field];
    }

    public function hookValidateCustomerFormFields(array $params)
    {
        $field = null;
        foreach ($params['fields'] as $f) {
            if ($f->getName() === self::FIELD) {
                $field = $f;
            }
        }
        if (!$field) {
            return null;
        }
        $token = (string) Tools::getValue('cf-turnstile-response');
        if (!$this->verify($token)) {
            $field->addError($this->context->language->iso_code === 'nl'
                ? 'Bevestig eerst dat je geen robot bent (de controle is mislukt, probeer het opnieuw).'
                : 'Please confirm that you are not a robot (the check failed, try again).');
        }
        return $field; // the core stores the returned field, errors included
    }

    /** Verifies the token with Cloudflare. A transport failure lets the request through (and is logged) so the shop keeps working. */
    private function verify(string $token): bool
    {
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $body = http_build_query(['secret' => Configuration::get(self::SECRET), 'response' => $token, 'remoteip' => $ip]);
        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
            $raw = curl_exec($ch);
            curl_close($ch);
        }
        if ($raw === false) {
            $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, stream_context_create(['http' => [
                'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $body, 'timeout' => 8]]));
        }
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !array_key_exists('success', $data)) {
            PrestaShopLogger::addLog('kcm_turnstile: could not reach Cloudflare siteverify, request allowed', 2);
            return true;
        }
        if (empty($data['success'])) {
            PrestaShopLogger::addLog('kcm_turnstile: verification failed (' . implode(',', $data['error-codes'] ?? []) . ') ip ' . $ip, 1);
            return false;
        }
        return true;
    }
}

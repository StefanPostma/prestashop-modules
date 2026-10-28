<?php
/**
 * Cloudflare Turnstile for PrestaShop 8/9: bot check on the customer registration form (account creation
 * page, registration form on the login page, guest step of the checkout), on the contact form and on the
 * newsletter form (ps_emailsubscription). Each place can be switched on or off in the module settings;
 * theme, appearance and a test mode with Cloudflare's official test keys are configurable too.
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
    const ON_NEWSLETTER = 'KCM_TURNSTILE_ON_NEWSLETTER';     // ps_emailsubscription form (footer / column)
    const THEME = 'KCM_TURNSTILE_THEME';                     // auto | light | dark
    const APPEARANCE = 'KCM_TURNSTILE_APPEARANCE';           // always | execute | interaction-only
    const TEST = 'KCM_TURNSTILE_TEST';                       // '' | pass | fail | interactive (Cloudflare test keys)
    const PAGES = ['authentication', 'registration', 'order', 'contact'];
    /** Cloudflare's published test keys: [site key, secret key]. */
    const TEST_KEYS = [
        'pass' => ['1x00000000000000000000AA', '1x0000000000000000000000000000000AA'],
        'fail' => ['2x00000000000000000000AB', '2x0000000000000000000000000000000AA'],
        'interactive' => ['3x00000000000000000000FF', '1x0000000000000000000000000000000AA'],
    ];

    public function __construct()
    {
        $this->name = 'kcm_turnstile';
        $this->tab = 'front_office_features';
        $this->version = '1.1.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Cloudflare Turnstile', [], 'Modules.Kcmturnstile.Admin');
        $this->description = $this->trans('Cloudflare Turnstile bot check on account creation, guest checkout, the contact form and the newsletter form.', [], 'Modules.Kcmturnstile.Admin');
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled() && Configuration::updateValue(self::ACTIVE, 1);
    }

    /** Which places are protected; the settings default to "on". */
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
        foreach (['additionalCustomerFormFields', 'validateCustomerFormFields', 'actionFrontControllerSetMedia', 'actionFrontControllerInitBefore', 'displayKcmTurnstile', 'displayNewsletterRegistration', 'actionNewsletterRegistrationBefore'] as $h) {
            if (!Hook::isModuleRegisteredOnHook($this, $h, (int) Context::getContext()->shop->id)) {
                $ok = $this->registerHook($h) && $ok;
            }
        }
        return $ok;
    }

    public function uninstall()
    {
        foreach ([self::ACTIVE, self::SITEKEY, self::SECRET, self::ON_REGISTRATION, self::ON_CHECKOUT, self::ON_CONTACT, self::ON_NEWSLETTER, self::THEME, self::APPEARANCE, self::TEST] as $k) {
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
            foreach ([self::ON_REGISTRATION, self::ON_CHECKOUT, self::ON_CONTACT, self::ON_NEWSLETTER] as $k) {
                Configuration::updateValue($k, (int) Tools::getValue($k));
            }
            $theme = (string) Tools::getValue(self::THEME);
            Configuration::updateValue(self::THEME, in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'light');
            $appearance = (string) Tools::getValue(self::APPEARANCE);
            Configuration::updateValue(self::APPEARANCE, in_array($appearance, ['always', 'execute', 'interaction-only'], true) ? $appearance : 'always');
            $test = (string) Tools::getValue(self::TEST);
            Configuration::updateValue(self::TEST, isset(self::TEST_KEYS[$test]) ? $test : '');
            Configuration::updateValue(self::SITEKEY, trim((string) Tools::getValue(self::SITEKEY)));
            $secret = trim((string) Tools::getValue(self::SECRET));
            if ($secret !== '') { // empty = keep the stored secret
                Configuration::updateValue(self::SECRET, $secret);
            }
            $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Notifications.Success'));
        }
        if ($this->testMode() !== '') {
            $out .= $this->displayWarning($this->trans('Test mode is on: the widget uses Cloudflare\'s test keys, not your own. Switch it off for production.', [], 'Modules.Kcmturnstile.Admin'));
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
                ['type' => 'switch', 'label' => $this->trans('On the newsletter form (ps_emailsubscription)', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::ON_NEWSLETTER, 'is_bool' => true,
                    'values' => [['id' => 'nl_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'nl_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]],
                    'desc' => $this->trans('The widget is placed inside the newsletter form by the module itself; no theme change needed.', [], 'Modules.Kcmturnstile.Admin')],
                ['type' => 'select', 'label' => $this->trans('Theme', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::THEME,
                    'options' => ['query' => [['id' => 'auto', 'name' => 'auto'], ['id' => 'light', 'name' => 'light'], ['id' => 'dark', 'name' => 'dark']], 'id' => 'id', 'name' => 'name']],
                ['type' => 'select', 'label' => $this->trans('Appearance', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::APPEARANCE,
                    'options' => ['query' => [['id' => 'always', 'name' => 'always (widget always visible)'], ['id' => 'execute', 'name' => 'execute (visible after the check starts)'], ['id' => 'interaction-only', 'name' => 'interaction-only (visible only when a challenge is needed)']], 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->trans('"interaction-only" keeps the form clean: most visitors never see the widget.', [], 'Modules.Kcmturnstile.Admin')],
                ['type' => 'select', 'label' => $this->trans('Test mode', [], 'Modules.Kcmturnstile.Admin'), 'name' => self::TEST,
                    'options' => ['query' => [['id' => '', 'name' => $this->trans('Off (use my keys)', [], 'Modules.Kcmturnstile.Admin')], ['id' => 'pass', 'name' => $this->trans('Always passes', [], 'Modules.Kcmturnstile.Admin')], ['id' => 'fail', 'name' => $this->trans('Always fails', [], 'Modules.Kcmturnstile.Admin')], ['id' => 'interactive', 'name' => $this->trans('Force an interactive challenge', [], 'Modules.Kcmturnstile.Admin')]], 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->trans('Uses Cloudflare\'s official test keys so you can try the forms without touching your own keys. Never leave this on in production.', [], 'Modules.Kcmturnstile.Admin')],
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
            self::ON_REGISTRATION => $on(self::ON_REGISTRATION), self::ON_CHECKOUT => $on(self::ON_CHECKOUT), self::ON_CONTACT => $on(self::ON_CONTACT), self::ON_NEWSLETTER => $on(self::ON_NEWSLETTER),
            self::THEME => $this->theme(), self::APPEARANCE => $this->appearance(), self::TEST => $this->testMode()], 'languages' => $this->context->controller->getLanguages(), 'id_language' => $this->context->language->id];
        return $helper->generateForm([$form]);
    }

    /* ---------- front office ---------- */

    private function testMode(): string
    {
        $t = (string) Configuration::get(self::TEST);
        return isset(self::TEST_KEYS[$t]) ? $t : '';
    }

    private function theme(): string
    {
        $t = (string) Configuration::get(self::THEME);
        return in_array($t, ['auto', 'light', 'dark'], true) ? $t : 'light';
    }

    private function appearance(): string
    {
        $a = (string) Configuration::get(self::APPEARANCE);
        return in_array($a, ['always', 'execute', 'interaction-only'], true) ? $a : 'always';
    }

    /** Site key: the configured one, or Cloudflare's test key in test mode. */
    private function siteKey(): string
    {
        $t = $this->testMode();
        return $t !== '' ? self::TEST_KEYS[$t][0] : (string) Configuration::get(self::SITEKEY);
    }

    private function secretKey(): string
    {
        $t = $this->testMode();
        return $t !== '' ? self::TEST_KEYS[$t][1] : (string) Configuration::get(self::SECRET);
    }

    private function keysOk(): bool
    {
        return (bool) Configuration::get(self::ACTIVE) && $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    private function isEnabledHere(): bool
    {
        $controller = $this->context->controller;
        return $this->keysOk()
            && $controller instanceof FrontController
            && in_array($controller->php_self, self::PAGES, true)
            && $this->placeEnabled((string) $controller->php_self)
            && empty($this->context->customer->id); // nothing to protect for logged-in customers
    }

    /** Newsletter form: can be on every page (footer), so it has its own switch. */
    private function newsletterEnabled(): bool
    {
        $v = Configuration::get(self::ON_NEWSLETTER);
        return $this->keysOk() && $this->context->controller instanceof FrontController && ($v === false || (bool) $v);
    }

    /** The widget markup; $action shows up in the Cloudflare Turnstile analytics. */
    private function widgetHtml(string $action, string $style = 'margin:0 0 1rem'): string
    {
        return '<div class="cf-turnstile kc-turnstile" data-sitekey="' . htmlspecialchars($this->siteKey()) . '" data-language="' . htmlspecialchars($this->context->language->iso_code)
            . '" data-theme="' . $this->theme() . '" data-appearance="' . $this->appearance() . '" data-action="' . htmlspecialchars($action) . '"' . ($style !== '' ? ' style="' . $style . '"' : '') . '></div>';
    }

    public function hookActionFrontControllerSetMedia(array $params)
    {
        if (!$this->isEnabledHere() && !$this->newsletterEnabled()) {
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
        return $this->widgetHtml('contact');
    }

    /** Newsletter form (ps_emailsubscription renders {hook h='displayNewsletterRegistration'} inside its form). */
    public function hookDisplayNewsletterRegistration(array $params)
    {
        if (!$this->newsletterEnabled()) {
            return '';
        }
        return $this->widgetHtml('newsletter', 'margin:.5rem 0');
    }

    /** ps_emailsubscription passes hookError by reference: a message blocks the (un)subscription. */
    public function hookActionNewsletterRegistrationBefore(array $params)
    {
        if (!$this->newsletterEnabled() || !array_key_exists('hookError', $params)) {
            return;
        }
        if (!$this->verify((string) Tools::getValue('cf-turnstile-response'))) {
            $params['hookError'] = $this->context->language->iso_code === 'nl'
                ? 'Bevestig eerst dat u geen robot bent en probeer het opnieuw.'
                : 'Please confirm that you are not a robot and try again.';
        }
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
        $field->setAvailableValues(['sitekey' => $this->siteKey(), 'language' => $this->context->language->iso_code, 'theme' => $this->theme(), 'appearance' => $this->appearance(),
            'action' => $this->context->controller->php_self === 'order' ? 'checkout' : 'registration']);
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
        $body = http_build_query(['secret' => $this->secretKey(), 'response' => $token, 'remoteip' => $ip]);
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

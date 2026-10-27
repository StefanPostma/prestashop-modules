<?php
/**
 * Spam blocklist for registration and the contact form (PrestaShop 8/9).
 * Blocks e-mail domains / addresses from a list (plus optionally the public list of disposable e-mail
 * domains) and messages containing listed words. Blocked attempts are logged in the module settings page.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Kcm_Blockspam extends Module
{
    const DOMAINS = 'KCM_BLOCK_DOMAINS';     // one per line: example.com | .example.com (incl. subdomains) | .ru (TLD) | user@example.com
    const WORDS = 'KCM_BLOCK_WORDS';         // one per line, matched case-insensitively in contact messages / names
    const DISPOSABLE = 'KCM_BLOCK_DISPOSABLE'; // 1 = also use the public disposable-email-domains list
    const DISPOSABLE_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';
    const FIELD = 'kcm_blockcheck';
    const PAGES = ['authentication', 'registration', 'order'];

    public function __construct()
    {
        $this->name = 'kcm_blockspam';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'Spamblokkade (e-maildomeinen en woorden)';
        $this->description = 'Weigert registraties en contactberichten van geblokkeerde e-maildomeinen, wegwerpadressen en berichten met geblokkeerde woorden.';
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    public function ensureInstalled(): bool
    {
        $ok = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'kcm_blockspam_log` (
            `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date_add` DATETIME NOT NULL,
            `source` VARCHAR(16) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `ip` VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (`id_log`), KEY `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        foreach (['additionalCustomerFormFields', 'validateCustomerFormFields', 'actionFrontControllerInitBefore'] as $h) {
            if (!Hook::isModuleRegisteredOnHook($this, $h, (int) Context::getContext()->shop->id)) {
                $ok = $this->registerHook($h) && $ok;
            }
        }
        if (Configuration::get(self::DISPOSABLE) === false) {
            $ok = Configuration::updateValue(self::DISPOSABLE, 1) && $ok;
        }
        return $ok;
    }

    public function uninstall()
    {
        foreach ([self::DOMAINS, self::WORDS, self::DISPOSABLE] as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall(); // the log table is kept
    }

    /* ---------- settings ---------- */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitKcmBlock')) {
            Configuration::updateValue(self::DOMAINS, $this->normaliseList((string) Tools::getValue(self::DOMAINS)), false, null, null);
            Configuration::updateValue(self::WORDS, $this->normaliseList((string) Tools::getValue(self::WORDS)), false, null, null);
            Configuration::updateValue(self::DISPOSABLE, (int) Tools::getValue(self::DISPOSABLE));
            $out .= $this->displayConfirmation('Instellingen opgeslagen.');
        } elseif (Tools::isSubmit('submitKcmBlockRefresh')) {
            $n = $this->refreshDisposable(true);
            $out .= $n ? $this->displayConfirmation("Lijst met wegwerpdomeinen bijgewerkt: $n domeinen.") : $this->displayError('De lijst kon niet worden gedownload.');
        } elseif (Tools::isSubmit('submitKcmBlockClearLog')) {
            Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'kcm_blockspam_log`');
            $out .= $this->displayConfirmation('Logboek geleegd.');
        }
        return $out . $this->renderForm() . $this->renderLog();
    }

    private function normaliseList(string $text): string
    {
        $lines = [];
        foreach (preg_split('/[\r\n,;]+/', $text) as $l) {
            $l = mb_strtolower(trim($l));
            if ($l !== '' && $l[0] !== '#') {
                $lines[$l] = $l;
            }
        }
        return implode("\n", $lines);
    }

    protected function renderForm()
    {
        $disp = $this->disposableInfo();
        $form = ['form' => [
            'legend' => ['title' => 'Spamblokkade', 'icon' => 'icon-ban-circle'],
            'description' => 'Geldt voor Account aanmaken, de gaststap van de checkout en het contactformulier. Een geblokkeerde registratie krijgt de melding dat het e-mailadres niet wordt geaccepteerd; een geblokkeerd contactbericht wordt niet verzonden en niet opgeslagen.',
            'input' => [
                ['type' => 'textarea', 'label' => 'Geblokkeerde e-maildomeinen en adressen', 'name' => self::DOMAINS, 'rows' => 10,
                    'desc' => 'Eén per regel. <code>example.com</code> = precies dat domein; <code>.example.com</code> = ook alle subdomeinen; <code>.ru</code> = hele extensie; <code>naam@example.com</code> = één adres. Regels die met # beginnen worden genegeerd.'],
                ['type' => 'textarea', 'label' => 'Geblokkeerde woorden in contactberichten', 'name' => self::WORDS, 'rows' => 6,
                    'desc' => 'Eén per regel, hoofdletterongevoelig; gezocht in het bericht en het e-mailadres van het contactformulier. Bijvoorbeeld: seo services, backlinks, crypto.'],
                ['type' => 'switch', 'label' => 'Ook wegwerp-e-maildomeinen blokkeren', 'name' => self::DISPOSABLE, 'is_bool' => true,
                    'values' => [['id' => 'disp_on', 'value' => 1, 'label' => 'Ja'], ['id' => 'disp_off', 'value' => 0, 'label' => 'Nee']],
                    'desc' => 'Gebruikt de open lijst disposable-email-domains (GitHub), automatisch ververst na 7 dagen. ' . $disp],
            ],
            'buttons' => [['title' => 'Lijst wegwerpdomeinen nu verversen', 'name' => 'submitKcmBlockRefresh', 'type' => 'submit', 'class' => 'btn btn-default pull-left', 'icon' => 'process-icon-refresh', 'js' => "this.value='1'"]],
            'submit' => ['title' => 'Opslaan'],
        ]];
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitKcmBlock';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = ['fields_value' => [self::DOMAINS => Configuration::get(self::DOMAINS), self::WORDS => Configuration::get(self::WORDS), self::DISPOSABLE => (int) Configuration::get(self::DISPOSABLE)],
            'languages' => $this->context->controller->getLanguages(), 'id_language' => $this->context->language->id];
        return $helper->generateForm([$form]);
    }

    protected function renderLog()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'kcm_blockspam_log` ORDER BY id_log DESC LIMIT 100') ?: [];
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'kcm_blockspam_log`');
        $html = '<div class="panel"><h3><i class="icon-list"></i> Geblokkeerde pogingen <span class="badge">' . $total . '</span></h3>';
        if (!$rows) {
            $html .= '<p>Nog niets geblokkeerd.</p>';
        } else {
            $html .= '<table class="table"><thead><tr><th>Datum</th><th>Waar</th><th>E-mailadres</th><th>Reden</th><th>IP</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr><td>' . Tools::safeOutput($r['date_add']) . '</td><td>' . ($r['source'] === 'contact' ? 'contactformulier' : 'registratie') . '</td><td>' . Tools::safeOutput($r['email']) . '</td><td>' . Tools::safeOutput($r['reason']) . '</td><td>' . Tools::safeOutput((string) $r['ip']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<form method="post" action="' . Tools::safeOutput($this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name) . '"><button type="submit" name="submitKcmBlockClearLog" value="1" class="btn btn-default" onclick="return confirm(\'Logboek leegmaken?\');"><i class="icon-trash"></i> Logboek leegmaken</button></form>';
        }
        return $html . '</div>';
    }

    /* ---------- blocklist logic ---------- */

    private function listLines(string $key): array
    {
        $v = (string) Configuration::get($key);
        return $v === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $v))));
    }

    private function disposablePath(): string
    {
        return _PS_MODULE_DIR_ . $this->name . '/data/disposable.txt';
    }

    private function disposableInfo(): string
    {
        $p = $this->disposablePath();
        if (!is_file($p)) {
            return 'Nog niet gedownload.';
        }
        return 'Laatst gedownload: ' . date('d-m-Y H:i', filemtime($p)) . ', ' . count(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) . ' domeinen.';
    }

    /** Downloads the public disposable-domain list (once per 7 days, or forced). Returns the number of domains. */
    public function refreshDisposable(bool $force = false): int
    {
        $p = $this->disposablePath();
        if (!$force && is_file($p) && filemtime($p) > time() - 7 * 86400) {
            return count(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: PrestaShop kcm_blockspam\r\n"]]);
        $raw = @file_get_contents(self::DISPOSABLE_URL, false, $ctx);
        if ($raw === false && function_exists('curl_init')) {
            $ch = curl_init(self::DISPOSABLE_URL);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'PrestaShop kcm_blockspam']);
            $raw = curl_exec($ch);
            curl_close($ch);
        }
        if (!is_string($raw) || strlen($raw) < 1000) {
            if (is_file($p)) { touch($p); } // keep the old list, retry later
            return 0;
        }
        @mkdir(dirname($p), 0755, true);
        file_put_contents($p, strtolower(trim($raw)) . "\n");
        return count(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private function disposableDomains(): array
    {
        if (!Configuration::get(self::DISPOSABLE)) {
            return [];
        }
        $p = $this->disposablePath();
        if (!is_file($p) || filemtime($p) < time() - 7 * 86400) {
            $this->refreshDisposable();
        }
        if (!is_file($p)) {
            return [];
        }
        static $cache = null;
        if ($cache === null) {
            $cache = array_fill_keys(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), true);
        }
        return $cache;
    }

    /** Returns the reason when the e-mail address is blocked, null otherwise. */
    public function blockedEmailReason(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || strpos($email, '@') === false) {
            return null;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        foreach ($this->listLines(self::DOMAINS) as $rule) {
            if (strpos($rule, '@') !== false) {
                if ($rule === $email) return 'adres op de blokkeerlijst';
            } elseif ($rule[0] === '.') {
                if ($domain === substr($rule, 1) || substr($domain, -strlen($rule)) === $rule) return 'domein/extensie ' . $rule . ' op de blokkeerlijst';
            } elseif ($domain === $rule) {
                return 'domein ' . $rule . ' op de blokkeerlijst';
            }
        }
        $disposable = $this->disposableDomains();
        if ($disposable) {
            $parts = explode('.', $domain);
            for ($i = 0; $i < count($parts) - 1; ++$i) { // domain and its parent domains
                if (isset($disposable[implode('.', array_slice($parts, $i))])) return 'wegwerp-e-maildomein';
            }
        }
        return null;
    }

    /** Returns the matched word when the text contains a blocked word, null otherwise. */
    public function blockedWord(string $text): ?string
    {
        $t = mb_strtolower($text);
        foreach ($this->listLines(self::WORDS) as $w) {
            if ($w !== '' && mb_strpos($t, $w) !== false) return $w;
        }
        return null;
    }

    private function log(string $source, string $email, string $reason): void
    {
        Db::getInstance()->insert('kcm_blockspam_log', ['date_add' => date('Y-m-d H:i:s'), 'source' => pSQL($source), 'email' => pSQL(Tools::substr($email, 0, 255)), 'reason' => pSQL(Tools::substr($reason, 0, 255)), 'ip' => pSQL((string) Tools::getRemoteAddr())]);
    }

    /* ---------- registration / guest checkout ---------- */

    private function onRegistrationPage(): bool
    {
        $c = $this->context->controller;
        return $c instanceof FrontController && in_array($c->php_self, self::PAGES, true) && empty($this->context->customer->id);
    }

    public function hookAdditionalCustomerFormFields(array $params)
    {
        if (!$this->onRegistrationPage()) {
            return [];
        }
        $field = new FormField();
        $field->setName(self::FIELD);
        $field->setType('blockcheck');
        $field->setLabel('');
        return [$field];
    }

    public function hookValidateCustomerFormFields(array $params)
    {
        $field = null;
        foreach ($params['fields'] as $f) {
            if ($f->getName() === self::FIELD) $field = $f;
        }
        if (!$field) {
            return null;
        }
        $email = (string) Tools::getValue('email');
        $reason = $this->blockedEmailReason($email);
        if ($reason === null) {
            $w = $this->blockedWord((string) Tools::getValue('firstname') . ' ' . (string) Tools::getValue('lastname') . ' ' . $email);
            if ($w !== null) $reason = 'geblokkeerd woord "' . $w . '"';
        }
        if ($reason !== null) {
            $this->log('registration', $email, $reason);
            $field->addError($this->context->language->iso_code === 'nl' ? 'Dit e-mailadres wordt niet geaccepteerd. Gebruik een ander e-mailadres of neem telefonisch contact met ons op.' : 'This e-mail address is not accepted. Please use another address or contact us by phone.');
        }
        return $field;
    }

    /* ---------- contact form ---------- */

    public function hookActionFrontControllerInitBefore(array $params)
    {
        $c = $this->context->controller;
        if (!($c instanceof FrontController) || $c->php_self !== 'contact' || !Tools::isSubmit('submitMessage')) {
            return;
        }
        $email = (string) Tools::getValue('from');
        $reason = $this->blockedEmailReason($email);
        if ($reason === null) {
            $w = $this->blockedWord((string) Tools::getValue('message') . ' ' . $email);
            if ($w !== null) $reason = 'geblokkeerd woord "' . $w . '"';
        }
        if ($reason !== null) {
            $this->log('contact', $email, $reason);
            unset($_POST['submitMessage'], $_GET['submitMessage']); // contactform never sees the submit: nothing sent or stored
            $this->context->smarty->assign('kc_contact_error', $this->context->language->iso_code === 'nl'
                ? 'Uw bericht is niet verzonden: dit e-mailadres of deze inhoud wordt niet geaccepteerd. Neem eventueel telefonisch contact met ons op.'
                : 'Your message was not sent: this e-mail address or content is not accepted. You can also contact us by phone.');
        }
    }
}

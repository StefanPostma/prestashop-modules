<?php
/**
 * AI-productomschrijving (AI product description) for PrestaShop 8/9.
 * Knop op de productpagina (back office) die op basis van de productfoto's en de al ingevulde tekst
 * een omschrijving en korte omschrijving laat schrijven door OpenAI of Anthropic. De tekst komt in de
 * editor; de beheerder kijkt na en slaat op. API-sleutels staan in de module-instellingen.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/classes/KcmAiClient.php';
require_once __DIR__ . '/classes/KcmAiDescriber.php';

class Kcm_Aidescribe extends Module
{
    const PROVIDER = 'KCM_AI_PROVIDER';          // anthropic | openai
    const KEY_ANTHROPIC = 'KCM_AI_KEY_ANTHROPIC';
    const KEY_OPENAI = 'KCM_AI_KEY_OPENAI';
    const MODEL_ANTHROPIC = 'KCM_AI_MODEL_ANTHROPIC';
    const MODEL_OPENAI = 'KCM_AI_MODEL_OPENAI';
    const INSTRUCTIONS = 'KCM_AI_INSTRUCTIONS';
    const MAX_IMAGES = 'KCM_AI_MAX_IMAGES';
    const FILL_SHORT = 'KCM_AI_FILL_SHORT';
    const CONTEXT = 'KCM_AI_CONTEXT';

    public function __construct()
    {
        $this->name = 'kcm_aidescribe';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'AI-productomschrijving';
        $this->description = 'Knop op de productpagina die een omschrijving schrijft op basis van de productfoto\'s (OpenAI of Anthropic).';
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    public function ensureInstalled(): bool
    {
        $ok = true;
        if (!Hook::isModuleRegisteredOnHook($this, 'displayBackOfficeHeader', (int) Context::getContext()->shop->id)) {
            $ok = $this->registerHook('displayBackOfficeHeader') && $ok;
        }
        if (!Tab::getIdFromClassName('AdminKcmAiDescribe')) {
            $tab = new Tab();
            $tab->class_name = 'AdminKcmAiDescribe';
            $tab->module = $this->name;
            $tab->id_parent = -1; // hidden: only used for the ajax endpoint and its permissions
            $tab->active = true;
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = 'AI-productomschrijving';
            }
            $ok = $tab->add() && $ok;
        }
        foreach ([self::PROVIDER => 'anthropic', self::MODEL_ANTHROPIC => 'claude-sonnet-5-5', self::MODEL_OPENAI => 'gpt-5', self::MAX_IMAGES => 4, self::FILL_SHORT => 1,
            self::CONTEXT => 'Je schrijft productomschrijvingen voor de webshop ' . Configuration::get('PS_SHOP_NAME') . '.',
            self::INSTRUCTIONS => "Schrijf in het Nederlands, zakelijk en helder, in de u-vorm. Vermeld de staat (nieuw, gereviseerd of gebruikt) als die zichtbaar is of in de tekst staat."] as $k => $v) {
            if (Configuration::get($k) === false) {
                $ok = Configuration::updateValue($k, $v) && $ok;
            }
        }
        return $ok;
    }

    public function uninstall()
    {
        if ($id = (int) Tab::getIdFromClassName('AdminKcmAiDescribe')) {
            (new Tab($id))->delete();
        }
        foreach ([self::PROVIDER, self::KEY_ANTHROPIC, self::KEY_OPENAI, self::MODEL_ANTHROPIC, self::MODEL_OPENAI, self::INSTRUCTIONS, self::MAX_IMAGES, self::FILL_SHORT, self::CONTEXT] as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall();
    }

    /* ---------- settings page ---------- */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitKcmAiTest') || Tools::isSubmit('submitKcmAi')) {
            $this->saveSettings();
            $out .= $this->displayConfirmation('Instellingen opgeslagen.');
        }
        if (Tools::isSubmit('submitKcmAiTest')) {
            try {
                $t = microtime(true);
                $answer = KcmAiClient::fromSettings()->complete('Je bent een testassistent.', 'Antwoord met precies het woord OK.', []);
                $out .= $this->displayConfirmation('Verbinding werkt (' . (Configuration::get(self::PROVIDER) === 'openai' ? 'OpenAI, ' . Configuration::get(self::MODEL_OPENAI) : 'Anthropic, ' . Configuration::get(self::MODEL_ANTHROPIC)) . ', ' . round(microtime(true) - $t, 1) . ' s). Antwoord van het model: ' . Tools::safeOutput(Tools::substr(trim($answer), 0, 80)));
            } catch (Throwable $e) {
                $out .= $this->displayError('Verbinding mislukt: ' . Tools::safeOutput($e->getMessage()));
            }
        }
        return $out . $this->renderForm();
    }

    private function saveSettings(): void
    {
        Configuration::updateValue(self::PROVIDER, Tools::getValue(self::PROVIDER) === 'openai' ? 'openai' : 'anthropic');
        foreach ([self::KEY_ANTHROPIC, self::KEY_OPENAI] as $k) { // empty = keep the stored key
            $v = trim((string) Tools::getValue($k));
            if ($v !== '') { Configuration::updateValue($k, $v); }
        }
        Configuration::updateValue(self::MODEL_ANTHROPIC, trim((string) Tools::getValue(self::MODEL_ANTHROPIC)) ?: 'claude-sonnet-5-5');
        Configuration::updateValue(self::MODEL_OPENAI, trim((string) Tools::getValue(self::MODEL_OPENAI)) ?: 'gpt-5');
        Configuration::updateValue(self::CONTEXT, trim((string) Tools::getValue(self::CONTEXT)), true);
        Configuration::updateValue(self::INSTRUCTIONS, trim((string) Tools::getValue(self::INSTRUCTIONS)), true);
        Configuration::updateValue(self::MAX_IMAGES, max(0, min(8, (int) Tools::getValue(self::MAX_IMAGES))));
        Configuration::updateValue(self::FILL_SHORT, (int) Tools::getValue(self::FILL_SHORT));
    }

    protected function renderForm()
    {
        $hasA = Configuration::get(self::KEY_ANTHROPIC) !== false && Configuration::get(self::KEY_ANTHROPIC) !== '';
        $hasO = Configuration::get(self::KEY_OPENAI) !== false && Configuration::get(self::KEY_OPENAI) !== '';
        $form = ['form' => [
            'legend' => ['title' => 'AI-productomschrijving', 'icon' => 'icon-magic'],
            'description' => 'Op de productpagina (tabblad Omschrijving) verschijnt een knop die de productfoto\'s en de al ingevulde tekst naar het gekozen model stuurt en een omschrijving plus korte omschrijving in de editor zet. Bestaande tekst wordt als bron van feiten gebruikt (referentienummers, maten, toepassing) en verbeterd, niet weggegooid. U kijkt het resultaat na en slaat het product op.',
            'input' => [
                ['type' => 'select', 'label' => 'Aanbieder', 'name' => self::PROVIDER, 'options' => ['query' => [['id' => 'anthropic', 'name' => 'Anthropic (Claude)'], ['id' => 'openai', 'name' => 'OpenAI (GPT)']], 'id' => 'id', 'name' => 'name']],
                ['type' => 'password', 'label' => 'Anthropic API-sleutel', 'name' => self::KEY_ANTHROPIC, 'desc' => $hasA ? 'Er is een sleutel opgeslagen. Leeg laten om die te behouden.' : 'Nog geen sleutel opgeslagen (console.anthropic.com > API keys).'],
                ['type' => 'text', 'label' => 'Anthropic model', 'name' => self::MODEL_ANTHROPIC, 'desc' => 'Bijvoorbeeld claude-sonnet-5-5 (goede prijs/kwaliteit) of claude-opus-5-5.'],
                ['type' => 'password', 'label' => 'OpenAI API-sleutel', 'name' => self::KEY_OPENAI, 'desc' => $hasO ? 'Er is een sleutel opgeslagen. Leeg laten om die te behouden.' : 'Nog geen sleutel opgeslagen (platform.openai.com > API keys).'],
                ['type' => 'text', 'label' => 'OpenAI model', 'name' => self::MODEL_OPENAI, 'desc' => 'Bijvoorbeeld gpt-5 of gpt-5-mini. Het model moet afbeeldingen kunnen lezen.'],
                ['type' => 'textarea', 'label' => 'Winkelcontext', 'name' => self::CONTEXT, 'rows' => 3, 'desc' => 'Wie bent u en wat verkoopt u? Bijvoorbeeld: "Je schrijft productomschrijvingen voor de webshop X, specialist in onderdelen voor klassieke auto\'s (nieuw, gereviseerd en gebruikt)." Wordt bij elke aanvraag meegestuurd.'],
                ['type' => 'textarea', 'label' => 'Schrijfinstructies', 'name' => self::INSTRUCTIONS, 'rows' => 4, 'desc' => 'Toon, taal, wat wel en niet te noemen. Wordt bij elke aanvraag meegestuurd.'],
                ['type' => 'text', 'label' => 'Maximum aantal foto\'s per aanvraag', 'name' => self::MAX_IMAGES, 'class' => 'fixed-width-xs', 'desc' => '0 t/m 8. Meer foto\'s = meer context, maar ook hogere kosten per aanvraag.'],
                ['type' => 'switch', 'label' => 'Ook de korte omschrijving invullen', 'name' => self::FILL_SHORT, 'is_bool' => true,
                    'values' => [['id' => 'short_on', 'value' => 1, 'label' => 'Ja'], ['id' => 'short_off', 'value' => 0, 'label' => 'Nee']]],
            ],
            // HelperForm renders extra buttons without a value; Tools::isSubmit() treats "" as not submitted, hence the onclick
            'buttons' => [['title' => 'Verbinding testen', 'name' => 'submitKcmAiTest', 'type' => 'submit', 'class' => 'btn btn-default pull-left', 'icon' => 'process-icon-refresh', 'js' => "this.value='1'"]],
            'submit' => ['title' => 'Opslaan'],
        ]];
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitKcmAi';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = ['fields_value' => [
            self::PROVIDER => Configuration::get(self::PROVIDER), self::KEY_ANTHROPIC => '', self::KEY_OPENAI => '',
            self::MODEL_ANTHROPIC => Configuration::get(self::MODEL_ANTHROPIC), self::MODEL_OPENAI => Configuration::get(self::MODEL_OPENAI),
            self::CONTEXT => Configuration::get(self::CONTEXT), self::INSTRUCTIONS => Configuration::get(self::INSTRUCTIONS), self::MAX_IMAGES => (int) Configuration::get(self::MAX_IMAGES), self::FILL_SHORT => (int) Configuration::get(self::FILL_SHORT),
        ], 'languages' => $this->context->controller->getLanguages(), 'id_language' => $this->context->language->id];
        return $helper->generateForm([$form]);
    }

    /* ---------- product page button ---------- */

    public function hookDisplayBackOfficeHeader(array $params)
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (!preg_match('#/sell/catalog/products(-v2)?/\d+/edit#', $uri)) {
            return '';
        }
        // host-relative so it works whether the back office is opened with or without www
        $ajax = preg_replace('#^https?://[^/]+#', '', $this->context->link->getAdminLink('AdminKcmAiDescribe', true, [], ['ajax' => 1, 'action' => 'generate']));
        $cfg = ['url' => $ajax, 'idLang' => (int) Configuration::get('PS_LANG_DEFAULT'), 'fillShort' => (bool) Configuration::get(self::FILL_SHORT),
            'configured' => KcmAiClient::isConfigured(), 'provider' => Configuration::get(self::PROVIDER)];
        return '<script>window.kcmAiDescribe = ' . json_encode($cfg) . ';</script>'
            . '<script src="' . $this->_path . 'views/js/aidescribe.js?v=' . $this->version . '" defer></script>';
    }
}

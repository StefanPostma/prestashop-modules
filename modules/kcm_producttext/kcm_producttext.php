<?php
/**
 * kcm_producttext: bulk editor for product descriptions.
 *
 * One back-office page (Catalogus > Productteksten) that lists all active products with the length of their
 * description, generates AI drafts in batches (through the kcm_aidescribe client and prompt, so the same
 * safety rules apply), and lets the admin review, edit and save the texts one by one. Saving writes only
 * description and description_short of product_lang; the previous text is kept and can be restored.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/KcmProductText.php';

class Kcm_Producttext extends Module
{
    const MIN_CHARS = 'KCM_PT_MIN_CHARS';   // below this a description counts as "too short"
    const BATCH = 'KCM_PT_BATCH';           // products per ajax batch
    const MAX_IMAGES = 'KCM_PT_MAX_IMAGES'; // photos sent along per product
    const TABLE = 'kcm_producttext';

    public function __construct()
    {
        $this->name = 'kcm_producttext';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'StefanPostma';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->dependencies = ['kcm_aidescribe'];
        parent::__construct();
        $this->displayName = 'Productteksten';
        $this->description = 'Overzicht van alle productomschrijvingen met AI-concepten die je per product nakijkt en opslaat.';
    }

    public function install()
    {
        return parent::install() && $this->ensureInstalled();
    }

    public function ensureInstalled(): bool
    {
        $ok = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_product` INT UNSIGNED NOT NULL,
            `id_lang` INT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT "queued",
            `draft_short` TEXT NULL,
            `draft_desc` TEXT NULL,
            `prev_short` TEXT NULL,
            `prev_desc` TEXT NULL,
            `hint` VARCHAR(500) NULL,
            `error` VARCHAR(500) NULL,
            `date_generated` DATETIME NULL,
            `date_saved` DATETIME NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_product`, `id_lang`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
        if (!Tab::getIdFromClassName('AdminKcmProductText')) {
            $tab = new Tab();
            $tab->class_name = 'AdminKcmProductText';
            $tab->module = $this->name;
            $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
            $tab->active = true;
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = 'Productteksten';
            }
            $ok = $tab->add() && $ok;
        }
        foreach ([self::MIN_CHARS => 200, self::BATCH => 3, self::MAX_IMAGES => 2] as $k => $v) {
            if (Configuration::get($k) === false) {
                $ok = Configuration::updateValue($k, $v) && $ok;
            }
        }
        return $ok;
    }

    public function uninstall()
    {
        if ($id = (int) Tab::getIdFromClassName('AdminKcmProductText')) {
            (new Tab($id))->delete();
        }
        foreach ([self::MIN_CHARS, self::BATCH, self::MAX_IMAGES] as $k) {
            Configuration::deleteByName($k);
        }
        // the table is kept on purpose: it holds the previous texts (restore) and the drafts
        return parent::uninstall();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminKcmProductText'));
    }
}

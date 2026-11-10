<?php
/** Back office page Catalogus > Productteksten: overview with filters and batch generation, and the review screen per product. */
class AdminKcmProductTextController extends ModuleAdminController
{
    /** @var int */
    private $idLang;

    public function __construct()
    {
        $this->bootstrap = true;
        if (Tools::getValue('ajax')) {
            ob_start();
        }
        parent::__construct();
        $this->toolbar_title = 'Productteksten';
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        require_once _PS_MODULE_DIR_ . 'kcm_aidescribe/kcm_aidescribe.php';
        require_once _PS_MODULE_DIR_ . 'kcm_aidescribe/classes/KcmAiClient.php';
        require_once _PS_MODULE_DIR_ . 'kcm_aidescribe/classes/KcmAiDescriber.php';
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'kcm_producttext/views/css/producttext.css');
        $this->addJS(_MODULE_DIR_ . 'kcm_producttext/views/js/producttext.js');
        if (Tools::getValue('id_product')) {
            $this->addJS(_PS_JS_DIR_ . 'tiny_mce/tinymce.min.js');
            $this->addJS(_PS_JS_DIR_ . 'admin/tinymce.inc.js');
        }
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();
        if (Tools::getValue('id_product')) {
            $this->page_header_toolbar_btn['back'] = ['href' => $this->listUrl(), 'desc' => 'Terug naar overzicht', 'icon' => 'process-icon-back'];
        } else {
            $this->page_header_toolbar_btn['settings'] = ['href' => '#kcpt-settings', 'desc' => 'Instellingen', 'icon' => 'process-icon-cogs'];
        }
    }

    /* ---------- URLs (host-relative, see kcm_paruzzi: the BO may run without www) ---------- */

    private function rel(string $url): string
    {
        return preg_replace('#^https?://[^/]+#', '', $url);
    }

    private function filterParams(): array
    {
        $filter = (string) Tools::getValue('filter', 'short');
        if (!in_array($filter, KcmProductText::FILTERS, true)) {
            $filter = 'short';
        }
        return ['filter' => $filter, 'cat' => (int) Tools::getValue('cat'), 'q' => trim((string) Tools::getValue('q'))];
    }

    private function listUrl(array $extra = []): string
    {
        $params = array_filter($this->filterParams() + $extra, function ($v) { return $v !== '' && $v !== 0 && $v !== null; });
        return $this->rel($this->context->link->getAdminLink('AdminKcmProductText', true, [], $params));
    }

    private function reviewUrl(int $idProduct): string
    {
        return $this->listUrl(['id_product' => $idProduct]);
    }

    /* ---------- POST actions ---------- */

    public function postProcess()
    {
        $idProduct = (int) Tools::getValue('id_product');
        if (Tools::isSubmit('submitKcmQueue')) {
            $ids = (array) Tools::getValue('ids', []);
            $n = KcmProductText::queue($ids, $this->idLang);
            $this->confirmations[] = $n . ' product(en) in de wachtrij gezet. De concepten worden nu gemaakt.';
            Tools::redirectAdmin($this->listUrl(['run' => 1, 'conf' => 4]));
        }
        if (Tools::isSubmit('submitKcmQueueFilter')) {
            $f = $this->filterParams();
            $n = KcmProductText::queue(KcmProductText::ids($this->idLang, $f['filter'], $f['cat'], $f['q']), $this->idLang);
            Tools::redirectAdmin($this->listUrl(['run' => 1, 'conf' => 4]));
        }
        if (Tools::isSubmit('submitKcmSettings')) {
            Configuration::updateValue(Kcm_Producttext::MIN_CHARS, max(50, (int) Tools::getValue('min_chars')));
            Configuration::updateValue(Kcm_Producttext::BATCH, max(1, min(6, (int) Tools::getValue('batch'))));
            Configuration::updateValue(Kcm_Producttext::MAX_IMAGES, max(0, min(4, (int) Tools::getValue('max_images'))));
            Tools::redirectAdmin($this->listUrl(['conf' => 6]));
        }
        if ($idProduct && (Tools::isSubmit('submitKcmSave') || Tools::isSubmit('submitKcmSaveShort'))) {
            $onlyShort = Tools::isSubmit('submitKcmSaveShort');
            $res = KcmProductText::save($idProduct, $this->idLang, (string) Tools::getValue('draft_short'), (string) Tools::getValue('draft_desc'), $onlyShort);
            if (!$res['ok']) {
                $this->errors[] = $res['message'];
                return;
            }
            $next = (int) Tools::getValue('next_id');
            if (Tools::getValue('goto_next') && $next) {
                Tools::redirectAdmin($this->reviewUrl($next) . '&saved=' . $idProduct);
            }
            Tools::redirectAdmin($this->reviewUrl($idProduct) . '&saved=' . $idProduct);
        }
        if ($idProduct && Tools::isSubmit('submitKcmDiscard')) {
            KcmProductText::discard($idProduct, $this->idLang);
            $next = (int) Tools::getValue('next_id');
            Tools::redirectAdmin($next ? $this->reviewUrl($next) : $this->listUrl());
        }
        if ($idProduct && Tools::isSubmit('submitKcmRestore')) {
            $res = KcmProductText::restore($idProduct, $this->idLang);
            if (!$res['ok']) {
                $this->errors[] = $res['message'];
                return;
            }
            Tools::redirectAdmin($this->reviewUrl($idProduct) . '&restored=1');
        }
        parent::postProcess();
    }

    /* ---------- pages ---------- */

    public function initContent()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $f = $this->filterParams();
        $this->context->smarty->assign([
            'filter' => $f['filter'], 'cat' => $f['cat'], 'q' => $f['q'],
            'list_url' => $this->listUrl(),
            'base_url' => $this->rel($this->context->link->getAdminLink('AdminKcmProductText')),
            'ajax_url' => $this->rel($this->context->link->getAdminLink('AdminKcmProductText', true, [], ['ajax' => 1])),
            'ai_configured' => KcmAiClient::isConfigured(),
        ]);
        if ($idProduct) {
            $this->renderReview($idProduct, $f);
        } else {
            $this->renderOverview($f);
        }
        parent::initContent();
    }

    private function renderOverview(array $f): void
    {
        $page = max(1, (int) Tools::getValue('page', 1));
        $total = KcmProductText::count($this->idLang, $f['filter'], $f['cat'], $f['q']);
        $rows = KcmProductText::overview($this->idLang, $f['filter'], $f['cat'], $f['q'], $page);
        foreach ($rows as &$r) {
            $r['review_url'] = $this->reviewUrl((int) $r['id_product']);
            $r['edit_url'] = $this->context->link->getAdminLink('AdminProducts', true, ['route' => 'admin_products_edit', 'productId' => (int) $r['id_product']]);
        }
        unset($r);
        $stats = KcmProductText::stats($this->idLang);
        $filters = [];
        foreach (KcmProductText::FILTERS as $key) {
            $filters[$key] = ['url' => $this->rel($this->context->link->getAdminLink('AdminKcmProductText', true, [], array_filter(['filter' => $key, 'cat' => $f['cat'], 'q' => $f['q']]))),
                'count' => $key === $f['filter'] ? $total : KcmProductText::count($this->idLang, $key, $f['cat'], $f['q'])];
        }
        $this->context->smarty->assign([
            'rows' => $rows, 'stats' => $stats, 'filters' => $filters, 'categories' => KcmProductText::categories($this->idLang),
            'page' => $page, 'pages' => max(1, (int) ceil($total / KcmProductText::PER_PAGE)), 'total' => $total, 'per_page' => KcmProductText::PER_PAGE,
            'run' => (int) Tools::getValue('run') || $stats['queued'] > 0,
            'settings' => ['min_chars' => (int) Configuration::get(Kcm_Producttext::MIN_CHARS), 'batch' => (int) Configuration::get(Kcm_Producttext::BATCH), 'max_images' => (int) Configuration::get(Kcm_Producttext::MAX_IMAGES)],
        ]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'kcm_producttext/views/templates/admin/overview.tpl');
    }

    private function renderReview(int $idProduct, array $f): void
    {
        $d = KcmProductText::detail($idProduct, $this->idLang);
        if (!$d) {
            $this->errors[] = 'Product niet gevonden.';
            return;
        }
        $ids = KcmProductText::ids($this->idLang, $f['filter'], $f['cat'], $f['q']);
        $pos = array_search($idProduct, $ids, true);
        $prev = $pos !== false && $pos > 0 ? $ids[$pos - 1] : 0;
        $next = $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : ($pos === false && $ids ? $ids[0] : 0);
        $concept = $d['concept'];
        $draftShort = $concept && $concept['draft_short'] !== null ? $concept['draft_short'] : $d['description_short'];
        $draftDesc = $concept && $concept['draft_desc'] !== null ? $concept['draft_desc'] : $d['description'];
        $facts = array_filter([$d['reference'] !== '' ? 'Ref. ' . $d['reference'] : '', $d['mpn'] !== '' ? 'MPN ' . $d['mpn'] : '', (string) $d['brand'],
            trim(($d['parent_category'] ? $d['parent_category'] . ' › ' : '') . (string) $d['category']), '€ ' . number_format((float) $d['price'], 2, ',', '.'), 'voorraad ' . (int) $d['quantity']]);
        $this->context->smarty->assign([
            'facts' => implode(' · ', $facts),
            'd' => $d, 'concept' => $concept,
            'draft_short_plain' => trim(strip_tags(html_entity_decode((string) $draftShort, ENT_QUOTES, 'UTF-8'))),
            'draft_desc' => (string) $draftDesc,
            'draft_desc_len' => mb_strlen(trim(strip_tags(html_entity_decode((string) $draftDesc, ENT_QUOTES, 'UTF-8')))),
            'has_draft' => $concept && $concept['status'] === 'draft',
            'prev_url' => $prev ? $this->reviewUrl($prev) : '', 'next_url' => $next ? $this->reviewUrl($next) : '', 'next_id' => $next,
            'position' => $pos === false ? 0 : $pos + 1, 'count' => count($ids),
            'current_url' => $this->reviewUrl($idProduct),
            'edit_url' => $this->context->link->getAdminLink('AdminProducts', true, ['route' => 'admin_products_edit', 'productId' => $idProduct]),
            'front_url' => $this->context->link->getProductLink($idProduct, null, null, null, $this->idLang),
            'saved' => (int) Tools::getValue('saved'), 'restored' => (int) Tools::getValue('restored'),
            'iso_lang' => Language::getIsoById($this->idLang),
        ]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'kcm_producttext/views/templates/admin/review.tpl');
    }

    /* ---------- ajax ---------- */

    private function ajaxJson($data)
    {
        $stray = ob_get_level() ? (string) ob_get_contents() : '';
        if (trim($stray) !== '') {
            @file_put_contents(_PS_ROOT_DIR_ . '/var/logs/kcm_producttext-ajax.log', '[' . date('c') . '] ' . Tools::getValue('action') . ": stray output before JSON:\n" . substr($stray, 0, 4000) . "\n\n", FILE_APPEND);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    /** ajax: action=runBatch -> generates drafts for the next queued products (stays under the 60 s execution limit). */
    public function ajaxProcessRunBatch()
    {
        @set_time_limit(55);
        try {
            $this->ajaxJson(['ok' => true] + KcmProductText::runBatch($this->idLang, (int) Configuration::get(Kcm_Producttext::BATCH), 35));
        } catch (Throwable $e) {
            $this->ajaxJson(['ok' => false, 'message' => $e->getMessage(), 'remaining' => KcmProductText::queuedCount($this->idLang)]);
        }
    }

    /** ajax: action=regenerate&id_product=N&hint=... -> a new draft for one product, returned for the editor. */
    public function ajaxProcessRegenerate()
    {
        @set_time_limit(55);
        $idProduct = (int) Tools::getValue('id_product');
        $hint = trim((string) Tools::getValue('hint'));
        KcmProductText::setHint($idProduct, $this->idLang, $hint);
        try {
            $res = KcmProductText::generate($idProduct, $this->idLang, $hint);
            if ($res['ok']) {
                $res['description_short_plain'] = trim(strip_tags(html_entity_decode($res['description_short'], ENT_QUOTES, 'UTF-8')));
            }
            $this->ajaxJson($res);
        } catch (Throwable $e) {
            $this->ajaxJson(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /** ajax: action=status -> queue counters for the banner. */
    public function ajaxProcessStatus()
    {
        $this->ajaxJson(['ok' => true] + KcmProductText::stats($this->idLang));
    }
}

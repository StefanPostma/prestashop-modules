<?php
/** Ajax endpoint for the product-page button: builds the prompt from the product, its photos and the current editor text. */
class AdminKcmAiDescribeController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        if (Tools::getValue('ajax')) {
            ob_start();
        }
        parent::__construct();
    }

    public function initContent()
    {
        $this->errors[] = 'Deze pagina heeft geen inhoud; de module werkt via de knop op de productpagina.';
        parent::initContent();
    }

    public function ajaxProcessGenerate()
    {
        @set_time_limit(180);
        try {
            $this->json($this->generate());
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    private function generate(): array
    {
        $idProduct = (int) Tools::getValue('id_product');
        $product = new Product($idProduct, false, (int) Configuration::get('PS_LANG_DEFAULT'));
        if (!Validate::isLoadedObject($product)) {
            return ['ok' => false, 'message' => 'Product niet gevonden. Sla het product eerst op.'];
        }
        $name = trim((string) Tools::getValue('name')) ?: (string) $product->name;
        $reference = trim((string) Tools::getValue('reference')) ?: (string) $product->reference;
        $mpn = trim((string) Tools::getValue('mpn')) ?: (string) $product->mpn;
        $brand = $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        $category = '';
        if ($product->id_category_default) {
            $cat = new Category((int) $product->id_category_default, (int) Configuration::get('PS_LANG_DEFAULT'));
            $category = Validate::isLoadedObject($cat) ? (string) $cat->name : '';
        }

        $p = ['name' => $name, 'reference' => $reference, 'mpn' => $mpn, 'brand' => $brand, 'category' => $category,
            'existing' => (string) Tools::getValue('description'), 'existingShort' => (string) Tools::getValue('description_short')];
        $describer = new KcmAiDescriber();
        $files = $this->imageFiles($idProduct, (int) Configuration::get(Kcm_Aidescribe::MAX_IMAGES));
        $client = KcmAiClient::fromSettings();
        try {
            $answer = $client->complete($describer->systemPrompt(), $describer->userPrompt($p), $files);
        } finally {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        $result = $describer->checkAnswer($answer, $p);
        if ($result['ok']) {
            $result += ['images' => count($files), 'provider' => Configuration::get(Kcm_Aidescribe::PROVIDER)];
        }
        return $result;
    }

    /** Resized JPEG copies (max 1024 px) of the product images, cover first. */
    private function imageFiles(int $idProduct, int $max): array
    {
        $files = [];
        if ($max <= 0) {
            return $files;
        }
        $images = Image::getImages((int) Configuration::get('PS_LANG_DEFAULT'), $idProduct);
        usort($images, function ($a, $b) { return (int) $b['cover'] - (int) $a['cover'] ?: (int) $a['position'] - (int) $b['position']; });
        foreach (array_slice($images, 0, $max) as $img) {
            $image = new Image((int) $img['id_image']);
            $src = _PS_PRODUCT_IMG_DIR_ . $image->getExistingImgPath() . '.jpg';
            if (!is_file($src)) {
                continue;
            }
            $tmp = tempnam(_PS_TMP_IMG_DIR_ ?: sys_get_temp_dir(), 'kcmai') . '.jpg';
            if (ImageManager::resize($src, $tmp, 1024, 1024, 'jpg', true)) {
                $files[] = $tmp;
            }
        }
        return $files;
    }

    private function json(array $data): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }
}

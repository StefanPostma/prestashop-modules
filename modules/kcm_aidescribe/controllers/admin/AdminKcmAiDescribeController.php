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
        $existing = trim(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], "\n", (string) Tools::getValue('description'))));
        $existingShort = trim(strip_tags((string) Tools::getValue('description_short')));
        $brand = $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        $category = '';
        if ($product->id_category_default) {
            $cat = new Category((int) $product->id_category_default, (int) Configuration::get('PS_LANG_DEFAULT'));
            $category = Validate::isLoadedObject($cat) ? (string) $cat->name : '';
        }

        $context = trim((string) Configuration::get(Kcm_Aidescribe::CONTEXT)) ?: 'Je schrijft productomschrijvingen voor de webshop ' . Configuration::get('PS_SHOP_NAME') . '.';
        $system = $context . "\n"
            . "Regels:\n- Gebruik alleen feiten die op de foto's te zien zijn of in de aangeleverde gegevens staan. Verzin geen onderdeelnummers, maten, bouwjaren of toepassingen.\n"
            . "- Als er al een tekst is aangeleverd, zijn alle nummers en feiten daarin leidend: behoud ze en verbeter de tekst (structuur, volledigheid, taal).\n"
            . "- Omschrijving: HTML met alleen <p>, <ul>, <li>, <strong>; 2 tot 4 korte alinea's, eventueel een lijst met kenmerken. Geen koppen, geen prijs, geen verzendinformatie.\n"
            . "- Korte omschrijving: 1 of 2 zinnen platte tekst, maximaal 300 tekens.\n"
            . "- Antwoord uitsluitend met JSON in deze vorm: {\"description\": \"...\", \"description_short\": \"...\"}\n"
            . trim((string) Configuration::get(Kcm_Aidescribe::INSTRUCTIONS));

        $user = "Productgegevens:\n- Naam: $name\n" . ($reference !== '' ? "- Referentie: $reference\n" : '') . ($mpn !== '' ? "- Fabrikantnummer / VW-nummer: $mpn\n" : '')
            . ($brand !== '' ? "- Merk: $brand\n" : '') . ($category !== '' ? "- Categorie: $category\n" : '')
            . ($existing !== '' ? "\nAangeleverde tekst (feiten behouden, tekst verbeteren):\n$existing\n" : "\nEr is nog geen omschrijving; schrijf een nieuwe op basis van de foto's en de gegevens.\n")
            . ($existingShort !== '' ? "\nHuidige korte omschrijving:\n$existingShort\n" : '')
            . "\nSchrijf nu de omschrijving en de korte omschrijving.";

        $files = $this->imageFiles($idProduct, (int) Configuration::get(Kcm_Aidescribe::MAX_IMAGES));
        $client = KcmAiClient::fromSettings();
        $answer = $client->complete($system, $user, $files);
        foreach ($files as $f) {
            @unlink($f);
        }
        $data = $this->parseJson($answer);
        if (!$data || empty($data['description'])) {
            return ['ok' => false, 'message' => 'Het model gaf geen bruikbaar antwoord: ' . Tools::substr($answer, 0, 200)];
        }
        $description = Tools::purifyHTML((string) $data['description']);
        $description = preg_replace('/\s*style="[^"]*"/', '', $description);
        $short = Tools::substr(trim(strip_tags((string) ($data['description_short'] ?? ''))), 0, 400);
        return ['ok' => true, 'description' => $description, 'description_short' => $short !== '' ? '<p>' . Tools::safeOutput($short) . '</p>' : '', 'images' => count($files), 'provider' => $client ? Configuration::get(Kcm_Aidescribe::PROVIDER) : ''];
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

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        return is_array($data) ? $data : null;
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

<?php
/**
 * Feed endpoint: /index.php?fc=module&module=kcm_gfeed&controller=feed&token=…
 * RSS 2.0 with the Google Merchant Center namespace; one <item> per product or per combination.
 */
class Kcm_GfeedFeedModuleFrontController extends ModuleFrontController
{
    /** @var Kcm_Gfeed */
    public $module;
    private int $idLang;
    private int $idShop;
    private string $currency;
    private array $pickupRefs;
    private float $maxWeight;
    private string $labelShip;
    private string $labelPickup;
    private string $googleCategory;
    private bool $skipZero;
    private float $defaultWeight;
    private bool $skipOutOfStock;
    private string $pickupMode;
    private string $imageType;
    /** @var array<int, int[]> id_product => carrier id_reference restriction */
    private array $carrierRestrictions = [];
    private array $categoryPaths = [];
    private array $manufacturers = [];

    public function init()
    {
        parent::init();
        $token = (string) Tools::getValue('token');
        if ($token === '' || !hash_equals((string) Configuration::get(Kcm_Gfeed::TOKEN), $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('forbidden');
        }
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->idShop = (int) $this->context->shop->id;
        $this->currency = (string) Currency::getDefaultCurrency()->iso_code;
        $this->pickupRefs = $this->module->pickupCarrierRefs();
        $this->maxWeight = (float) Configuration::get(Kcm_Gfeed::MAX_WEIGHT);
        $this->labelShip = (string) Configuration::get(Kcm_Gfeed::LABEL_SHIP);
        $this->labelPickup = (string) Configuration::get(Kcm_Gfeed::LABEL_PICKUP);
        $this->googleCategory = (string) (int) Configuration::get(Kcm_Gfeed::GOOGLE_CATEGORY);
        $this->skipZero = (bool) Configuration::get(Kcm_Gfeed::SKIP_ZERO_PRICE);
        $pickupMode = (string) Configuration::get(Kcm_Gfeed::PICKUP_MODE);
        $this->pickupMode = in_array($pickupMode, ['skip', 'local', 'send'], true) ? $pickupMode : 'skip';
        $skipOos = Configuration::get(Kcm_Gfeed::SKIP_OUT_OF_STOCK);
        $this->skipOutOfStock = $skipOos === false ? true : (bool) $skipOos;
        $defaultWeight = Configuration::get(Kcm_Gfeed::DEFAULT_WEIGHT);
        $this->defaultWeight = $defaultWeight === false ? 1.0 : (float) $defaultWeight; // setting added in 1.0.1: installed copies may not have it yet
        $this->imageType = ImageType::getFormattedName('large');
        $this->loadCarrierRestrictions();
        foreach (Manufacturer::getManufacturers(false, 0, false) as $m) {
            $this->manufacturers[(int) $m['id_manufacturer']] = (string) $m['name'];
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: no-cache');
        $this->renderFeed();
        exit;
    }

    private function loadCarrierRestrictions(): void
    {
        $rows = Db::getInstance()->executeS('SELECT pc.id_product, pc.id_carrier_reference FROM `' . _DB_PREFIX_ . 'product_carrier` pc WHERE pc.id_shop = ' . $this->idShop);
        foreach ($rows ?: [] as $r) {
            $this->carrierRestrictions[(int) $r['id_product']][] = (int) $r['id_carrier_reference'];
        }
    }

    private function renderFeed(): void
    {
        $shopName = trim((string) Configuration::get('PS_SHOP_NAME'));
        $x = new XMLWriter();
        $x->openURI('php://output');
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('rss');
        $x->writeAttribute('version', '2.0');
        $x->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $x->startElement('channel');
        $x->writeElement('title', $shopName);
        $x->writeElement('link', $this->context->link->getPageLink('index', true));
        $x->writeElement('description', $shopName . ' product feed');
        $x->flush();

        $ids = Db::getInstance()->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop . '
            WHERE ps.active = 1 AND ps.available_for_order = 1 AND ps.visibility != "none" ORDER BY p.id_product');
        $n = 0;
        foreach ($ids ?: [] as $row) {
            $product = new Product((int) $row['id_product'], false, $this->idLang, $this->idShop);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }
            $n += $this->writeProduct($x, $product);
            if ($n % 50 === 0) {
                $x->flush();
            }
        }
        $x->endElement(); // channel
        $x->endElement(); // rss
        $x->endDocument();
        $x->flush();
    }

    /** @return int number of items written */
    private function writeProduct(XMLWriter $x, Product $product): int
    {
        $cover = Product::getCover($product->id);
        $idCover = (int) ($cover['id_image'] ?? 0);
        if (!$idCover) {
            return 0; // Google rejects items without image
        }
        $label = $this->shippingLabel($product);
        if ($this->pickupMode === 'skip' && $label === $this->labelPickup) {
            return 0; // no shipping price possible, Google would disapprove the item
        }
        $base = [
            'link' => $this->context->link->getProductLink($product, null, null, null, $this->idLang, $this->idShop),
            'title' => $this->text($product->name, 150),
            'description' => $this->description($product),
            'brand' => $this->manufacturers[(int) $product->id_manufacturer] ?? '',
            'product_type' => $this->categoryPath((int) $product->id_category_default),
            'label' => $label,
            'pickupOnly' => $label === $this->labelPickup,
            'weight' => (float) $product->weight,
            'cover' => $idCover,
            'link_rewrite' => is_array($product->link_rewrite) ? reset($product->link_rewrite) : $product->link_rewrite,
        ];
        $combinations = $product->hasAttributes() ? $this->combinations($product) : [];
        if (!$combinations) {
            return $this->writeItem($x, $product, 0, $base, []);
        }
        $written = 0;
        foreach ($combinations as $ida => $c) {
            $written += $this->writeItem($x, $product, (int) $ida, $base, $c);
        }

        return $written;
    }

    /** @return array<int, array{names: string[], reference: string, ean13: string, mpn: string, image: int, weight: float}> */
    private function combinations(Product $product): array
    {
        $out = [];
        foreach ($product->getAttributeCombinations($this->idLang) as $r) {
            $ida = (int) $r['id_product_attribute'];
            $out[$ida] = $out[$ida] ?? ['names' => [], 'reference' => (string) $r['reference'], 'ean13' => (string) $r['ean13'], 'mpn' => (string) ($r['mpn'] ?? ''), 'image' => 0, 'weight' => (float) $r['weight']];
            $out[$ida]['names'][] = $r['group_name'] . ' ' . $r['attribute_name'];
        }
        foreach ($out as $ida => $c) {
            $images = Product::_getAttributeImageAssociations($ida);
            $out[$ida]['image'] = (int) ($images[0] ?? 0);
        }

        return $out;
    }

    private function writeItem(XMLWriter $x, Product $product, int $ida, array $base, array $c): int
    {
        $price = Product::getPriceStatic($product->id, true, $ida ?: null, 2);
        if ($price === null || ($this->skipZero && $price <= 0)) {
            return 0;
        }
        $id = $ida ? $product->id . '-' . $ida : (string) $product->id;
        $title = $base['title'] . ($c ? ' - ' . $this->text(implode(', ', $c['names']), 60) : '');
        $link = $base['link'] . ($ida ? (strpos($base['link'], '?') === false ? '?' : '&') . 'id_product_attribute=' . $ida : '');
        $idImage = !empty($c['image']) ? (int) $c['image'] : (int) $base['cover'];
        $reference = ($c['reference'] ?? '') !== '' ? $c['reference'] : (string) $product->reference;
        $ean = ($c['ean13'] ?? '') !== '' ? $c['ean13'] : (string) $product->ean13;
        $mpn = ($c['mpn'] ?? '') !== '' ? $c['mpn'] : ((string) $product->mpn !== '' ? (string) $product->mpn : $reference);
        $weight = $base['weight'] + (float) ($c['weight'] ?? 0);
        if ($weight <= 0) {
            $weight = $this->defaultWeight; // Google needs a weight for weight-based rates; the shop charges the lowest band for weightless products too
        }
        $qty = StockAvailable::getQuantityAvailableByProduct($product->id, $ida ?: null, $this->idShop);
        if ($this->skipOutOfStock && $qty <= 0) {
            return 0;
        }

        $x->startElement('item');
        $x->writeElement('g:id', $id);
        if ($ida) {
            $x->writeElement('g:item_group_id', (string) $product->id);
        }
        $x->writeElement('g:title', $title);
        $x->writeElement('g:description', $base['description']);
        $x->writeElement('g:link', $link);
        $x->writeElement('g:image_link', $this->imageUrl($base['link_rewrite'], $idImage));
        $extra = 0;
        foreach (Image::getImages($this->idLang, $product->id, $ida ?: null) as $img) {
            if ((int) $img['id_image'] !== $idImage && $extra < 10) {
                $x->writeElement('g:additional_image_link', $this->imageUrl($base['link_rewrite'], (int) $img['id_image']));
                ++$extra;
            }
        }
        $x->writeElement('g:condition', 'new');
        $x->writeElement('g:availability', $this->availability($product, $qty));
        // markdown: the regular price goes in g:price and the reduced price in g:sale_price, so Google shows the discount
        $regular = (float) Product::getPriceStatic($product->id, true, $ida ?: null, 2, null, false, false);
        if ($regular > $price + 0.004) {
            $x->writeElement('g:price', number_format($regular, 2, '.', '') . ' ' . $this->currency);
            $x->writeElement('g:sale_price', number_format($price, 2, '.', '') . ' ' . $this->currency);
            $window = $this->saleWindow((int) $product->id, $ida);
            if ($window !== '') {
                $x->writeElement('g:sale_price_effective_date', $window);
            }
        } else {
            $x->writeElement('g:price', number_format($price, 2, '.', '') . ' ' . $this->currency);
        }
        if ($base['brand'] !== '') {
            $x->writeElement('g:brand', $base['brand']);
        }
        $gtin = preg_match('/^\d{8}$|^\d{12,14}$/', $ean) ? $ean : '';
        if ($gtin !== '') {
            $x->writeElement('g:gtin', $gtin);
        }
        if ($mpn !== '') {
            $x->writeElement('g:mpn', $this->text($mpn, 70));
        }
        if ($gtin === '' && ($base['brand'] === '' || $mpn === '')) {
            $x->writeElement('g:identifier_exists', 'no');
        }
        if ($this->googleCategory !== '0') {
            $x->writeElement('g:google_product_category', $this->googleCategory);
        }
        if ($base['product_type'] !== '') {
            $x->writeElement('g:product_type', $base['product_type']);
        }
        if ($weight > 0) {
            $x->writeElement('g:shipping_weight', rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.') . ' kg');
        }
        $x->writeElement('g:shipping_label', $base['label']);
        if ($base['pickupOnly'] && $this->pickupMode === 'local') {
            // collect-only: no online listing (it would be disapproved for a missing shipping price), shown through the local inventory feed
            $x->writeElement('g:excluded_destination', 'Free_listings');
            $x->writeElement('g:excluded_destination', 'Shopping_ads');
        }
        $x->endElement();

        return 1;
    }

    /** ISO 8601 "from/to" of the active specific price (only when both dates are set), empty otherwise. */
    private function saleWindow(int $idProduct, int $ida): string
    {
        $sp = SpecificPrice::getSpecificPrice(
            $idProduct,
            $this->idShop,
            (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            (int) Configuration::get('PS_UNIDENTIFIED_GROUP'),
            1,
            $ida ?: null
        );
        $empty = '0000-00-00 00:00:00';
        if (!is_array($sp) || empty($sp['from']) || empty($sp['to']) || $sp['from'] === $empty || $sp['to'] === $empty) {
            return '';
        }
        $tz = new DateTimeZone(date_default_timezone_get());
        $from = (new DateTime($sp['from'], $tz))->format('Y-m-d\TH:iO');
        $to = (new DateTime($sp['to'], $tz))->format('Y-m-d\TH:iO');

        return $from . '/' . $to;
    }

    private function shippingLabel(Product $product): string
    {
        if ($this->maxWeight > 0 && (float) $product->weight > $this->maxWeight) {
            return $this->labelPickup;
        }
        $refs = $this->carrierRestrictions[(int) $product->id] ?? [];
        if ($refs && $this->pickupRefs && !array_diff($refs, $this->pickupRefs)) {
            return $this->labelPickup;
        }

        return $this->labelShip;
    }

    private function availability(Product $product, int $qty): string
    {
        if ($qty > 0) {
            return 'in_stock';
        }

        return Product::isAvailableWhenOutOfStock((int) $product->out_of_stock) ? 'backorder' : 'out_of_stock';
    }

    private function imageUrl(string $linkRewrite, int $idImage): string
    {
        $url = $this->context->link->getImageLink($linkRewrite, (string) $idImage, $this->imageType);

        return preg_replace('#^//#', 'https://', (string) $url);
    }

    private function description(Product $product): string
    {
        $text = $this->text($product->description, 5000);
        if ($text === '') {
            $text = $this->text($product->description_short, 5000);
        }

        return $text !== '' ? $text : $this->text($product->name, 150);
    }

    private function text($value, int $max): string
    {
        $value = is_array($value) ? (string) reset($value) : (string) $value;
        $value = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\s*\n\s*/", "\n", $value)));
        if (mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max - 1)) . '…';
        }

        return $value;
    }

    private function categoryPath(int $idCategory): string
    {
        if (!isset($this->categoryPaths[$idCategory])) {
            $names = [];
            $category = new Category($idCategory, $this->idLang, $this->idShop);
            if (Validate::isLoadedObject($category)) {
                foreach (array_reverse($category->getParentsCategories($this->idLang)) as $c) {
                    if (!(int) $c['is_root_category'] && (int) $c['id_category'] !== (int) Configuration::get('PS_HOME_CATEGORY')) {
                        $names[] = $c['name'];
                    }
                }
            }
            $this->categoryPaths[$idCategory] = $this->text(implode(' > ', $names), 750);
        }

        return $this->categoryPaths[$idCategory];
    }
}

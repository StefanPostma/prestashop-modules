<?php
/**
 * Local inventory feed: /index.php?fc=module&module=kcm_gfeed&controller=localinventory&token=…
 * Tab-delimited text for Google Merchant Center (data source type "local inventory"): one row per product or
 * combination with store_code, id (same ids as the product feed), availability and quantity. Prices are left out:
 * the shop prices are the same in store and online, so Merchant Center uses the price of the product feed.
 */
class Kcm_GfeedLocalinventoryModuleFrontController extends ModuleFrontController
{
    /** @var Kcm_Gfeed */
    public $module;

    public function init()
    {
        parent::init();
        $token = (string) Tools::getValue('token');
        if ($token === '' || !hash_equals((string) Configuration::get(Kcm_Gfeed::TOKEN), $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('forbidden');
        }
        $storeCode = trim((string) Configuration::get(Kcm_Gfeed::STORE_CODE));
        if ($storeCode === '') {
            http_response_code(409);
            header('Content-Type: text/plain; charset=utf-8');
            exit('store code not configured (module settings)');
        }
        @set_time_limit(900);
        @ini_set('memory_limit', '512M');
        $idShop = (int) $this->context->shop->id;
        $skipOos = Configuration::get(Kcm_Gfeed::SKIP_OUT_OF_STOCK);
        $skipOos = $skipOos === false ? true : (bool) $skipOos;
        $skipZero = (bool) Configuration::get(Kcm_Gfeed::SKIP_ZERO_PRICE);

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        echo "store_code\tid\tavailability\tquantity\n";
        $ids = Db::getInstance()->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . '
            WHERE ps.active = 1 AND ps.available_for_order = 1 AND ps.visibility != "none" ORDER BY p.id_product');
        foreach ($ids ?: [] as $row) {
            $idProduct = (int) $row['id_product'];
            if (!Product::getCover($idProduct)) {
                continue; // same rule as the product feed: items without image are not in it, so no inventory row either
            }
            $product = new Product($idProduct, false, (int) Configuration::get('PS_LANG_DEFAULT'), $idShop);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }
            $attributes = [];
            if ($product->hasAttributes()) {
                foreach ($product->getAttributeCombinations((int) Configuration::get('PS_LANG_DEFAULT')) as $c) {
                    $attributes[(int) $c['id_product_attribute']] = true;
                }
            }
            foreach ($attributes ? array_keys($attributes) : [0] as $ida) {
                $price = Product::getPriceStatic($idProduct, true, $ida ?: null, 2);
                if ($price === null || ($skipZero && $price <= 0)) {
                    continue;
                }
                $qty = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $ida ?: null, $idShop);
                if ($skipOos && $qty <= 0) {
                    continue;
                }
                $id = $ida ? $idProduct . '-' . $ida : (string) $idProduct;
                echo $storeCode . "\t" . $id . "\t" . ($qty > 0 ? 'in_stock' : 'out_of_stock') . "\t" . max(0, $qty) . "\n";
            }
        }
        exit;
    }
}

<?php
/**
 * Data access and generation for the Productteksten page.
 *
 * Status of a row in kcm_producttext: queued (waiting for a batch), draft (concept ready to review),
 * failed (generation error, see `error`), done (saved by the admin), skipped (concept discarded).
 * Products without a row have no concept yet.
 */
class KcmProductText
{
    const PER_PAGE = 25;
    const FILTERS = ['short', 'draft', 'noshort', 'failed', 'done', 'all'];

    /* ---------- reading ---------- */

    public static function minChars(): int
    {
        return max(50, (int) Configuration::get(Kcm_Producttext::MIN_CHARS));
    }

    public static function stats(int $idLang): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $shop = (int) Context::getContext()->shop->id;
        $min = self::minChars();
        $row = $db->getRow("SELECT COUNT(*) total,
                SUM(CHAR_LENGTH(REGEXP_REPLACE(IFNULL(pl.description, ''), '<[^>]+>', '')) < $min) short_count,
                SUM(TRIM(IFNULL(pl.description_short, '')) = '' OR pl.description_short = '<p></p>') noshort,
                SUM(t.status = 'draft') drafts, SUM(t.status = 'queued') queued, SUM(t.status = 'failed') failed, SUM(t.status = 'done') done
            FROM {$p}product p
            JOIN {$p}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = $idLang AND pl.id_shop = $shop
            LEFT JOIN {$p}kcm_producttext t ON t.id_product = p.id_product AND t.id_lang = $idLang
            WHERE p.active = 1");
        $cats = (int) $db->getValue("SELECT COUNT(DISTINCT p.id_category_default) FROM {$p}product p WHERE p.active = 1");
        return array_map('intval', (array) $row) + ['categories' => $cats, 'min' => $min];
    }

    /** Categories (default categories of active products) for the filter select. */
    public static function categories(int $idLang): array
    {
        $p = _DB_PREFIX_;
        $shop = (int) Context::getContext()->shop->id;
        return Db::getInstance()->executeS("SELECT c.id_category, cl.name, COUNT(*) n
            FROM {$p}product p
            JOIN {$p}category c ON c.id_category = p.id_category_default
            JOIN {$p}category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = $idLang AND cl.id_shop = $shop
            WHERE p.active = 1 GROUP BY c.id_category ORDER BY cl.name") ?: [];
    }

    private static function where(int $idLang, string $filter, int $cat, string $q): string
    {
        $min = self::minChars();
        $w = ['p.active = 1'];
        switch ($filter) {
            case 'short': $w[] = "CHAR_LENGTH(REGEXP_REPLACE(IFNULL(pl.description, ''), '<[^>]+>', '')) < $min AND IFNULL(t.status, '') <> 'done'"; break;
            case 'draft': $w[] = "t.status = 'draft'"; break;
            case 'noshort': $w[] = "(TRIM(IFNULL(pl.description_short, '')) = '' OR pl.description_short = '<p></p>')"; break;
            case 'failed': $w[] = "t.status = 'failed'"; break;
            case 'done': $w[] = "t.status = 'done'"; break;
        }
        if ($cat > 0) {
            $w[] = 'p.id_category_default = ' . $cat;
        }
        if ($q !== '') {
            $like = '\'%' . pSQL($q) . '%\'';
            $w[] = "(pl.name LIKE $like OR p.reference LIKE $like OR p.mpn LIKE $like)";
        }
        return implode(' AND ', $w);
    }

    private static function baseQuery(int $idLang): string
    {
        $p = _DB_PREFIX_;
        $shop = (int) Context::getContext()->shop->id;
        return "FROM {$p}product p
            JOIN {$p}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = $idLang AND pl.id_shop = $shop
            LEFT JOIN {$p}kcm_producttext t ON t.id_product = p.id_product AND t.id_lang = $idLang
            LEFT JOIN {$p}manufacturer m ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN {$p}category c ON c.id_category = p.id_category_default
            LEFT JOIN {$p}category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = $idLang AND cl.id_shop = $shop
            LEFT JOIN {$p}category_lang pcl ON pcl.id_category = c.id_parent AND pcl.id_lang = $idLang AND pcl.id_shop = $shop
            LEFT JOIN {$p}image i ON i.id_product = p.id_product AND i.cover = 1";
    }

    public static function count(int $idLang, string $filter, int $cat, string $q): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . self::baseQuery($idLang) . ' WHERE ' . self::where($idLang, $filter, $cat, $q));
    }

    public static function overview(int $idLang, string $filter, int $cat, string $q, int $page): array
    {
        $offset = max(0, $page - 1) * self::PER_PAGE;
        $rows = Db::getInstance()->executeS("SELECT p.id_product, p.reference, p.mpn, pl.name, pl.link_rewrite, pl.description_short, m.name brand, cl.name category, pcl.name parent_category,
                i.id_image, t.status, t.date_saved, t.error,
                CHAR_LENGTH(REGEXP_REPLACE(IFNULL(pl.description, ''), '<[^>]+>', '')) text_len
            " . self::baseQuery($idLang) . ' WHERE ' . self::where($idLang, $filter, $cat, $q) . " ORDER BY p.id_product LIMIT $offset, " . self::PER_PAGE) ?: [];
        $min = self::minChars();
        $link = Context::getContext()->link;
        foreach ($rows as &$r) {
            $r['short_plain'] = Tools::substr(trim(strip_tags(html_entity_decode((string) $r['description_short'], ENT_QUOTES, 'UTF-8'))), 0, 90);
            $r['level'] = $r['text_len'] < $min ? 'red' : ($r['text_len'] < $min * 2 ? 'orange' : 'green');
            $r['pct'] = min(100, (int) round($r['text_len'] / ($min * 2) * 100));
            $r['status'] = $r['status'] ?: 'none';
            $r['thumb'] = $r['id_image'] ? $link->getImageLink($r['link_rewrite'], (string) $r['id_image'], 'small_default') : '';
            $r['meta_line'] = implode(' · ', array_filter([$r['reference'] !== '' ? 'Ref. ' . $r['reference'] : '', (string) $r['brand'], trim(($r['parent_category'] ? $r['parent_category'] . ' › ' : '') . (string) $r['category'])]));
        }
        unset($r);
        return $rows;
    }

    /** Ordered product ids of a filter, for prev/next in the review screen. */
    public static function ids(int $idLang, string $filter, int $cat, string $q): array
    {
        $rows = Db::getInstance()->executeS('SELECT p.id_product ' . self::baseQuery($idLang) . ' WHERE ' . self::where($idLang, $filter, $cat, $q) . ' ORDER BY p.id_product') ?: [];
        return array_map(function ($r) { return (int) $r['id_product']; }, $rows);
    }

    public static function row(int $idProduct, int $idLang): ?array
    {
        $r = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'kcm_producttext` WHERE id_product = ' . (int) $idProduct . ' AND id_lang = ' . (int) $idLang);
        return $r ?: null;
    }

    /** Everything the review screen shows for one product. */
    public static function detail(int $idProduct, int $idLang): ?array
    {
        $p = _DB_PREFIX_;
        $shop = (int) Context::getContext()->shop->id;
        $r = Db::getInstance()->getRow("SELECT p.id_product, p.reference, p.mpn, p.ean13, p.price, pl.name, pl.link_rewrite, pl.description, pl.description_short, pl.meta_title,
                m.name brand, cl.name category, pcl.name parent_category, sa.quantity
            " . self::baseQuery($idLang) . "
            LEFT JOIN {$p}stock_available sa ON sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = $shop
            WHERE p.id_product = " . (int) $idProduct);
        if (!$r) {
            return null;
        }
        $link = Context::getContext()->link;
        $images = Image::getImages($idLang, $idProduct);
        usort($images, function ($a, $b) { return ((int) $b['cover'] - (int) $a['cover']) ?: ((int) $a['position'] - (int) $b['position']); });
        $r['images'] = array_map(function ($img) use ($link, $r) {
            return ['id' => (int) $img['id_image'], 'small' => $link->getImageLink($r['link_rewrite'], (string) $img['id_image'], 'small_default'), 'large' => $link->getImageLink($r['link_rewrite'], (string) $img['id_image'], 'home_default')];
        }, $images);
        $tags = Tag::getProductTags($idProduct);
        $r['tags'] = implode(', ', $tags[$idLang] ?? []);
        $r['text_len'] = mb_strlen(trim(strip_tags(html_entity_decode((string) $r['description'], ENT_QUOTES, 'UTF-8'))));
        $r['short_plain'] = trim(strip_tags(html_entity_decode((string) $r['description_short'], ENT_QUOTES, 'UTF-8')));
        $r['concept'] = self::row($idProduct, $idLang);
        $r['min'] = self::minChars();
        return $r;
    }

    /* ---------- queue and generation ---------- */

    public static function queue(array $ids, int $idLang): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $row = self::row($id, $idLang);
            if ($row && $row['status'] === 'done') {
                // re-generating a finished product is allowed; the saved text stays until the admin saves again
            }
            Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'kcm_producttext` (id_product, id_lang, status, error, date_upd) VALUES (' . $id . ', ' . (int) $idLang . ", 'queued', NULL, NOW())
                ON DUPLICATE KEY UPDATE status = 'queued', error = NULL, date_upd = NOW()");
            ++$n;
        }
        return $n;
    }

    public static function queuedCount(int $idLang): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'kcm_producttext` WHERE status = \'queued\' AND id_lang = ' . (int) $idLang);
    }

    /** Generates drafts for queued products until $limit products or $seconds are used. */
    public static function runBatch(int $idLang, int $limit, int $seconds): array
    {
        $t0 = microtime(true);
        $ids = Db::getInstance()->executeS('SELECT t.id_product FROM `' . _DB_PREFIX_ . 'kcm_producttext` t JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = t.id_product
            WHERE t.status = \'queued\' AND t.id_lang = ' . (int) $idLang . ' ORDER BY t.date_upd, t.id_product LIMIT ' . (int) $limit) ?: [];
        $done = 0; $failed = 0;
        foreach ($ids as $r) {
            if ($done + $failed > 0 && microtime(true) - $t0 > $seconds) {
                break;
            }
            $res = self::generate((int) $r['id_product'], $idLang);
            $res['ok'] ? ++$done : ++$failed;
        }
        return ['processed' => $done + $failed, 'drafts' => $done, 'failed' => $failed, 'remaining' => self::queuedCount($idLang), 'seconds' => round(microtime(true) - $t0, 1)];
    }

    /** One product: prompt + photos to the model, answer checked, stored as draft (or failed). */
    public static function generate(int $idProduct, int $idLang, ?string $hint = null): array
    {
        $db = Db::getInstance();
        $table = '`' . _DB_PREFIX_ . 'kcm_producttext`';
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return ['ok' => false, 'message' => 'Product niet gevonden'];
        }
        $row = self::row($idProduct, $idLang);
        if ($hint === null) {
            $hint = $row ? (string) $row['hint'] : '';
        }
        $brand = $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        $category = '';
        if ($product->id_category_default) {
            $cat = new Category((int) $product->id_category_default, $idLang);
            $category = Validate::isLoadedObject($cat) ? (string) $cat->name : '';
        }
        $p = ['name' => (string) $product->name, 'reference' => (string) $product->reference, 'mpn' => (string) $product->mpn, 'brand' => $brand, 'category' => $category,
            'existing' => (string) $product->description, 'existingShort' => (string) $product->description_short];
        $files = [];
        try {
            $describer = new KcmAiDescriber();
            $user = $describer->userPrompt($p)
                . "\n\nOpbouw van de omschrijving: eerst 1 of 2 korte alinea's over wat het onderdeel is en doet en wat de staat is (nieuw, NOS, gereviseerd, gebruikt); daarna, alleen als die gegevens bekend zijn, een alinea <p><strong>Toepasbaar op</strong></p> met een lijst van modellen en bouwjaren, een alinea <p><strong>Levering</strong></p> (per stuk, per paar, per set, wat is inbegrepen) en een alinea <p><strong>Originele nummers</strong></p> met de VW- of fabrikantnummers. Streef naar 400 tot 1000 tekens.\n"
                . "Regels voor deze shop:\n"
                . "- De referentie is ons eigen artikelnummer en nooit een origineel nummer: niet opnemen onder Originele nummers. Alleen nummers uit het veld fabrikantnummer of uit de bestaande tekst gelden als origineel nummer.\n"
                . "- 'Origineel VW' als merk betekent: een origineel Volkswagen-onderdeel. Schrijf dus 'origineel VW-onderdeel', nooit 'van het merk Origineel VW'.\n"
                . "- Noem de categorie niet in de tekst.\n"
                . "- Verwijs nooit naar de foto's of afbeeldingen: geen 'op de foto', 'afgebeeld', 'zoals afgebeeld', 'van twee kanten te zien' of iets dergelijks. Beschrijf gewoon wat het is.\n"
                . "- Schrijf geen zin die alleen herhaalt wat de naam al zegt (zoals 'de naam vermeldt de kleur zwart').\n"
                . "- Noem de staat (nieuw, NOS, gereviseerd, gebruikt) alleen als die in de bron of de aanwijzing staat; leid de staat niet af van de foto's.\n"
                . "- Geen algemene verkoop- of afsluitzinnen zoals 'een geschikte keuze voor wie...', 'maakt uw restauratie compleet' of 'controleer voor bestelling'. Elke zin moet een feit bevatten.\n"
                . "- Bouwjaren en modellen letterlijk overnemen zoals ze in de bron staan; staat er alleen een periode zonder model, neem die dan zo over en vul geen model in.\n"
                . "- Twijfel je over een eigenschap (materiaal, kleur, aantal), laat die dan weg in plaats van alternatieven te noemen.\n"
                . "- Zinnen als 'vanaf 8.1971 en later' zijn dubbelop: schrijf 'vanaf 8.1971'."
                . ($hint !== '' ? "\n\nAanwijzing van de beheerder voor dit product (betrouwbaar, mag je gebruiken): " . trim(preg_replace('/\s+/', ' ', strip_tags($hint))) : '');
            $files = self::imageFiles($idProduct, $idLang, (int) Configuration::get(Kcm_Producttext::MAX_IMAGES));
            $client = KcmAiClient::fromSettings();
            $answer = $client->complete($describer->systemPrompt(), $user, $files['send'], 2500);
            $res = $describer->checkAnswer($answer, $p);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            foreach ($files['tmp'] ?? [] as $f) {
                @unlink($f);
            }
        }
        if (!$res['ok']) {
            $db->execute("INSERT INTO $table (id_product, id_lang, status, error, hint, date_upd) VALUES ($idProduct, $idLang, 'failed', '" . pSQL(Tools::substr($res['message'], 0, 490)) . "', '" . pSQL($hint) . "', NOW())
                ON DUPLICATE KEY UPDATE status = 'failed', error = VALUES(error), hint = VALUES(hint), date_upd = NOW()");
            return $res;
        }
        $db->execute("INSERT INTO $table (id_product, id_lang, status, draft_short, draft_desc, error, hint, date_generated, date_upd) VALUES ($idProduct, $idLang, 'draft', '" . pSQL($res['description_short'], true) . "', '" . pSQL($res['description'], true) . "', NULL, '" . pSQL($hint) . "', NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = 'draft', draft_short = VALUES(draft_short), draft_desc = VALUES(draft_desc), error = NULL, hint = VALUES(hint), date_generated = NOW(), date_upd = NOW()");
        return ['ok' => true, 'description' => $res['description'], 'description_short' => $res['description_short']];
    }

    /** JPEG files to send: the ready-made medium thumbnails when present, else a resized copy of the original. */
    private static function imageFiles(int $idProduct, int $idLang, int $max): array
    {
        $send = []; $tmp = [];
        if ($max <= 0) {
            return ['send' => $send, 'tmp' => $tmp];
        }
        $images = Image::getImages($idLang, $idProduct);
        usort($images, function ($a, $b) { return ((int) $b['cover'] - (int) $a['cover']) ?: ((int) $a['position'] - (int) $b['position']); });
        foreach (array_slice($images, 0, $max) as $img) {
            $image = new Image((int) $img['id_image']);
            $base = _PS_PRODUCT_IMG_DIR_ . $image->getExistingImgPath();
            if (is_file($base . '-medium_default.jpg')) {
                $send[] = $base . '-medium_default.jpg';
            } elseif (is_file($base . '.jpg')) {
                $t = tempnam(sys_get_temp_dir(), 'kcpt') . '.jpg';
                if (ImageManager::resize($base . '.jpg', $t, 640, 640, 'jpg', true)) {
                    $send[] = $t; $tmp[] = $t;
                }
            }
        }
        return ['send' => $send, 'tmp' => $tmp];
    }

    /* ---------- saving ---------- */

    /** Writes the two text fields (all shops of this language), keeps the previous text, marks done, reindexes. */
    public static function save(int $idProduct, int $idLang, string $short, string $desc, bool $onlyShort): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $short = trim(Tools::purifyHTML($short));
        $desc = trim(Tools::purifyHTML($desc));
        if (!Validate::isCleanHtml($short) || !Validate::isCleanHtml($desc)) {
            return ['ok' => false, 'message' => 'De tekst bevat niet-toegestane HTML.'];
        }
        if ($short !== '' && strpos($short, '<') === false) {
            $short = '<p>' . Tools::safeOutput($short) . '</p>';
        }
        if (mb_strlen(strip_tags($short)) > (int) Configuration::get('PS_PRODUCT_SHORT_DESC_LIMIT')) {
            return ['ok' => false, 'message' => 'De korte omschrijving is langer dan de limiet van ' . (int) Configuration::get('PS_PRODUCT_SHORT_DESC_LIMIT') . ' tekens (Winkelinstellingen > Producten).'];
        }
        $cur = $db->getRow("SELECT description, description_short FROM {$p}product_lang WHERE id_product = $idProduct AND id_lang = $idLang ORDER BY id_shop");
        if (!$cur) {
            return ['ok' => false, 'message' => 'Product niet gevonden'];
        }
        $row = self::row($idProduct, $idLang);
        $prevShort = $row && $row['prev_short'] !== null ? $row['prev_short'] : (string) $cur['description_short'];
        $prevDesc = $row && $row['prev_desc'] !== null ? $row['prev_desc'] : (string) $cur['description'];
        $set = "description_short = '" . pSQL($short, true) . "'" . ($onlyShort ? '' : ", description = '" . pSQL($desc, true) . "'");
        if (!$db->execute("UPDATE {$p}product_lang SET $set WHERE id_product = $idProduct AND id_lang = $idLang")) {
            return ['ok' => false, 'message' => 'Opslaan mislukt: ' . $db->getMsgError()];
        }
        $db->execute("UPDATE {$p}product SET date_upd = NOW() WHERE id_product = $idProduct");
        $db->execute('INSERT INTO `' . $p . "kcm_producttext` (id_product, id_lang, status, prev_short, prev_desc, date_saved, date_upd) VALUES ($idProduct, $idLang, 'done', '" . pSQL($prevShort, true) . "', '" . pSQL($prevDesc, true) . "', NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = 'done', prev_short = VALUES(prev_short), prev_desc = VALUES(prev_desc), date_saved = NOW(), date_upd = NOW()");
        self::reindex($idProduct);
        return ['ok' => true];
    }

    public static function restore(int $idProduct, int $idLang): array
    {
        $row = self::row($idProduct, $idLang);
        if (!$row || $row['prev_desc'] === null) {
            return ['ok' => false, 'message' => 'Er is geen vorige tekst bewaard voor dit product.'];
        }
        $p = _DB_PREFIX_;
        Db::getInstance()->execute("UPDATE {$p}product_lang SET description = '" . pSQL($row['prev_desc'], true) . "', description_short = '" . pSQL($row['prev_short'], true) . "' WHERE id_product = $idProduct AND id_lang = $idLang");
        Db::getInstance()->execute("UPDATE {$p}kcm_producttext SET status = 'draft', prev_short = NULL, prev_desc = NULL, date_saved = NULL, date_upd = NOW() WHERE id_product = $idProduct AND id_lang = $idLang");
        self::reindex($idProduct);
        return ['ok' => true];
    }

    public static function discard(int $idProduct, int $idLang): void
    {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . "kcm_producttext` SET status = 'skipped', date_upd = NOW() WHERE id_product = $idProduct AND id_lang = $idLang AND status IN ('draft', 'failed', 'queued')");
    }

    public static function setHint(int $idProduct, int $idLang, string $hint): void
    {
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . "kcm_producttext` (id_product, id_lang, status, hint, date_upd) VALUES ($idProduct, $idLang, 'skipped', '" . pSQL(Tools::substr($hint, 0, 500)) . "', NOW())
            ON DUPLICATE KEY UPDATE hint = VALUES(hint), date_upd = NOW()");
    }

    private static function reindex(int $idProduct): void
    {
        $p = _DB_PREFIX_;
        Db::getInstance()->execute("UPDATE {$p}product SET indexed = 0 WHERE id_product = $idProduct");
        Db::getInstance()->execute("UPDATE {$p}product_shop SET indexed = 0 WHERE id_product = $idProduct");
        try {
            Search::indexation(false, $idProduct);
        } catch (Throwable $e) {
            // the nightly/BO indexation picks it up
        }
    }
}

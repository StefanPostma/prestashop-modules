# kcm_producttext: review and rewrite all product descriptions from one back-office page

PrestaShop 8/9 module. Adds *Catalogus > Productteksten*: one page that lists every active product with the
length of its description, generates AI drafts in batches, and lets you review, edit and save the texts one
product at a time. Nothing goes live until you click **Opslaan** on the review screen; the previous text is
kept and can be restored per product.

It is the bulk companion of [`kcm_aidescribe`](../kcm_aidescribe), which it **requires**: the API client, the
model settings, the shop context, the writing instructions and the prompt-injection guards all come from that
module. Configure and test `kcm_aidescribe` first.

## What you get

**Overview** (`AdminKcmProductText`)

* Four counters: active products, products with a description shorter than the threshold, drafts waiting for
  review, products saved through this module.
* Filters: too short, draft ready, without short description, failed, done, all; a category drop-down and a
  search box (name or reference); 25 products per page.
* Tick products and click *Concepten genereren voor selectie*, or queue the whole filter. Generation runs in
  the browser tab through repeated ajax calls (default 3 products per call, 35 seconds budget per call), with a
  progress banner and a pause button. You can keep reviewing while it runs. Failed products land in the
  *Mislukt* filter with the error message.
* Settings panel (toolbar button): "too short" threshold (default 200 characters), products per ajax call,
  photos sent per product.

**Review screen**

* Photos (click a thumbnail to enlarge), the facts the model saw (reference, brand, category, price, stock),
  current text and draft side by side; the draft is editable (short description as plain text with a counter,
  description in TinyMCE).
* Optional hint field for this product ("the rubbers are sold separately") and a *Nieuw concept maken* button
  that regenerates on the spot.
* *Opslaan en volgende* writes `description` and `description_short` of `product_lang` (all shops), marks the
  product done, resets the product's search index flags and moves on. *Alleen korte omschrijving opslaan*,
  *Concept verwerpen* and *Vorige tekst terugzetten* do what they say.

## Install

1. Install and configure `kcm_aidescribe` (API key, model, shop context, writing instructions).
2. Upload this module's zip through *Modules > Module manager > Upload a module*, or copy `kcm_producttext/`
   to `modules/` and install. Installation creates the table `<prefix>kcm_producttext` (drafts, previous texts,
   status per product and language) and the tab under *Catalogus*.
3. Open *Catalogus > Productteksten*.

Uninstalling keeps the table, so drafts and previous texts survive a reinstall.

## How it works

* `classes/KcmProductText.php` holds the queries (overview, counters, queue, batch runner, generate, save,
  restore). `generate()` builds the prompt with `KcmAiDescriber::userPrompt()` from `kcm_aidescribe`, appends
  the layout and the shop rules below, sends up to *n* product photos (`medium_default`, resized when
  missing) and runs the answer through `KcmAiDescriber::checkAnswer()`, which strips everything except
  `<p><ul><ol><li><strong><em><br>` and rejects answers that contain links or instructions.
* `controllers/admin/AdminKcmProductTextController.php` renders the two screens and handles the form posts
  and the ajax actions `runBatch`, `regenerate` and `status`. URLs are host-relative so the back office also
  works on a different host alias.
* Saving uses `Tools::purifyHTML()` and `Validate::isCleanHtml()`, respects `PS_PRODUCT_SHORT_DESC_LIMIT`,
  and stores the previous texts before the update.
* `Search::indexation()` looks at `product_shop.indexed`; the module resets both `product.indexed` and
  `product_shop.indexed` and reindexes the one product after saving.

## Prompt rules you will want to adapt

The description layout (one or two factual paragraphs, then *Toepasbaar op*, *Levering*, *Originele nummers*
when known, 400 to 1000 characters) and the extra rules in `KcmProductText::generate()` were written for a
classic-car parts shop: the reference is the shop's own article number and never a manufacturer number, "Origineel
VW" as brand means an original Volkswagen part, never refer to the photos, never infer the condition from the
photos, copy model years literally, no sales phrases. Edit that string for your own shop; the general style
(language, tone) stays configurable in `kcm_aidescribe`.

One lesson from the first batches: the model happily sidesteps a literal forbidden phrase with a synonym
("as shown in the photos" instead of "visible on the photos"). Phrase prohibitions as a category ("never refer to
the photos in any form") rather than a sentence.

## Limits

* One language: the default shop language (`PS_LANG_DEFAULT`). Drafts are stored per `id_lang`, so extending
  the controller to other languages is a small change.
* Generation runs only while the overview tab is open; close the tab and the queue simply waits.
* Thinking models return a thinking block first; the client call uses `max_tokens` 2500, lower values give an
  empty answer.
* `Db::getRow()` appends its own `LIMIT 1`; do not add one yourself in queries you extend.
* Back-office texts are in Dutch.

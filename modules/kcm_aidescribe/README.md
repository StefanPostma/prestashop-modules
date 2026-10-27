# kcm_aidescribe: AI product descriptions (OpenAI or Anthropic)

Adds a button *"Omschrijving met AI schrijven"* above the description editor on the PrestaShop 8/9 product page.
It sends the product photos (resized, cover first), the product name, reference, MPN, brand, category and the
text that is already in the editor to the model you configured, and puts the generated description and short
description into the editor. Nothing is saved automatically: you read the result and click Save.

Existing text is treated as *facts to keep*: part numbers, sizes or fitment you typed yourself come back in the
improved text. The prompt tells the model not to invent part numbers, sizes or fitment.

## Install and configure

1. Upload the zip through *Modules > Module manager > Upload a module* (or copy the folder to `modules/` and install).
2. *Modules > AI-productomschrijving > Configure*:
   * provider: Anthropic (Claude) or OpenAI (GPT);
   * the API key of that provider (stored in your shop's configuration table, never in this repository);
   * model (defaults `claude-sonnet-5-5` / `gpt-5`; the model must accept images);
   * *Winkelcontext*: one or two sentences about your shop and what you sell, sent with every request;
   * *Schrijfinstructies*: tone, language, what to mention or avoid;
   * number of photos per request (0 to 8) and whether to fill the short description too.
3. Click *Verbinding testen*: it saves the settings and sends a one-word test prompt.

## How it works

* Hook `displayBackOfficeHeader` injects `views/js/aidescribe.js` only on `/sell/catalog/products/<id>/edit`.
* The script adds the button above the description editor of the default language (`#product_description_description_<idLang>`,
  TinyMCE), collects the current field values and POSTs them to the hidden legacy admin controller
  `AdminKcmAiDescribe` (tab with `id_parent = -1`, host-relative URL so it also works when the back office is
  opened on a different host alias).
* The controller resizes the product images to max 1024 px JPEG, builds the prompt, calls the API
  (`classes/KcmAiClient.php`: Anthropic Messages API or OpenAI Chat Completions with base64 images), expects a JSON
  answer `{"description": "<html>", "description_short": "..."}`, purifies the HTML and returns it. The script sets
  the TinyMCE content and fires `change` so the Save button becomes active.

## Notes

* The back-office texts and the default prompt are in Dutch; change *Schrijfinstructies* for another language.
* Each click costs one API request with up to 8 images; check your provider's pricing.
* Requires PHP curl.

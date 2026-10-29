# PrestaShop modules

Small, self-contained modules for PrestaShop 8 and 9, written for a Dutch webshop and shared as-is.
Each module lives in `modules/<name>` and can be zipped and uploaded through *Modules > Module manager > Upload a module*,
or copied into the `modules/` folder of the shop and installed from the Module manager.

| Module | What it does |
|---|---|
| [`kcm_postcode`](modules/kcm_postcode) | Accepts Dutch postcodes typed without a space (`1234AB`) in the address forms; PrestaShop 8/9 otherwise rejects them because the country format is `NNNN LL`. |
| [`kcm_aidescribe`](modules/kcm_aidescribe) | Adds a button to the product page that writes the product description (and short description) with OpenAI or Anthropic, based on the product photos and the text you already typed. |
| [`kcm_turnstile`](modules/kcm_turnstile) | Cloudflare Turnstile bot check on account creation, the guest step of the checkout, the contact form and the newsletter form; each place can be switched on or off, with theme, appearance and a test mode. |
| [`kcm_blockspam`](modules/kcm_blockspam) | Blocks account creation, guest checkout and contact-form messages from blocked e-mail domains or addresses, disposable e-mail domains and messages with blocked words; keeps a log of blocked attempts. |
| [`kcm_pickuponly`](modules/kcm_pickuponly) | Replaces the misleading "Shipping: Free" in the cart and checkout summary by the pickup carrier's name when every delivery option of the cart is a pickup carrier (collect-only products). |

The `kcm_` prefix is just the author's module prefix. The back-office texts of the modules are in Dutch.

## Zipping a module

```sh
cd modules && zip -r ../kcm_postcode.zip kcm_postcode -x '*.DS_Store'
```

## Security notes

* No API keys or shop data are stored in this repository. `kcm_aidescribe` stores the API keys you enter in the
  PrestaShop configuration table of your own shop.
* Report problems through the issue tracker of this repository.

## License

[MIT](LICENSE).

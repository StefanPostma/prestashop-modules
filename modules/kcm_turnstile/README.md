# kcm_turnstile: Cloudflare Turnstile for PrestaShop 8/9

Adds a [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) bot check to:

* the account creation page and the registration form on the login page,
* the guest / registration step of the checkout,
* the contact form (module `contactform`).

Each place can be switched on or off in the module settings. Logged-in customers never see the check.
A failed or missing check blocks the form with a message; a transport failure towards Cloudflare lets the
request through (and logs it), so a Cloudflare hiccup never locks your shop.

## Install

1. Create a Turnstile widget in the Cloudflare dashboard for your shop hostname (mode *Managed*).
2. Upload the module zip, then *Modules > Cloudflare Turnstile > Configure*: paste site key and secret key,
   choose where the check is shown, save.
3. Add the two theme snippets below (child theme of *classic*).

## Theme snippets

**Registration forms** (`templates/_partials/form-fields.tpl`, inside the `{if $field.type ...}` chain, for
example before `{elseif $field.type === 'textarea'}`):

```smarty
{elseif $field.type === 'turnstile'}
  <div class="cf-turnstile" data-sitekey="{$field.availableValues.sitekey}" data-language="{$field.availableValues.language|default:'auto'}" data-theme="light"></div>
```

Optional, to hide the "Optional" label next to it: add `'turnstile'` to the array in
`{if (!$field.required && !in_array($field.type, ['radio-buttons', 'checkbox']))}`.

**Contact form** (`modules/contactform/views/templates/widget/contactform.tpl` in your theme, copy of the
module template): render the widget and the blocked-message notice:

```smarty
{if isset($kc_contact_error) && $kc_contact_error}
  <div class="notification notification-error"><ul><li>{$kc_contact_error|escape:'htmlall':'UTF-8'}</li></ul></div>
{/if}
...
{hook h='displayKcmTurnstile'}
```

## How it works

* Registration: hook `additionalCustomerFormFields` adds a field of type `turnstile`; `validateCustomerFormFields`
  verifies `cf-turnstile-response` with Cloudflare's siteverify endpoint and adds an error to the field.
* Contact form: the module has no validation hook, so `actionFrontControllerInitBefore` verifies the token before
  the `contactform` module processes the POST, removes `submitMessage` when it fails and sets the smarty variable
  `kc_contact_error` for the theme.
* `actionFrontControllerSetMedia` loads `https://challenges.cloudflare.com/turnstile/v0/api.js` only on those pages.

Cloudflare test keys for a local shop: site key `1x00000000000000000000AA`, secret `1x0000000000000000000000000000000AA`
(always pass) or secret `2x0000000000000000000000000000000AA` (always fail).

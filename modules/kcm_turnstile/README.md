# kcm_turnstile: Cloudflare Turnstile for PrestaShop 8/9

Adds a [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) bot check to:

* the account creation page and the registration form on the login page,
* the guest / registration step of the checkout,
* the contact form (module `contactform`),
* the newsletter form (module `ps_emailsubscription`; the widget is placed inside that form by hook, no theme change).

Each place can be switched on or off in the module settings. Logged-in customers never see the check on the
account and checkout forms. Theme (auto/light/dark) and appearance (always, execute, interaction-only) are
configurable; with *interaction-only* most visitors never see the widget. A test mode uses Cloudflare's official
test keys (always passes, always fails, force an interactive challenge) so you can try the forms without touching
your own keys. Each place sends its own `data-action` (registration, checkout, contact, newsletter), which shows
up in the Turnstile analytics.
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
  <div class="cf-turnstile" data-sitekey="{$field.availableValues.sitekey}" data-language="{$field.availableValues.language|default:'auto'}" data-theme="{$field.availableValues.theme|default:'light'}" data-appearance="{$field.availableValues.appearance|default:'always'}" data-action="{$field.availableValues.action|default:'registration'}"></div>
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
* Newsletter: `displayNewsletterRegistration` renders the widget inside the `ps_emailsubscription` form and
  `actionNewsletterRegistrationBefore` sets `hookError` when the token fails, which makes that module show the error.
* `actionFrontControllerSetMedia` loads `https://challenges.cloudflare.com/turnstile/v0/api.js` only on the
  protected pages (on every page when the newsletter form is protected, as it usually sits in the footer).
* Test mode swaps your keys for Cloudflare's test keys (`1x…`/`2x…`/`3x…`); the configuration page shows a warning
  while it is on.

Inspired in part by [Pixel-Open/prestashop-cloudflare-turnstile](https://github.com/Pixel-Open/prestashop-cloudflare-turnstile)
(test mode, appearance option, newsletter form). Differences: this module also protects the guest step of the
checkout, sends the visitor IP to siteverify, lets requests through when Cloudflare is unreachable, stays on the
page instead of redirecting, and keeps the error message neutral.

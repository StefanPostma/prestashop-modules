# kcm_blockspam: block spam registrations and contact messages

Blocks account creation, guest checkout and contact-form messages when

* the e-mail domain or address is on your blocklist (`example.com`, `.example.com` incl. subdomains, `.ru` for a
  whole TLD, or `name@example.com`),
* the domain is a disposable e-mail domain (optional; uses the public
  [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) list, refreshed
  every 7 days),
* the contact message or e-mail address contains one of your blocked words (case-insensitive).

A blocked registration shows "This e-mail address is not accepted" on the form; a blocked contact message is not
sent and not stored. Every blocked attempt is logged (date, source, address, reason, IP) and shown on the module
settings page, where you can also empty the log.

## Install

Upload the zip, then *Modules > Spamblokkade > Configure*: fill the lists and save. The back-office texts are
in Dutch. The registration forms need a `blockcheck` field type in the theme (only its error is shown), and the
contact form needs the `kc_contact_error` notice; both snippets are the same as for the
[kcm_turnstile](../kcm_turnstile) module:

```smarty
{elseif $field.type === 'blockcheck'}
```

(in `templates/_partials/form-fields.tpl`, nothing to render) and

```smarty
{if isset($kc_contact_error) && $kc_contact_error}
  <div class="notification notification-error"><ul><li>{$kc_contact_error|escape:'htmlall':'UTF-8'}</li></ul></div>
{/if}
```

(in your theme's copy of `modules/contactform/views/templates/widget/contactform.tpl`).

## How it works

* Registration / guest checkout: `additionalCustomerFormFields` adds an invisible field; `validateCustomerFormFields`
  checks the posted e-mail (and first/last name against the word list) and adds the error to that field.
* Contact form: `actionFrontControllerInitBefore` checks `from` and `message` before the `contactform` module runs,
  removes `submitMessage` when blocked and sets `kc_contact_error`.
* The disposable list is cached in `data/disposable.txt` inside the module folder.

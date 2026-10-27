# kcm_postcode: Dutch postcodes without a space

PrestaShop 8/9 validates the postcode literally against the country format (`NNNN LL` for the Netherlands) and
the address form also enforces a minimum length of 7 characters. A customer who types `1234AB` or `1234ab` gets
"Invalid postcode". This module rewrites a Dutch-looking postcode in the submitted form data to `1234 AB` before
the front controller validates the address form, so both checks pass. Postcodes of other countries are untouched.

## Install

Upload the zip through *Modules > Module manager > Upload a module* (or copy the folder to `modules/` and install).
No configuration.

## Theme change (recommended)

The browser-side `minlength` attribute still stops the submit before the server sees it. In your theme's
`templates/_partials/form-fields.tpl` (copy it from the classic theme into your child theme), change

```smarty
{if !empty($field.minLength)}minlength="{$field.minLength}"{/if}
```

into

```smarty
{if !empty($field.minLength) && $field.name != 'postcode'}minlength="{$field.minLength}"{/if}
```

## How it works

Hook `actionFrontControllerInitBefore`: if `$_POST['postcode']` matches `^\d{4}\s*[A-Za-z]{2}$` it becomes
`NNNN LL` (uppercase letters, one space). Nothing else is changed.

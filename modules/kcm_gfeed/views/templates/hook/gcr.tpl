{**
 * Google Customer Reviews: survey opt-in (order confirmation page only) and the optional store badge.
 * The variables hold JSON that was encoded with the JSON_HEX_* flags, safe inside a script element.
 *}
{if $kcm_gcr_optin}
<script>
  window.___gcfg = {ldelim}lang: {$kcm_gcr_lang nofilter}{rdelim};
  window.renderOptIn = function () {ldelim}
    window.gapi.load('surveyoptin', function () {ldelim}
      window.gapi.surveyoptin.render({$kcm_gcr_optin nofilter});
    {rdelim});
  {rdelim};
</script>
<script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
{/if}
{if $kcm_gcr_badge}
<script id="merchantWidgetScript" src="https://www.gstatic.com/shopping/merchant/merchantwidget.js" defer></script>
<script>
  document.getElementById('merchantWidgetScript').addEventListener('load', function () {ldelim}
    merchantwidget.start({$kcm_gcr_badge nofilter});
  {rdelim});
</script>
{/if}

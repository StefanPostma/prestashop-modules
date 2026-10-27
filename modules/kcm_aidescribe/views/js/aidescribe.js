/* AI-productomschrijving: button above the description editor on the PrestaShop 8/9 product page. */
(function () {
  'use strict';
  var cfg = window.kcmAiDescribe || {};
  function $(id) { return document.getElementById(id); }
  function editor(id) { return (window.tinymce && tinymce.get(id)) || null; }
  function getHtml(id) { var e = editor(id); if (e) return e.getContent(); var t = $(id); return t ? t.value : ''; }
  function setHtml(id, html) {
    var e = editor(id), t = $(id);
    if (e) { e.setContent(html); e.save(); e.fire('change'); }
    else if (t) { t.value = html; }
    if (t) { ['input', 'change'].forEach(function (ev) { t.dispatchEvent(new Event(ev, { bubbles: true })); }); }
  }
  function notify(msg, ok) {
    if (ok && window.showSuccessMessage) return showSuccessMessage(msg);
    if (!ok && window.showErrorMessage) return showErrorMessage(msg);
    alert(msg);
  }
  function init() {
    var lang = cfg.idLang || 1;
    var descId = 'product_description_description_' + lang;
    var shortId = 'product_description_description_short_' + lang;
    var desc = $(descId);
    if (!desc || $('kcm-ai-describe')) return;
    var group = desc.closest('.form-group') || desc.parentElement;
    var bar = document.createElement('div');
    bar.id = 'kcm-ai-describe';
    bar.style.cssText = 'margin:0 0 8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap';
    bar.innerHTML = '<button type="button" class="btn btn-outline-primary btn-sm" id="kcm-ai-btn"><i class="material-icons" style="font-size:16px;vertical-align:middle">auto_awesome</i> Omschrijving met AI schrijven</button>'
      + '<small class="text-muted" id="kcm-ai-hint">' + (cfg.configured ? 'Gebruikt de productfoto’s en de tekst die al in de editor staat (nummers en feiten blijven behouden). Controleer het resultaat en sla daarna op.' : 'Nog geen API-sleutel ingesteld: Modules > AI-productomschrijving > Configureren.') + '</small>';
    group.parentElement.insertBefore(bar, group);
    var btn = $('kcm-ai-btn');
    btn.addEventListener('click', function () {
      var m = location.pathname.match(/\/products(?:-v2)?\/(\d+)\/edit/);
      if (!m) return notify('Sla het product eerst op; daarna kan de omschrijving worden geschreven.', false);
      var fd = new FormData();
      fd.append('id_product', m[1]);
      fd.append('name', ($('product_header_name_' + lang) || {}).value || '');
      fd.append('reference', ($('product_details_references_reference') || {}).value || '');
      fd.append('mpn', ($('product_details_references_mpn') || {}).value || '');
      fd.append('description', getHtml(descId));
      fd.append('description_short', getHtml(shortId));
      var label = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="font-size:16px;vertical-align:middle">hourglass_top</i> Bezig… (foto’s worden gelezen, dit duurt 10 tot 30 seconden)';
      fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { throw new Error('Onverwacht antwoord van de server (' + r.status + '). Ververs de pagina en probeer opnieuw.'); } }); })
        .then(function (d) {
          if (!d.ok) throw new Error(d.message || 'Onbekende fout');
          setHtml(descId, d.description);
          if (cfg.fillShort && d.description_short) setHtml(shortId, d.description_short);
          notify('Omschrijving geschreven op basis van ' + d.images + ' foto’s. Controleer de tekst en klik op Opslaan.', true);
        })
        .catch(function (e) { notify(e.message, false); })
        .then(function () { btn.disabled = false; btn.innerHTML = label; });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 800); });
  else setTimeout(init, 800);
})();

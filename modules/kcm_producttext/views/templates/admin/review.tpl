{* kcm_producttext: review one product *}
<div class="kcpt">
  {if $saved}<div class="alert alert-success">Tekst van product #{$saved|intval} opgeslagen. Voor anonieme bezoekers kan de productpagina tot een uur in de Cloudflare-cache blijven staan.</div>{/if}
  {if $restored}<div class="alert alert-success">De vorige tekst is teruggezet.</div>{/if}
  {if $concept && $concept.status == 'failed'}<div class="alert alert-warning">Het vorige concept mislukte: {$concept.error|escape:'html':'UTF-8'}</div>{/if}

  <div class="kcpt-head">
    <div>
      <div class="pmeta"><a href="{$list_url|escape:'html':'UTF-8'}">&lsaquo; Terug naar overzicht</a> &middot; filter: {$filter|escape:'html':'UTF-8'} &middot; <a href="{$edit_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">productpagina in de backoffice</a> &middot; <a href="{$front_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">in de shop</a></div>
      <h2>{$d.name|escape:'html':'UTF-8'}</h2>
      <div class="facts">{$facts|escape:'html':'UTF-8'}</div>
    </div>
    <div class="nav">
      {if $count}<span class="pmeta">{$position|intval} van {$count|intval}</span>{/if}
      {if $prev_url}<a class="btn-kc" href="{$prev_url|escape:'html':'UTF-8'}">Vorige</a>{/if}
      {if $next_url}<a class="btn-kc" href="{$next_url|escape:'html':'UTF-8'}">Overslaan</a>{/if}
    </div>
  </div>

  <form method="post" action="{$current_url|escape:'html':'UTF-8'}" id="kcpt-review-form">
  <input type="hidden" name="next_id" value="{$next_id|intval}">
  <div class="kcpt-cols">
    <div class="kcpt-side">
      <div class="kcpt-box">
        <h4>Foto's</h4>
        {if $d.images}
          <img id="kcpt-photo" class="kcpt-photo" src="{$d.images[0].large|escape:'html':'UTF-8'}" alt="">
          {if $d.images|count > 1}
          <div class="kcpt-thumbs">
            {foreach $d.images as $img}<img src="{$img.small|escape:'html':'UTF-8'}" data-large="{$img.large|escape:'html':'UTF-8'}" class="{if $img@first}active{/if}" alt="">{/foreach}
          </div>
          {/if}
        {else}
          <div class="muted">Geen foto's. Het concept wordt dan alleen uit de tekst en gegevens gemaakt.</div>
        {/if}
      </div>
      <div class="kcpt-box">
        <h4>Bron voor het concept</h4>
        <div class="kcpt-facts">
          <div class="k">Huidige tekst</div><div>{$d.text_len|intval} tekens{if $d.text_len < $d.min} <span class="red">(te kort)</span>{/if}</div>
          <div class="k">Tags</div><div>{if $d.tags}{$d.tags|escape:'html':'UTF-8'}{else}<span class="muted">geen</span>{/if}</div>
          <div class="k">Meta-titel</div><div>{if $d.meta_title}{$d.meta_title|escape:'html':'UTF-8'}{else}<span class="muted">geen</span>{/if}</div>
          {if $d.ean13}<div class="k">EAN</div><div>{$d.ean13|escape:'html':'UTF-8'}</div>{/if}
        </div>
        <label for="kcpt-hint" style="margin-top:12px;font-weight:400;color:#5c5c5a">Extra aanwijzing voor dit product (optioneel)</label>
        <textarea id="kcpt-hint" class="kcpt-hint" rows="2" placeholder="Bijv. noem dat de rubbers los leverbaar zijn">{if $concept}{$concept.hint|escape:'html':'UTF-8'}{/if}</textarea>
        <button type="button" class="btn-kc" id="kcpt-regen" {if !$ai_configured}disabled{/if}>{if $has_draft}Nieuw concept maken{else}Concept maken{/if}</button>
        <span class="kcpt-busy" id="kcpt-regen-busy"></span>
      </div>
      {if $concept && $concept.status == 'done'}
      <div class="kcpt-box">
        <h4>Opgeslagen {$concept.date_saved|date_format:'%e %b %Y %H:%M'}</h4>
        <p class="pmeta">De tekst van vóór het opslaan is bewaard.</p>
        <button type="submit" name="submitKcmRestore" class="btn-kc" onclick="return confirm('De vorige tekst terugzetten? De huidige tekst wordt dan overschreven.')">Vorige tekst terugzetten</button>
      </div>
      {/if}
    </div>

    <div class="kcpt-main">
      <div class="kcpt-field">
        <div class="fhead"><b>Korte omschrijving</b><small>op categoriepagina's en als samenvatting bovenaan de productpagina</small><div class="spacer"></div>
          {if $d.short_plain == ''}<span class="badge-s failed">Nu leeg</span>{else}<span class="badge-s none">{$d.short_plain|count_characters:true} tekens</span>{/if}</div>
        <div class="cols">
          <div class="col cur"><div class="lab">Huidig</div><div class="curtext">{if $d.short_plain}{$d.short_plain|escape:'html':'UTF-8'}{else}<span class="muted">Geen korte omschrijving</span>{/if}</div></div>
          <div class="col new"><div class="lab">{if $has_draft}Concept, bewerkbaar{else}Nieuwe tekst{/if}<span class="cnt" id="kcpt-short-count"></span></div>
            <label for="kcpt-short" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Korte omschrijving</label>
            <textarea id="kcpt-short" name="draft_short" class="kcpt-ta" rows="4" maxlength="400">{$draft_short_plain|escape:'html':'UTF-8'}</textarea>
          </div>
        </div>
      </div>

      <div class="kcpt-field">
        <div class="fhead"><b>Omschrijving</b><small>het tabblad Omschrijving op de productpagina</small><div class="spacer"></div>
          <span class="badge-s {if $d.text_len < $d.min}failed{else}none{/if}">{$d.text_len|intval} tekens{if $d.text_len < $d.min}, te kort{/if}</span></div>
        <div class="cols">
          <div class="col cur"><div class="lab">Huidig</div><div class="curtext">{if $d.description}{$d.description nofilter}{else}<span class="muted">Geen omschrijving</span>{/if}</div></div>
          <div class="col new"><div class="lab">{if $has_draft}Concept, bewerkbaar{else}Nieuwe tekst{/if}<span class="cnt">{$draft_desc_len|intval} tekens</span></div>
            <label for="kcpt-desc" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Omschrijving</label>
            <textarea id="kcpt-desc" name="draft_desc" class="kcpt-ta kcpt-rte" rows="16">{$draft_desc|escape:'html':'UTF-8'}</textarea>
          </div>
        </div>
      </div>

      <div class="kcpt-actions">
        <label><input type="checkbox" name="goto_next" value="1" checked {if !$next_id}disabled{/if}> Na opslaan direct naar het volgende product</label>
        <div class="spacer"></div>
        {if $has_draft}<button type="submit" name="submitKcmDiscard" class="btn-kc danger lg">Concept verwerpen</button>{/if}
        <button type="submit" name="submitKcmSaveShort" class="btn-kc lg">Alleen korte omschrijving opslaan</button>
        <button type="submit" name="submitKcmSave" class="btn-kc primary lg">Opslaan{if $next_id} en volgende{/if}</button>
      </div>
      <div class="kcpt-note"><i class="icon-info-circle"></i><div>Opslaan schrijft alleen de twee tekstvelden van dit product. Prijs, voorraad, foto's, tags en metadata blijven ongemoeid. De vorige tekst wordt bewaard en kan per product worden teruggezet.</div></div>
    </div>
  </div>
  </form>
</div>
<script>
  window.kcptConfig = { ajaxUrl: '{$ajax_url|escape:'javascript':'UTF-8'}', idProduct: {$d.id_product|intval}, iso: '{$iso_lang|escape:'javascript':'UTF-8'}' };
</script>

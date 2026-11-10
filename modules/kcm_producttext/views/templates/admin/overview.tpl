{* kcm_producttext: overview *}
<div class="kcpt">
  <p class="kcpt-intro">Alle actieve producten op één pagina. Genereer een concept, kijk het na en sla op. Omschrijvingen worden pas gewijzigd als je in het nakijkscherm op Opslaan klikt.</p>

  {if !$ai_configured}
    <div class="alert alert-warning">Er is nog geen API-sleutel ingesteld. Ga naar Modules &rsaquo; AI-productomschrijving &rsaquo; Configureren.</div>
  {/if}

  <div class="kcpt-stats">
    <div class="kcpt-stat"><div class="label">Actieve producten</div><div class="value">{$stats.total|intval}</div><div class="sub">in {$stats.categories|intval} categorieën</div></div>
    <div class="kcpt-stat"><div class="label">Te korte omschrijving</div><div class="value red">{$stats.short_count|intval}</div><div class="sub">minder dan {$stats.min|intval} tekens</div></div>
    <div class="kcpt-stat"><div class="label">Concept klaar om na te kijken</div><div class="value orange">{$stats.drafts|intval}</div><div class="sub">{if $stats.queued}{$stats.queued|intval} in de wachtrij{else}gegenereerd, nog niet opgeslagen{/if}</div></div>
    <div class="kcpt-stat"><div class="label">Afgerond</div><div class="value green">{$stats.done|intval}</div><div class="sub">nagekeken en opgeslagen</div></div>
  </div>

  <div class="panel kcpt-settings" id="kcpt-settings">
    <h3><i class="icon-cogs"></i> Instellingen</h3>
    <form method="post" action="{$list_url|escape:'html':'UTF-8'}" class="form-inline">
      <label>Te kort onder <input type="number" name="min_chars" class="form-control" value="{$settings.min_chars|intval}" min="50" max="2000" style="width:90px"> tekens</label>
      &nbsp; <label>Per aanroep <input type="number" name="batch" class="form-control" value="{$settings.batch|intval}" min="1" max="6" style="width:70px"> producten</label>
      &nbsp; <label>Foto's meesturen <input type="number" name="max_images" class="form-control" value="{$settings.max_images|intval}" min="0" max="4" style="width:70px"></label>
      &nbsp; <button type="submit" name="submitKcmSettings" class="btn btn-default">Opslaan</button>
      <p class="help-block">Schrijfstijl, model en API-sleutel staan bij Modules &rsaquo; AI-productomschrijving.</p>
    </form>
  </div>

  <form method="post" action="{$list_url|escape:'html':'UTF-8'}" id="kcpt-list-form">
  <div class="kcpt-card">
    <div class="kcpt-toolbar">
      <div class="kcpt-pills">
        <a href="{$filters.short.url|escape:'html':'UTF-8'}" class="{if $filter == 'short'}active{/if}">Te kort ({$filters.short.count|intval})</a>
        <a href="{$filters.draft.url|escape:'html':'UTF-8'}" class="{if $filter == 'draft'}active{/if}">Concept klaar ({$filters.draft.count|intval})</a>
        <a href="{$filters.noshort.url|escape:'html':'UTF-8'}" class="{if $filter == 'noshort'}active{/if}">Zonder korte omschrijving ({$filters.noshort.count|intval})</a>
        {if $filters.failed.count}<a href="{$filters.failed.url|escape:'html':'UTF-8'}" class="{if $filter == 'failed'}active{/if}">Mislukt ({$filters.failed.count|intval})</a>{/if}
        <a href="{$filters.done.url|escape:'html':'UTF-8'}" class="{if $filter == 'done'}active{/if}">Afgerond ({$filters.done.count|intval})</a>
        <a href="{$filters.all.url|escape:'html':'UTF-8'}" class="{if $filter == 'all'}active{/if}">Alles ({$filters.all.count|intval})</a>
      </div>
      <div class="spacer"></div>
      <select onchange="window.location.href='{$base_url|escape:'javascript':'UTF-8'}&filter={$filter|escape:'javascript':'UTF-8'}&q={$q|escape:'url'}&cat='+this.value" aria-label="Categorie">
        <option value="0">Alle categorieën</option>
        {foreach $categories as $c}<option value="{$c.id_category|intval}" {if $cat == $c.id_category}selected{/if}>{$c.name|escape:'html':'UTF-8'} ({$c.n|intval})</option>{/foreach}
      </select>
      <input type="search" name="q" value="{$q|escape:'html':'UTF-8'}" placeholder="Zoek op naam of referentie" aria-label="Zoeken" onkeydown="if(event.key==='Enter'){ldelim}event.preventDefault();window.location.href='{$base_url|escape:'javascript':'UTF-8'}&filter={$filter|escape:'javascript':'UTF-8'}&cat={$cat|intval}&q='+encodeURIComponent(this.value);{rdelim}">
      <button type="submit" name="submitKcmQueue" id="kcpt-queue-btn" class="btn-kc primary" {if !$ai_configured}disabled{/if}>Concepten genereren voor selectie (<span id="kcpt-sel-count">0</span>)</button>
    </div>

    <div class="kcpt-banner" id="kcpt-banner">
      <div class="dot"></div>
      <div class="text"></div>
      <div class="bar"><i></i></div>
      <button type="button" class="btn-kc" id="kcpt-pause">Pauzeren</button>
    </div>

    <div style="overflow-x:auto">
    <table class="kcpt-table">
      <thead>
        <tr>
          <th style="width:36px"><input type="checkbox" id="kcpt-check-all" aria-label="Alles selecteren"></th>
          <th style="width:60px">Foto</th>
          <th>Product</th>
          <th style="width:160px">Huidige tekst</th>
          <th>Korte omschrijving nu</th>
          <th style="width:150px">Status</th>
          <th style="width:190px"></th>
        </tr>
      </thead>
      <tbody>
      {foreach $rows as $r}
        <tr class="{$r.status|escape:'html':'UTF-8'}">
          <td><input type="checkbox" name="ids[]" value="{$r.id_product|intval}" aria-label="Selecteer {$r.name|escape:'html':'UTF-8'}" {if $r.status == 'none' || $r.status == 'failed'}checked{/if}></td>
          <td>{if $r.thumb}<img class="thumb" src="{$r.thumb|escape:'html':'UTF-8'}" alt="">{else}<div class="thumb"></div>{/if}</td>
          <td>
            <div class="pname"><a href="{$r.review_url|escape:'html':'UTF-8'}">{$r.name|escape:'html':'UTF-8'}</a></div>
            <div class="pmeta">{$r.meta_line|escape:'html':'UTF-8'}</div>
          </td>
          <td><div class="lenbar"><div class="track"><i class="{$r.level}" style="width:{$r.pct|intval}%"></i></div><span>{$r.text_len|intval} tk.</span></div></td>
          <td>{if $r.short_plain}{$r.short_plain|escape:'html':'UTF-8'}{else}<span class="muted">Geen korte omschrijving</span>{/if}</td>
          <td>
            {if $r.status == 'draft'}<span class="badge-s draft">Concept klaar</span>
            {elseif $r.status == 'queued'}<span class="badge-s queued">In wachtrij</span>
            {elseif $r.status == 'failed'}<span class="badge-s failed" title="{$r.error|escape:'html':'UTF-8'}">Mislukt</span>
            {elseif $r.status == 'done'}<span class="badge-s done">Afgerond {if $r.date_saved}{$r.date_saved|date_format:'%e %b'}{/if}</span>
            {else}<span class="badge-s none">Nog geen concept</span>{/if}
          </td>
          <td class="text-right">
            {if $r.status == 'draft'}<a class="btn-kc primary" href="{$r.review_url|escape:'html':'UTF-8'}">Nakijken</a>
            {elseif $r.status == 'done'}<a class="btn-kc" href="{$r.review_url|escape:'html':'UTF-8'}">Bekijken</a>
            {else}<a class="btn-kc" href="{$r.review_url|escape:'html':'UTF-8'}">Zelf schrijven</a>{/if}
          </td>
        </tr>
      {foreachelse}
        <tr><td colspan="7" class="muted" style="padding:24px 16px">Geen producten in dit filter.</td></tr>
      {/foreach}
      </tbody>
    </table>
    </div>

    <div class="kcpt-foot">
      <div>{if $total}{(($page-1)*$per_page)+1} tot {min($page*$per_page, $total)} van {$total|intval} producten{else}0 producten{/if}</div>
      {if $filter != 'done' && $total > $rows|count}
        <button type="submit" name="submitKcmQueueFilter" class="btn-kc" {if !$ai_configured}disabled{/if} onclick="return confirm('Alle {$total|intval} producten in dit filter in de wachtrij zetten?')">Hele filter in de wachtrij ({$total|intval})</button>
      {/if}
      <div class="spacer" style="flex:1 1 40px"></div>
      {if $pages > 1}
      <div class="pages">
        {if $page > 1}<a href="{$list_url|escape:'html':'UTF-8'}&page={$page-1}">Vorige</a>{/if}
        {for $i=1 to $pages}{if $i == $page}<span class="cur">{$i}</span>{elseif $i <= 2 || $i >= $pages-1 || ($i >= $page-2 && $i <= $page+2)}<a href="{$list_url|escape:'html':'UTF-8'}&page={$i}">{$i}</a>{elseif $i == 3 || $i == $pages-2}<span>…</span>{/if}{/for}
        {if $page < $pages}<a href="{$list_url|escape:'html':'UTF-8'}&page={$page+1}">Volgende</a>{/if}
      </div>
      {/if}
    </div>
  </div>
  </form>

  <div class="kcpt-note"><i class="icon-info-circle"></i><div>Een concept wordt gemaakt uit de bestaande tekst, de productfoto's, referentie, fabrikantnummers en categorie. Het model verzint geen passing of bouwjaren die niet in de bron staan; controleer die velden toch altijd. Concepten staan apart opgeslagen en raken de live omschrijving niet.</div></div>
</div>
<script>
  window.kcptConfig = { ajaxUrl: '{$ajax_url|escape:'javascript':'UTF-8'}', listUrl: '{$list_url|escape:'javascript':'UTF-8'}', run: {if $run}1{else}0{/if}, queued: {$stats.queued|intval} };
</script>

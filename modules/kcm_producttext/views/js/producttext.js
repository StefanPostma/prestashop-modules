/* kcm_producttext: batch polling on the overview, regenerate + photo switch on the review screen. */
(function ($) {
  'use strict';
  $(function () {
    var cfg = window.kcptConfig || {};
    if (!cfg.ajaxUrl) { return; }

    /* ---------- overview ---------- */
    var $banner = $('#kcpt-banner');
    if ($banner.length) {
      // settings panel: the BO toolbar swallows the "#kcpt-settings" anchor click, so toggle it ourselves
      $(document).on('click', 'a[href$="#kcpt-settings"]', function (e) {
        e.preventDefault();
        var $p = $('#kcpt-settings').toggleClass('open');
        if ($p.hasClass('open')) { $('html, body').animate({ scrollTop: $p.offset().top - 80 }, 200); $p.find('input').first().focus(); }
      });
      if (window.location.hash === '#kcpt-settings') { $('#kcpt-settings').addClass('open'); }

      var paused = false, startRemaining = 0, doneCount = 0, failedCount = 0, running = false;

      $('#kcpt-check-all').on('change', function () {
        $('.kcpt-table input[name="ids[]"]').prop('checked', this.checked);
        updateSelCount();
      });
      $('.kcpt-table').on('change', 'input[name="ids[]"]', updateSelCount);
      function updateSelCount() {
        var n = $('.kcpt-table input[name="ids[]"]:checked').length;
        $('#kcpt-sel-count').text(n);
        $('#kcpt-queue-btn').prop('disabled', n === 0);
      }
      updateSelCount();

      $('#kcpt-pause').on('click', function () {
        paused = !paused;
        $(this).text(paused ? 'Hervatten' : 'Pauzeren');
        if (!paused && !running) { runBatch(); }
      });

      function showBanner(remaining, msg) {
        var total = startRemaining || remaining;
        var pct = total ? Math.round((total - remaining) / total * 100) : 0;
        $banner.addClass('on');
        $banner.find('.bar i').css('width', pct + '%');
        $banner.find('.text').text(msg || ('Concepten genereren: ' + (total - remaining) + ' van ' + total + ' klaar' + (remaining ? ', nog ongeveer ' + Math.max(1, Math.round(remaining * 9 / 60)) + ' min.' : '.') + ' Je kunt ondertussen gewoon nakijken.'));
      }

      function runBatch() {
        if (paused) { return; }
        running = true;
        $.ajax({ url: cfg.ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'runBatch' } })
          .done(function (r) {
            running = false;
            if (!r || r.ok === false) {
              showBanner(r && r.remaining || 0, 'Fout bij genereren: ' + (r && r.message ? r.message : 'onbekend') + '. Mislukte producten staan in het filter Mislukt.');
              return;
            }
            if (!startRemaining) { startRemaining = r.remaining + r.processed; }
            doneCount += r.drafts; failedCount += r.failed;
            if (r.remaining > 0 && r.processed > 0) {
              showBanner(r.remaining);
              runBatch();
            } else {
              showBanner(0, 'Klaar: ' + doneCount + ' concept(en) gemaakt' + (failedCount ? ', ' + failedCount + ' mislukt (zie filter Mislukt)' : '') + '. De pagina wordt ververst.');
              $('#kcpt-pause').hide();
              setTimeout(function () { window.location.href = cfg.listUrl; }, 1800);
            }
          })
          .fail(function (xhr) {
            running = false;
            showBanner(0, 'De server gaf een fout (' + xhr.status + '). Mogelijk duurde een aanroep te lang; klik Hervatten om door te gaan.');
            paused = true; $('#kcpt-pause').text('Hervatten');
          });
      }

      if (cfg.run && cfg.queued > 0) {
        startRemaining = cfg.queued;
        showBanner(cfg.queued);
        runBatch();
      }
    }

    /* ---------- review ---------- */
    var $regen = $('#kcpt-regen');
    if ($regen.length) {
      $('.kcpt-thumbs img').on('click', function () {
        $('.kcpt-thumbs img').removeClass('active');
        $(this).addClass('active');
        $('#kcpt-photo').attr('src', $(this).data('large'));
      });

      function setDesc(html) {
        var ed = window.tinymce && tinymce.get('kcpt-desc');
        if (ed) { ed.setContent(html); } else { $('#kcpt-desc').val(html); }
      }
      function countShort() {
        $('#kcpt-short-count').text($('#kcpt-short').val().length + ' / 400');
      }
      $('#kcpt-short').on('input', countShort);
      countShort();

      $regen.on('click', function () {
        var $b = $(this);
        $b.prop('disabled', true);
        $('#kcpt-regen-busy').addClass('on').text('Concept wordt gemaakt, dit duurt 10 tot 30 seconden.');
        $.ajax({ url: cfg.ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'regenerate', id_product: cfg.idProduct, hint: $('#kcpt-hint').val() } })
          .done(function (r) {
            $b.prop('disabled', false);
            if (!r || !r.ok) {
              $('#kcpt-regen-busy').text('Mislukt: ' + (r && r.message ? r.message : 'onbekende fout'));
              return;
            }
            $('#kcpt-short').val(r.description_short_plain || '');
            countShort();
            setDesc(r.description || '');
            $('#kcpt-regen-busy').text('Nieuw concept geplaatst. Kijk het na en sla op.');
          })
          .fail(function (xhr) {
            $b.prop('disabled', false);
            $('#kcpt-regen-busy').text('De server gaf een fout (' + xhr.status + '). Probeer het nog eens.');
          });
      });

      if (window.tinymce && typeof window.tinySetup === 'function') {
        try {
          tinySetup({ editor_selector: 'kcpt-rte', height: 420, menubar: false,
            toolbar: 'bold italic | bullist numlist | removeformat | code' });
        } catch (e) { /* plain textarea stays usable */ }
      }
    }
  });
})(jQuery);

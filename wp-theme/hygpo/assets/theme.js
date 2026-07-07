/* Hygpo theme — tiny vanilla JS. No jQuery dependency.
   1) Mobile search toggle (open the full-width search under the nav).
   2) Gallery status chip: read the plugin's .mwf-gallery state (is-paid/is-locked)
      and surface a small honest status pill. Theme never decides paid state.       */

(function () {
  'use strict';

  /* ---- 1. Mobile search toggle ---- */
  var toggle = document.getElementById('hygpo-search-toggle');
  var panel  = document.getElementById('hygpo-mobile-search');
  if (toggle && panel) {
    toggle.addEventListener('click', function () {
      var open = panel.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        var input = panel.querySelector('.mwf-search-input, input[type="search"]');
        if (input) { try { input.focus(); } catch (e) {} }
      }
    });
  }

  /* If someone deep-links to #hygpo-mobile-search (e.g. from 404), open it. */
  if (window.location.hash === '#hygpo-mobile-search' && panel && toggle) {
    panel.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
  }

  /* ---- 2. Gallery status chip ---- */
  function paintStatus() {
    var slot = document.querySelector('[data-hygpo-status]');
    var gallery = document.querySelector('.mwf-gallery');
    if (!slot || !gallery) { return; }

    var paid   = gallery.classList.contains('is-paid');
    var locked = gallery.classList.contains('is-locked') ||
                 (!paid && gallery.querySelector('.mwf-prompt-locked'));

    if (!paid && !locked) { return; } // unknown — leave hidden

    slot.hidden = false;
    if (paid) {
      slot.innerHTML =
        '<span class="post-status paid"><span aria-hidden="true">\u2713</span> ' +
        'Unlocked — prompts ready to translate</span>';
    } else {
      slot.innerHTML =
        '<span class="post-status locked"><span aria-hidden="true">\uD83D\uDD12</span> ' +
        'Prompts locked — unlock to reveal all</span>';
    }
  }

  if (document.readyState !== 'loading') {
    paintStatus();
  } else {
    document.addEventListener('DOMContentLoaded', paintStatus);
  }
  /* Re-check shortly after load in case the plugin renders the gallery late. */
  setTimeout(paintStatus, 400);
})();

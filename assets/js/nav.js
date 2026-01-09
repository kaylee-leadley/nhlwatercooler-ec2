// assets/js/nav.js
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('site-nav-toggle');
  var nav    = document.getElementById('site-nav');

  if (!toggle || !nav) return;

  // Ensure we start in a closed state
  nav.classList.remove('site-nav--open');
  toggle.setAttribute('aria-expanded', 'false');

  // All dropdown items (Threads, Data, etc.)
  var dropdownItems   = Array.prototype.slice.call(
    document.querySelectorAll('.site-nav__item--dropdown')
  );
  var dropdownToggles = dropdownItems
    .map(function (item) {
      return item.querySelector('.site-nav__link--dropdown');
    })
    .filter(function (btn) { return !!btn; });

  /* ------------ Nav (burger) helpers ------------ */
  function openNav() {
    nav.classList.add('site-nav--open');
    toggle.setAttribute('aria-expanded', 'true');
  }

  function closeNav() {
    nav.classList.remove('site-nav--open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  function isNavOpen() {
    return nav.classList.contains('site-nav--open');
  }

  /* ------------ Dropdown helpers (multi) ------------ */
  function closeAllDropdowns(exceptItem) {
    dropdownItems.forEach(function (item) {
      if (item === exceptItem) return;
      item.classList.remove('site-nav__item--open');
      var btn = item.querySelector('.site-nav__link--dropdown');
      if (btn) {
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function toggleDropdown(item) {
    if (!item) return;
    var isOpen = item.classList.contains('site-nav__item--open');
    if (isOpen) {
      item.classList.remove('site-nav__item--open');
      var btn = item.querySelector('.site-nav__link--dropdown');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    } else {
      closeAllDropdowns(item);
      item.classList.add('site-nav__item--open');
      var btn2 = item.querySelector('.site-nav__link--dropdown');
      if (btn2) btn2.setAttribute('aria-expanded', 'true');
    }
  }

  function anyDropdownOpen() {
    return dropdownItems.some(function (item) {
      return item.classList.contains('site-nav__item--open');
    });
  }

  /* ------------ Burger toggle ------------ */
  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    if (isNavOpen()) {
      closeNav();
      closeAllDropdowns();
    } else {
      openNav();
    }
  });

  /* ------------ Wire up each dropdown ------------ */
  dropdownToggles.forEach(function (btn) {
    btn.setAttribute('aria-haspopup', 'true');
    btn.setAttribute('aria-expanded', 'false');

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var item = btn.closest('.site-nav__item--dropdown');
      toggleDropdown(item);
    });
  });

  /* ------------ Global click handler ------------ */
  document.addEventListener('click', function (e) {
    // Close dropdowns if clicking outside any dropdown
    if (anyDropdownOpen()) {
      var clickedInsideDropdown = dropdownItems.some(function (item) {
        return item.contains(e.target);
      });

      if (!clickedInsideDropdown) {
        closeAllDropdowns();
      }
    }

    // Close nav if open and click outside nav & burger
    if (!isNavOpen()) return;
    if (!nav.contains(e.target) && e.target !== toggle) {
      closeNav();
      closeAllDropdowns();
    }
  });

  /* ------------ Close on link click (but not dropdown buttons) ------------ */
  nav.addEventListener('click', function (e) {
    var link = e.target.closest('a.site-nav__link, a.site-nav__dropdown-link');
    if (!link) return;

    // Don't auto-close when clicking the dropdown *button* itself
    if (link.classList.contains('site-nav__link--dropdown')) {
      return;
    }

    // For normal links (Schedule, Standings, Login, etc.)
    closeAllDropdowns();
    closeNav();
  });

  /* ------------ Reset on resize to desktop ------------ */
  window.addEventListener('resize', function () {
    if (window.innerWidth > 700) {
      closeNav();
      closeAllDropdowns();
      // Desktop layout handled by CSS; we just keep everything "closed".
    }
  });
});

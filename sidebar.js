// sidebar.js
(function () {
  var sidebar  = document.getElementById('mainSidebar');
  var toggle   = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop'); // optional

  if (!sidebar || !toggle) return;

  var STORAGE_KEY = 'sidebar-expanded';

  // --- Read saved state (default: expanded = true) ---
  var saved = localStorage.getItem(STORAGE_KEY);
  var isExpanded = (saved === null) ? true : (saved === '1');

  function applyState() {
    if (isExpanded) {
      sidebar.classList.add('expanded');
      toggle.setAttribute('aria-expanded', 'true');
      if (backdrop) backdrop.classList.add('is-open');
    } else {
      sidebar.classList.remove('expanded');
      toggle.setAttribute('aria-expanded', 'false');
      if (backdrop) backdrop.classList.remove('is-open');
    }
  }

  // Apply initial state on page load
  applyState();

  // --- Toggle handler (ONLY way to open/close) ---
  toggle.addEventListener('click', function () {
    isExpanded = !isExpanded;
    localStorage.setItem(STORAGE_KEY, isExpanded ? '1' : '0');
    applyState();
  });

  // NOTE: no ESC key listener, no backdrop click handler
  // so it will NOT close unless the user clicks the toggle.
})();

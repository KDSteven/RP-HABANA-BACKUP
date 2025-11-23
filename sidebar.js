// sidebar.js
(function () {
  var sidebar  = document.getElementById('mainSidebar');
  var toggle   = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop'); // optional

  if (!sidebar || !toggle) return;

  var STORAGE_KEY = 'sidebar-expanded';
  var saved = localStorage.getItem(STORAGE_KEY);
  var isExpanded = (saved === null) ? true : (saved === '1');

  function applyState() {
    if (isExpanded) {
      sidebar.classList.add('expanded');
      document.body.classList.remove('sidebar-collapsed');
      toggle.setAttribute('aria-expanded', 'true');
      if (backdrop) backdrop.classList.add('is-open');
    } else {
      sidebar.classList.remove('expanded');
      document.body.classList.add('sidebar-collapsed');
      toggle.setAttribute('aria-expanded', 'false');
      if (backdrop) backdrop.classList.remove('is-open');
    }
  }

  // Apply on load
  applyState();

  // Toggle handler
  toggle.addEventListener('click', function () {
    isExpanded = !isExpanded;
    localStorage.setItem(STORAGE_KEY, isExpanded ? '1' : '0');
    applyState();
  });
})();

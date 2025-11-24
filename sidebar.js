// sidebar.js
(function () {
  const sidebar = document.getElementById('mainSidebar');
  const toggle = document.getElementById('sidebarToggle');
  if (!sidebar || !toggle) return;

  // Read saved state (default = expanded)
  let isExpanded = localStorage.getItem('sidebar-expanded');
  isExpanded = isExpanded === null ? true : isExpanded === '1';

  function applyState() {
    if (isExpanded) {
      sidebar.classList.add('expanded');
      toggle.setAttribute('aria-expanded', 'true');
    } else {
      sidebar.classList.remove('expanded');
      toggle.setAttribute('aria-expanded', 'false');
    }
  }

  // Apply immediately (prevents flicker)
  applyState();

  // Toggle click
  toggle.addEventListener('click', () => {
    isExpanded = !isExpanded;
    localStorage.setItem('sidebar-expanded', isExpanded ? '1' : '0');
    applyState();
  });
})();

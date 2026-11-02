/**
 * NeuroGI Atlas - Main Frontend JavaScript
 */
document.addEventListener('DOMContentLoaded', () => {
  // 1. Focus search input when pressing '/'
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
      const searchInput = document.querySelector('input[type="search"], input[name="q"], #q');
      if (searchInput) {
        e.preventDefault();
        searchInput.focus();
        searchInput.select();
      }
    }
  });

  // 2. Tab switching helper
  const tabButtons = document.querySelectorAll('[data-tab-target]');
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.tabTarget;
      const parentGroup = btn.closest('.tabs-container') || document;
      
      parentGroup.querySelectorAll('[data-tab-target]').forEach(b => b.classList.remove('active'));
      parentGroup.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      
      btn.classList.add('active');
      const pane = document.getElementById(targetId);
      if (pane) pane.classList.add('active');
    });
  });

  // 3. Simple copy to clipboard helper
  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', () => {
      const text = btn.dataset.copy;
      navigator.clipboard.writeText(text).then(() => {
        const originalText = btn.innerHTML;
        btn.innerHTML = '✓ Copied';
        setTimeout(() => { btn.innerHTML = originalText; }, 1800);
      });
    });
  });
});

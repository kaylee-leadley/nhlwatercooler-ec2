// assets/js/table-sort.js

document.addEventListener('DOMContentLoaded', function () {
  const tables = document.querySelectorAll('.js-sortable-table');
  if (!tables.length) return;

  tables.forEach(table => {
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;

    const headers = Array.from(thead.querySelectorAll('th'));

    headers.forEach((th, index) => {
      th.addEventListener('click', () => {
        const sortType = th.dataset.sortType || 'string';
        const currentDir = th.dataset.sortDirection === 'asc' ? 'asc' : 'desc';
        const newDir = currentDir === 'asc' ? 'desc' : 'asc';

        // Clear direction on siblings, set on this one
        headers.forEach(h => h.removeAttribute('data-sort-direction'));
        th.dataset.sortDirection = newDir;

        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((rowA, rowB) => {
          const cellA = rowA.children[index];
          const cellB = rowB.children[index];
          const aText = cellA ? cellA.textContent.trim() : '';
          const bText = cellB ? cellB.textContent.trim() : '';

          let cmp;

          if (sortType === 'number') {
            const aNum = parseFloat(aText.replace(/[^0-9\-\.]/g, '')) || 0;
            const bNum = parseFloat(bText.replace(/[^0-9\-\.]/g, '')) || 0;
            cmp = aNum - bNum;
          } else {
            cmp = aText.localeCompare(bText);
          }

          return newDir === 'asc' ? cmp : -cmp;
        });

        rows.forEach(row => tbody.appendChild(row));
      });
    });
  });
});

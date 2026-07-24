/* Song Tags admin — drag & drop to nest a tag under a parent tag or move it into
   another category. External file (the site CSP blocks inline scripts). */
(function () {
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let dragId = null;

  async function move(params) {
    const body = new URLSearchParams(params);
    body.append('action', 'move');
    try {
      const res  = await fetch('/admin/song-tags.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body,
      });
      const data = await res.json();
      if (data && data.ok) { location.reload(); return; }
      alert((data && data.error) || 'Could not move the tag.');
    } catch (e) {
      alert('Network error moving the tag.');
    }
  }

  function clearHover() {
    document.querySelectorAll('.drop-hover').forEach(el => el.classList.remove('drop-hover'));
  }

  // Drag starts on the ⠿ handle (not the row, so text inputs stay selectable).
  document.querySelectorAll('.tag-drag').forEach(h => {
    h.addEventListener('dragstart', e => {
      const row = h.closest('.tag-row');
      dragId = row ? row.dataset.id : null;
      if (!dragId) return;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragId); } catch (err) {}
      row.classList.add('dragging');
    });
    h.addEventListener('dragend', () => {
      dragId = null;
      document.querySelectorAll('.tag-row.dragging').forEach(r => r.classList.remove('dragging'));
      clearHover();
    });
  });

  // Drop on a TOP-LEVEL tag row → nest the dragged tag under it.
  document.querySelectorAll('.tag-row[data-top="1"]').forEach(row => {
    row.addEventListener('dragover', e => {
      if (!dragId || dragId === row.dataset.id) return;
      e.preventDefault();
      e.stopPropagation();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drop-hover');
    });
    row.addEventListener('dragleave', () => row.classList.remove('drop-hover'));
    row.addEventListener('drop', e => {
      e.preventDefault();
      e.stopPropagation();           // don't also fire the group-zone drop
      clearHover();
      if (!dragId || dragId === row.dataset.id) return;
      move({ id: dragId, parent_id: row.dataset.id });
    });
  });

  // Drop anywhere else in a category section → move the tag there (top-level).
  document.querySelectorAll('.tag-group-zone').forEach(zone => {
    zone.addEventListener('dragover', e => {
      if (!dragId) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      zone.classList.add('drop-hover');
    });
    zone.addEventListener('dragleave', e => {
      if (e.target === zone) zone.classList.remove('drop-hover');
    });
    zone.addEventListener('drop', e => {
      e.preventDefault();
      clearHover();
      if (!dragId) return;
      move({ id: dragId, tag_group: zone.dataset.group });
    });
  });
})();

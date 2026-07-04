/* Admin → Students page interactions.
   External file (no inline scripts) to comply with the site CSP. Loaded at the
   bottom of the page, so the DOM is already parsed. */
(function () {
  // ── Date of birth → show Parent/Guardian only for minors (under 18) ──
  var dob      = document.getElementById('dobField');
  var guardian = document.getElementById('guardianField');

  if (dob && guardian) {
    function ageFromDob(value) {
      if (!value) return null;
      var d = new Date(value + 'T00:00:00');
      if (isNaN(d.getTime())) return null;
      var now = new Date();
      var age = now.getFullYear() - d.getFullYear();
      var m   = now.getMonth() - d.getMonth();
      if (m < 0 || (m === 0 && now.getDate() < d.getDate())) age--;
      return age;
    }

    function toggleGuardian() {
      var age = ageFromDob(dob.value);
      guardian.style.display = (age !== null && age < 18) ? '' : 'none';
    }

    dob.addEventListener('input', toggleGuardian);
    dob.addEventListener('change', toggleGuardian);
    toggleGuardian(); // set correct state on load
  }

  // ── Confirm before submitting any form that declares data-confirm ──
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // ── Bulk select + delete on the enrollments table ──
  var checkAll  = document.getElementById('check-all');
  var bulkBtn   = document.getElementById('bulk-delete-btn');
  var bulkCount = document.getElementById('bulk-count');

  function rowChecks() {
    return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
  }

  function refreshBulk() {
    var boxes   = rowChecks();
    var checked = boxes.filter(function (c) { return c.checked; });
    if (bulkCount) bulkCount.textContent = checked.length;
    if (bulkBtn)   bulkBtn.disabled = checked.length === 0;
    if (checkAll) {
      checkAll.checked       = boxes.length > 0 && checked.length === boxes.length;
      checkAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rowChecks().forEach(function (c) { c.checked = checkAll.checked; });
      refreshBulk();
    });
  }
  rowChecks().forEach(function (c) { c.addEventListener('change', refreshBulk); });
  if (checkAll || bulkBtn) refreshBulk();
})();

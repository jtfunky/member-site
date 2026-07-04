// Auto-fills an amount field from the selected program's data-amount attribute.
// CSP-safe (external file, no inline script). Wire a <select> up with:
//   data-amount-target="<id of the amount input>"   (required)
//   data-amount-mode="value" | "display"            (default "value")
//   data-amount-onchange                            (only update on change,
//                                                     not on load — preserves
//                                                     an existing saved value)
(function () {
  function money(n) { return '₱' + Number(n).toLocaleString(); }

  var selects = document.querySelectorAll('select[data-amount-target]');
  Array.prototype.forEach.call(selects, function (sel) {
    var target = document.getElementById(sel.getAttribute('data-amount-target'));
    if (!target) return;

    var mode         = sel.getAttribute('data-amount-mode') || 'value';
    var onlyOnChange = sel.hasAttribute('data-amount-onchange');

    function update() {
      var opt = sel.options[sel.selectedIndex];
      var amt = opt ? opt.getAttribute('data-amount') : null;
      if (amt === null || amt === '') { if (!onlyOnChange) target.value = ''; return; }
      target.value = (mode === 'display') ? money(amt) : amt;
    }

    sel.addEventListener('change', update);
    if (!onlyOnChange) update(); // initialise on load (display mode)
  });
})();

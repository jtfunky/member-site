// Stripe checkout launcher (active only when PAYMENT_MODE = 'stripe').
// Reads the publishable key + currency from the button's data attributes so no
// inline script is needed — keeps the strict Content-Security-Policy intact.
(function () {
  const btn = document.getElementById('stripe-btn');
  if (!btn || typeof Stripe === 'undefined') return;

  // eslint-disable-next-line no-undef
  const stripe = Stripe(btn.dataset.stripeKey);

  btn.addEventListener('click', async () => {
    const res = await fetch('/api/create-checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ currency: btn.dataset.currency }),
    });
    const { url } = await res.json();
    if (url) window.location.href = url;
  });
})();

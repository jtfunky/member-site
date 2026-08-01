// Floating support-chat widget. Conversation lives only in sessionStorage —
// the server is stateless and just replays whatever history the client sends.
(function () {
  const widget   = document.getElementById('chatbot-widget');
  if (!widget) return;

  const toggle   = document.getElementById('chatbot-toggle');
  const panel    = document.getElementById('chatbot-panel');
  const closeBtn = document.getElementById('chatbot-close');
  const list     = document.getElementById('chatbot-messages');
  const form     = document.getElementById('chatbot-form');
  const input    = document.getElementById('chatbot-input');
  const csrf     = form.querySelector('input[name="csrf_token"]').value;

  const STORAGE_KEY = 'zada_chatbot_history';
  let history = [];
  try { history = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { history = []; }
  history.forEach(m => renderMessage(m.role, m.content));

  function renderMessage(role, text) {
    const div = document.createElement('div');
    div.className = 'chatbot-msg chatbot-msg--' + (role === 'user' ? 'user' : 'bot');
    div.textContent = text;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
  }

  function saveHistory() {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-12))); } catch (e) { /* ignore */ }
  }

  toggle.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) input.focus();
  });
  closeBtn.addEventListener('click', () => { panel.hidden = true; });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    input.disabled = true;

    renderMessage('user', text);
    history.push({ role: 'user', content: text });
    saveHistory();

    const typing = document.createElement('div');
    typing.className = 'chatbot-msg chatbot-msg--bot chatbot-msg--typing';
    typing.textContent = '…';
    list.appendChild(typing);
    list.scrollTop = list.scrollHeight;

    try {
      const res = await fetch('/api/chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ messages: history }),
      });
      const data = await res.json();
      typing.remove();
      const reply = res.ok ? data.reply : (data.error || 'Something went wrong — please try again.');
      renderMessage('assistant', reply);
      if (res.ok) { history.push({ role: 'assistant', content: reply }); saveHistory(); }
    } catch (err) {
      typing.remove();
      renderMessage('assistant', 'Could not reach chat support — please check your connection and try again.');
    } finally {
      input.disabled = false;
      input.focus();
    }
  });
})();

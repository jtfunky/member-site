<?php
// Floating support-chat widget. Include from footer.php once $user is known;
// caller guards with chatbotEnabled() so nothing renders if no API key is set.
?>
<div id="chatbot-widget" class="chatbot-widget">
  <button type="button" id="chatbot-toggle" class="chatbot-toggle" aria-label="Open support chat">Help</button>
  <div id="chatbot-panel" class="chatbot-panel" hidden>
    <div class="chatbot-panel-head">
      <span>Need help?</span>
      <button type="button" id="chatbot-close" class="chatbot-close" aria-label="Close chat">&times;</button>
    </div>
    <div id="chatbot-messages" class="chatbot-messages">
      <div class="chatbot-msg chatbot-msg--bot">Hi! Ask me anything about the game, requesting songs, your plan, or your account — I'll do my best to help.</div>
    </div>
    <form id="chatbot-form" class="chatbot-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="text" id="chatbot-input" placeholder="Type a message…" maxlength="1000" autocomplete="off" required>
      <button type="submit" class="btn btn-primary btn-sm">Send</button>
    </form>
  </div>
</div>
<script src="/assets/js/chatbot.js?v=<?= @filemtime(__DIR__ . '/../assets/js/chatbot.js') ?: 1 ?>"></script>

<?php
// Members only — staff already have full site access and the panel just gets
// in the way of dense admin tables.
$_chatbotPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (isset($user) && $user && ($showNav ?? false)
    && !str_starts_with((string) $_chatbotPath, '/admin')
    && ($user['role'] ?? '') === 'user'
) {
    require_once __DIR__ . '/chatbot.php';
    if (chatbotEnabled()) include __DIR__ . '/chatbot-widget.php';
}
?>
</body>
</html>

<?php
// Shared, categorized admin nav — replaces the flat 14-item pill row that
// used to be copy-pasted verbatim into every admin/*.php file (painful to
// keep in sync — every new admin page needed the same edit repeated
// everywhere). Active page/category is auto-detected from the current URL,
// so callers just `require` this file, no parameters needed.
//
// Deliberately NOT used by admin/devices.php (intentionally minimal, only
// 3 items) or admin/song-requests.php / admin/song-tags.php (role-gated —
// editors/partners see a different subset than admins) — those keep their
// own inline nav rather than forcing role-awareness into this shared file.
$_navCurrent = basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($_navCurrent === '' || $_navCurrent === 'admin') $_navCurrent = 'index.php';
// Sub-pages reached from a group's page but not worth their own top-level
// entry — just keep the parent group highlighted while on them.
$_navAliases = ['plan-edit.php' => 'placement-tests.php'];
$_navActiveKey = $_navAliases[$_navCurrent] ?? $_navCurrent;

$_navGroups = [
    'People'  => ['users.php' => 'Users', 'students.php' => 'Students', 'devices.php' => 'My Devices'],
    'Content' => ['songs.php' => 'Songs', 'song-tags.php' => 'Song Tags', 'song-requests.php' => 'Song Requests', 'song-editor.php' => 'Chart Editor', 'placement-tests.php' => 'Placement Tests', 'sessions.php' => 'Sessions'],
    'Growth'  => ['raffle.php' => 'Raffle', 'referrals.php' => 'Referrals', 'promo-codes.php' => 'Promo Codes', 'coupons.php' => 'Coupons', 'facebook-poster.php' => 'FB Poster'],
    'System'  => ['investor-agreement.php' => 'Investor Agreement', 'settings.php' => 'Settings'],
];
?>
<div class="admin-nav-pills">
  <a href="/admin/" class="<?= $_navActiveKey === 'index.php' ? 'active' : '' ?>">Overview</a>
  <?php foreach ($_navGroups as $_groupName => $_items):
    $_groupActive = array_key_exists($_navActiveKey, $_items);
  ?>
  <details class="admin-nav-group"<?= $_groupActive ? ' open' : '' ?>>
    <summary class="<?= $_groupActive ? 'active' : '' ?>"><?= htmlspecialchars($_groupName) ?></summary>
    <div class="admin-nav-group-menu">
      <?php foreach ($_items as $_file => $_label): ?>
      <a href="/admin/<?= $_file ?>" class="<?= $_navActiveKey === $_file ? 'active' : '' ?>"><?= htmlspecialchars($_label) ?></a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

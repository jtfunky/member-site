<?php
// Shared, categorized admin nav — replaces the flat 14-item pill row that
// used to be copy-pasted verbatim into every admin/*.php file (painful to
// keep in sync — every new admin page needed the same edit repeated
// everywhere). Active page/category is auto-detected from the current URL,
// so callers just `require` this file, no parameters needed.
//
// Role-aware: editors (song-tags.php / song-requests.php / song-editor.php
// only) get a small flat row, no dropdown groups needed for 3 items. Admins
// get the full categorized menu. Partners have their own separate nav
// entirely (includes/nav.php) and never reach this file.
//
// Deliberately NOT used by admin/devices.php (intentionally minimal, only
// 3 items) — that one keeps its own inline nav.
$_navRole    = $user['role'] ?? 'admin';
$_navCurrent = basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($_navCurrent === '' || $_navCurrent === 'admin') $_navCurrent = 'index.php';
// Sub-pages reached from a group's page but not worth their own top-level
// entry — just keep the parent group highlighted while on them.
$_navAliases = ['plan-edit.php' => 'placement-tests.php'];
$_navActiveKey = $_navAliases[$_navCurrent] ?? $_navCurrent;

if ($_navRole === 'editor'):
    $_editorItems = ['song-tags.php' => 'Song Tags', 'song-requests.php' => 'Song Requests', 'song-editor.php' => 'Chart Editor'];
?>
<div class="admin-nav-pills">
  <?php foreach ($_editorItems as $_file => $_label): ?>
  <a href="/admin/<?= $_file ?>" class="<?= $_navActiveKey === $_file ? 'active' : '' ?>"><?= htmlspecialchars($_label) ?></a>
  <?php endforeach; ?>
</div>
<?php
else:
    $_navGroups = [
        'People'  => ['users.php' => 'Users', 'online-users.php' => 'Online Now', 'students.php' => 'Students', 'devices.php' => 'My Devices'],
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
  <details class="admin-nav-group" name="admin-nav-group"<?= $_groupActive ? ' open' : '' ?>>
    <summary class="<?= $_groupActive ? 'active' : '' ?>"><?= htmlspecialchars($_groupName) ?></summary>
    <div class="admin-nav-group-menu">
      <?php foreach ($_items as $_file => $_label): ?>
      <a href="/admin/<?= $_file ?>" class="<?= $_navActiveKey === $_file ? 'active' : '' ?>"><?= htmlspecialchars($_label) ?></a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

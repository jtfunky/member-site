<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireRoles(['admin', 'editor']);
$db   = db();

$message = '';
$error   = '';

// Tag groups: the three parent categories.
$GROUPS = ['genre' => 'Genre', 'skill' => 'Skill / Lesson', 'folder' => 'Custom Folder'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'move') {
        // Drag & drop (fetch): nest a tag under a parent tag, or move it into a
        // group as top-level. JSON response; one level of nesting max.
        header('Content-Type: application/json');
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $st = $db->prepare('SELECT * FROM song_tags WHERE id = ?');
            $st->execute([$id]);
            $tag = $st->fetch();
            if (!$tag) { echo json_encode(['error' => 'Tag not found.']); exit; }
            if (!array_key_exists('parent_id', $tag)) {
                echo json_encode(['error' => 'Sub-categories aren’t set up yet — run migrate-song-tags-parent.sql.']); exit;
            }

            if (!empty($_POST['parent_id'])) {
                // Nest under another tag.
                $pid = (int) $_POST['parent_id'];
                if ($pid === $id) { echo json_encode(['error' => 'A tag can’t be its own parent.']); exit; }
                $ps = $db->prepare('SELECT * FROM song_tags WHERE id = ?');
                $ps->execute([$pid]);
                $parent = $ps->fetch();
                if (!$parent) { echo json_encode(['error' => 'Parent tag not found.']); exit; }
                if (!empty($parent['parent_id'])) {
                    echo json_encode(['error' => 'Only one level of sub-categories — drop onto a top-level tag.']); exit;
                }
                $c = $db->prepare('SELECT COUNT(*) FROM song_tags WHERE parent_id = ?');
                $c->execute([$id]);
                if ((int) $c->fetchColumn() > 0) {
                    echo json_encode(['error' => 'That tag has sub-tags of its own — move them out first.']); exit;
                }
                // The child follows its parent's group.
                $db->prepare('UPDATE song_tags SET parent_id = ?, tag_group = ? WHERE id = ?')
                   ->execute([$pid, $parent['tag_group'], $id]);
                echo json_encode(['ok' => true]); exit;
            }

            $group = (string) ($_POST['tag_group'] ?? '');
            if (!isset($GROUPS[$group])) { echo json_encode(['error' => 'Unknown category.']); exit; }
            // Move into the group as top-level; any children follow the group.
            $db->beginTransaction();
            $db->prepare('UPDATE song_tags SET tag_group = ?, parent_id = NULL WHERE id = ?')->execute([$group, $id]);
            $db->prepare('UPDATE song_tags SET tag_group = ? WHERE parent_id = ?')->execute([$group, $id]);
            $db->commit();
            echo json_encode(['ok' => true]); exit;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error' => 'Move failed — a tag with the same name may already exist in that category.']);
            exit;
        }
    }

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $group = isset($GROUPS[$_POST['tag_group'] ?? '']) ? $_POST['tag_group'] : 'genre';
        if ($name === '') {
            $error = 'Enter a tag name.';
        } else {
            try {
                $db->prepare('INSERT INTO song_tags (name, tag_group) VALUES (?, ?)')->execute([$name, $group]);
                $message = 'Tag added.';
            } catch (\Throwable $e) {
                $error = 'That tag already exists in this group.';
            }
        }
    } elseif ($action === 'rename') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id && $name !== '') {
            try {
                $db->prepare('UPDATE song_tags SET name = ? WHERE id = ?')->execute([$name, $id]);
                $message = 'Tag renamed.';
            } catch (\Throwable $e) {
                $error = 'Rename failed — a tag with that name already exists in the group.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            // song_tag_map rows cascade; children are PROMOTED to top-level (FK SET NULL).
            $db->prepare('DELETE FROM song_tags WHERE id = ?')->execute([$id]);
            $message = 'Tag deleted.';
        }
    }
}

// Load all tags with a usage count (t.* so parent_id rides along when migrated).
$tags = [];
try {
    $tags = $db->query(
        'SELECT t.*, (SELECT COUNT(*) FROM song_tag_map m WHERE m.tag_id = t.id) AS uses
         FROM song_tags t ORDER BY t.tag_group, t.name'
    )->fetchAll();
} catch (\Throwable $e) {
    $error = 'The song_tags table is missing — run migrate-song-tags.sql in phpMyAdmin first.';
}

// Build the hierarchy: top-level tags per group, each with its children attached.
$byId = []; $kids = []; $tops = [];
foreach ($tags as $t) $byId[$t['id']] = $t;
foreach ($tags as $t) {
    $pid = (int) ($t['parent_id'] ?? 0);
    if ($pid && isset($byId[$pid])) $kids[$pid][] = $t;
    else $tops[] = $t;
}
$byGroup = [];
foreach ($tops as $t) {
    $t['_children'] = $kids[$t['id']] ?? [];
    $byGroup[$t['tag_group']][] = $t;
}

$pageTitle = 'Song Tags — Admin';
$pageCss   = ['main', 'admin'];
$showNav   = true;
$pageHead  = '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken()) . '">';
require __DIR__ . '/../includes/header.php';

// One row of the tag table (top-level rows are drop targets for nesting).
function tagRow(array $t, bool $isChild): void { ?>
        <tr class="tag-row" data-id="<?= (int) $t['id'] ?>" <?= $isChild ? '' : 'data-top="1"' ?>>
          <td>
            <span class="tag-drag" draggable="true" title="Drag onto a tag to nest it, or onto a category to move it">⠿</span>
            <?php if ($isChild): ?><span class="tag-child-arrow">↳</span><?php endif; ?>
            <form method="POST" action="/admin/song-tags.php" style="display:inline-flex;gap:.4rem;align-items:center">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <input type="text" name="name" value="<?= htmlspecialchars($t['name']) ?>" maxlength="60" style="min-width:160px">
              <button type="submit" class="btn btn-ghost btn-xs">Save</button>
            </form>
          </td>
          <td><?= (int) $t['uses'] ?> song<?= (int) $t['uses'] === 1 ? '' : 's' ?></td>
          <td style="text-align:right">
            <form method="POST" action="/admin/song-tags.php" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-danger btn-xs" title="Removes this tag from all songs; sub-tags become top-level">Delete</button>
            </form>
          </td>
        </tr>
<?php } ?>

<style>
  .tag-drag { cursor: grab; user-select: none; margin-right: .45rem; color: #94a3b8; font-size: 1.05rem; }
  .tag-child-arrow { color: #94a3b8; margin: 0 .3rem 0 1.1rem; }
  .tag-row.dragging { opacity: .45; }
  .tag-row.drop-hover td { outline: 2px dashed #8b5cf6; outline-offset: -2px; }
  .tag-group-zone { padding: .25rem; border-radius: 8px; }
  .tag-group-zone.drop-hover { outline: 2px dashed rgba(139,92,246,.6); background: rgba(139,92,246,.05); }
</style>

<main class="container container--wide">
<div class="admin-header">
  <h1>Song Tags</h1>
  <div class="admin-nav-pills">
    <?php if ($user['role'] === 'admin'): ?>
    <a href="/admin/">Overview</a>
    <a href="/admin/users.php">Users</a>
    <a href="/admin/students.php">Students</a>
    <a href="/admin/sessions.php">Sessions</a>
    <a href="/admin/songs.php">Songs</a>
    <?php endif; ?>
    <a href="/admin/song-tags.php" class="active">Song Tags</a>
    <a href="/admin/song-requests.php">Song Requests</a>
    <a href="/admin/song-editor.php">Chart Editor</a>
    <?php if ($user['role'] === 'admin'): ?>
    <a href="/admin/placement-tests.php">Placement Tests</a>
    <a href="/admin/investor-agreement.php">Investor Agreement</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<p class="muted">
  Admin-managed categories for songs; assign them in the
  <a href="/admin/song-editor.php">Chart Editor</a>.
  <strong>Drag the ⠿ handle</strong> onto another tag to nest it as a sub-category
  (e.g. Classic Rock under Rock), or onto a category section to move it there.
</p>

<form method="POST" action="/admin/song-tags.php" class="tag-add-form">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="add">
  <input type="text" name="name" placeholder="New tag name" maxlength="60" required style="min-width:180px">
  <select name="tag_group">
    <?php foreach ($GROUPS as $gk => $glabel): ?>
      <option value="<?= $gk ?>"><?= htmlspecialchars($glabel) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-primary">Add tag</button>
</form>

<?php foreach ($GROUPS as $gk => $glabel): $list = $byGroup[$gk] ?? []; ?>
  <div class="tag-group-zone" data-group="<?= $gk ?>">
  <h2 style="margin-top:1.25rem"><?= htmlspecialchars($glabel) ?></h2>
  <?php if (!$list): ?>
    <p class="muted">No <?= htmlspecialchars(strtolower($glabel)) ?> tags yet — drag one here or add above.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Tag</th><th>Used by</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($list as $t): ?>
        <?php tagRow($t, false); ?>
        <?php foreach ($t['_children'] as $c) tagRow($c, true); ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
<?php endforeach; ?>

</main>
<script src="/assets/js/song-tags-admin.js?v=20260711"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

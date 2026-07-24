-- ============================================================
--  Song tag sub-categories — members-site
--  Adds song_tags.parent_id so a tag can nest one level under another tag
--  (e.g. Classic Rock under Rock). Deleting a parent PROMOTES its children to
--  top-level (ON DELETE SET NULL). Managed by drag & drop on admin/song-tags.php.
--  Idempotent (information_schema guard). Run once via phpMyAdmin -> SQL.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_tag_parent;
DELIMITER //
CREATE PROCEDURE _add_tag_parent()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'song_tags' AND COLUMN_NAME = 'parent_id') THEN
    ALTER TABLE song_tags
      ADD COLUMN parent_id INT NULL AFTER tag_group,
      ADD INDEX idx_parent (parent_id),
      ADD CONSTRAINT fk_tag_parent FOREIGN KEY (parent_id)
        REFERENCES song_tags(id) ON DELETE SET NULL;
  END IF;
END //
DELIMITER ;
CALL _add_tag_parent();
DROP PROCEDURE _add_tag_parent;

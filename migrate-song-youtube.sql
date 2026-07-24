-- ============================================================
--  Song YouTube URL — members-site
--  Optional per-song YouTube link. When set (and the song has no hosted/local
--  audio taking priority), the game plays the embedded YouTube video as the
--  backing track in a "casual" mode (looser sync than local/hosted audio).
--  The admin sets the exact video the chart was timed to, in the Chart Editor.
--  Idempotent: checks information_schema first, safe to re-run.
--  Run once via phpMyAdmin -> SQL tab.
-- ============================================================

DROP PROCEDURE IF EXISTS _add_song_youtube;

DELIMITER //
CREATE PROCEDURE _add_song_youtube()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'youtube_url'
  ) THEN
    ALTER TABLE songs ADD COLUMN youtube_url VARCHAR(255) NULL AFTER audio_filename;
  END IF;
END //
DELIMITER ;

CALL _add_song_youtube();
DROP PROCEDURE _add_song_youtube;

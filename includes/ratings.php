<?php
// 5-star experience ratings — page-level ("request-song", "my-plan") and
// per-song (context='song', ref_id=song_id). See migrate-experience-ratings.sql.

const RATING_CONTEXTS = ['request-song', 'my-plan', 'song'];

function submitRating(int $userId, string $context, string $refId, int $rating): bool {
    if (!in_array($context, RATING_CONTEXTS, true)) return false;
    if ($rating < 1 || $rating > 5) return false;
    try {
        db()->prepare(
            'INSERT INTO experience_ratings (user_id, context, ref_id, rating)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = NOW()'
        )->execute([$userId, $context, $refId, $rating]);
        return true;
    } catch (\Throwable $e) {
        return false; // table not migrated yet — fail quietly, widget just won't save
    }
}

// This user's existing rating for a context+ref, or 0 if none yet — used to
// pre-fill the widget so returning visitors see their own stars already lit.
function getRating(int $userId, string $context, string $refId = ''): int {
    try {
        $st = db()->prepare('SELECT rating FROM experience_ratings WHERE user_id = ? AND context = ? AND ref_id = ?');
        $st->execute([$userId, $context, $refId]);
        return (int) ($st->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

// Renders a self-contained 5-star widget. $refId is only meaningful for
// context='song'; page-level contexts pass ''. Auto-wired by
// assets/js/rating-widget.js (mounts every .rating-widget on the page, and
// exposes RatingWidget.reset() for game.php to re-target it per song).
function ratingWidget(int $userId, string $context, string $refId = '', string $label = 'Rate your experience'): string {
    $current = getRating($userId, $context, $refId);
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= '<button type="button" class="rating-star' . ($i <= $current ? ' is-filled' : '') . '" data-value="' . $i . '" aria-label="' . $i . ' star' . ($i !== 1 ? 's' : '') . '"><i class="ti tif ti-star" aria-hidden="true"></i></button>';
    }
    return '<div class="rating-widget" data-context="' . htmlspecialchars($context) . '" data-ref-id="' . htmlspecialchars($refId) . '" data-rated="' . ($current > 0 ? '1' : '0') . '">'
         . '<span class="rating-label">' . htmlspecialchars($label) . '</span>'
         . '<span class="rating-stars">' . $stars . '</span>'
         . '<span class="rating-thanks" style="display:' . ($current > 0 ? 'inline' : 'none') . '">Thanks for your feedback!</span>'
         . '</div>';
}

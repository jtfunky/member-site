<?php
/**
 * Session-scheduling logic: available slots, bookings, the 24-hour reschedule
 * rule, and credit accounting. Booking deducts one credit immediately;
 * rescheduling (>= 24h before) just moves the slot with no refund.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/programs.php';

if (!defined('RESCHEDULE_CUTOFF_HOURS')) define('RESCHEDULE_CUTOFF_HOURS', 24);

// The student enrollment record linked to a login account, or null.
function studentForUser(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM students WHERE user_id = ? ORDER BY id LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function slotById(int $id): ?array {
    $st = db()->prepare('SELECT * FROM session_slots WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function slotIsBooked(int $slotId): bool {
    $st = db()->prepare('SELECT 1 FROM session_bookings WHERE slot_id = ?');
    $st->execute([$slotId]);
    return (bool) $st->fetchColumn();
}

// Active, future slots that nobody has booked yet.
function openSlots(): array {
    return db()->query(
        'SELECT s.* FROM session_slots s
         LEFT JOIN session_bookings b ON b.slot_id = s.id
         WHERE s.is_active = 1 AND s.starts_at > NOW() AND b.id IS NULL
         ORDER BY s.starts_at'
    )->fetchAll();
}

function bookingById(int $id): ?array {
    $st = db()->prepare(
        'SELECT b.*, s.starts_at, s.duration_min
         FROM session_bookings b JOIN session_slots s ON s.id = b.slot_id
         WHERE b.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// A student's bookings, upcoming or past.
function studentBookings(int $studentId, bool $upcoming): array {
    $cmp = $upcoming ? '>=' : '<';
    $ord = $upcoming ? 'ASC' : 'DESC';
    $st = db()->prepare(
        "SELECT b.*, s.starts_at, s.duration_min
         FROM session_bookings b JOIN session_slots s ON s.id = b.slot_id
         WHERE b.student_id = ? AND s.starts_at $cmp NOW()
         ORDER BY s.starts_at $ord"
    );
    $st->execute([$studentId]);
    return $st->fetchAll();
}

// A booked session may be rescheduled only up to RESCHEDULE_CUTOFF_HOURS before it.
function canReschedule(string $startsAt): bool {
    return (strtotime($startsAt) - time()) >= RESCHEDULE_CUTOFF_HOURS * 3600;
}

// Book an open slot: deducts one credit atomically. Returns true or an error string.
function bookSlot(int $slotId, array $student): string|true {
    $db = db();
    if ((int) $student['session_credits'] <= 0) return 'You have no session credits left.';

    $slot = slotById($slotId);
    if (!$slot || !$slot['is_active'])           return 'That slot is no longer available.';
    if (strtotime($slot['starts_at']) <= time()) return 'That slot is in the past.';
    if (slotIsBooked($slotId))                   return 'That slot was just taken — please pick another.';

    try {
        $db->beginTransaction();
        // UNIQUE(slot_id) guards against two students racing for the same slot.
        $db->prepare('INSERT INTO session_bookings (slot_id, student_id, user_id) VALUES (?,?,?)')
           ->execute([$slotId, $student['id'], $student['user_id'] ?: null]);
        // Guarded decrement so credits can never go negative.
        $upd = $db->prepare('UPDATE students SET session_credits = session_credits - 1 WHERE id = ? AND session_credits > 0');
        $upd->execute([$student['id']]);
        if ($upd->rowCount() !== 1) { $db->rollBack(); return 'You have no session credits left.'; }
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 'That slot was just taken — please pick another.';
    }
}

// Move an existing booking to a new open slot. No credit change.
function rescheduleBooking(int $bookingId, int $newSlotId, array $student): string|true {
    $db = db();
    $bk = bookingById($bookingId);
    if (!$bk || (int) $bk['student_id'] !== (int) $student['id']) return 'Booking not found.';
    if (!canReschedule($bk['starts_at'])) {
        return 'Sessions can only be rescheduled at least ' . RESCHEDULE_CUTOFF_HOURS . ' hours in advance.';
    }
    $slot = slotById($newSlotId);
    if (!$slot || !$slot['is_active'])           return 'That new slot is not available.';
    if (strtotime($slot['starts_at']) <= time()) return 'That new slot is in the past.';
    if (slotIsBooked($newSlotId))                return 'That new slot was just taken — please pick another.';

    try {
        $db->prepare('UPDATE session_bookings SET slot_id = ?, status = "scheduled" WHERE id = ?')
           ->execute([$newSlotId, $bookingId]);
        return true;
    } catch (\Throwable $e) {
        return 'That new slot was just taken — please pick another.';
    }
}

// Cancel a booking: frees the slot and refunds one credit. Only allowed up to
// RESCHEDULE_CUTOFF_HOURS before the session. Returns true or an error string.
function cancelBooking(int $bookingId, array $student): string|true {
    $db = db();
    $bk = bookingById($bookingId);
    if (!$bk || (int) $bk['student_id'] !== (int) $student['id']) return 'Booking not found.';
    if (!canReschedule($bk['starts_at'])) {
        return 'Sessions can only be cancelled at least ' . RESCHEDULE_CUTOFF_HOURS . ' hours in advance.';
    }
    try {
        $db->beginTransaction();
        $del = $db->prepare('DELETE FROM session_bookings WHERE id = ? AND student_id = ?');
        $del->execute([$bookingId, (int) $student['id']]);
        if ($del->rowCount() === 1) {
            // Refund the credit only when a booking was actually removed (no double refund).
            $db->prepare('UPDATE students SET session_credits = session_credits + 1 WHERE id = ?')
               ->execute([(int) $student['id']]);
        }
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 'Could not cancel the session. Please try again.';
    }
}

// ── Admin helpers ──────────────────────────────────────────

// Every session is a fixed 90-minute block.
const SESSION_MINUTES = 90;

// The bookable 90-minute blocks in a day: 9:00 AM through 9:00 PM (8 blocks).
// Each entry: start/end as 'HH:MM' (24h) plus a human label like "9:00 AM – 10:30 AM".
function sessionBlocks(): array {
    $blocks = [];
    for ($i = 0; $i < 8; $i++) {
        $startMin = 9 * 60 + $i * SESSION_MINUTES;
        $endMin   = $startMin + SESSION_MINUTES;
        $start    = sprintf('%02d:%02d', intdiv($startMin, 60), $startMin % 60);
        $end      = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);
        $blocks[] = [
            'start' => $start,
            'end'   => $end,
            'label' => date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end)),
        ];
    }
    return $blocks;
}

// Existing slot at this exact start datetime (to avoid opening duplicates).
function slotAt(string $datetime): ?array {
    $st = db()->prepare('SELECT * FROM session_slots WHERE starts_at = ? LIMIT 1');
    $st->execute([$datetime]);
    return $st->fetch() ?: null;
}

function addSlot(string $startsAt, int $duration): void {
    db()->prepare('INSERT INTO session_slots (starts_at, duration_min) VALUES (?, ?)')
        ->execute([$startsAt, $duration > 0 ? $duration : 60]);
}

// Delete a slot only if it isn't booked (keeps a student's session safe).
function deleteSlotIfOpen(int $slotId): bool {
    if (slotIsBooked($slotId)) return false;
    db()->prepare('DELETE FROM session_slots WHERE id = ?')->execute([$slotId]);
    return true;
}

// Remove every unbooked slot (closes all availability). Booked slots are kept.
// Returns how many slots were removed.
function closeAllOpenSlots(): int {
    $st = db()->prepare(
        'DELETE s FROM session_slots s
         LEFT JOIN session_bookings b ON b.slot_id = s.id
         WHERE b.id IS NULL'
    );
    $st->execute();
    return $st->rowCount();
}

// Upcoming slots with any booking + the student who booked (for the admin view).
function adminUpcomingSlots(): array {
    return db()->query(
        'SELECT s.*, b.id AS booking_id, b.status AS booking_status,
                st.id AS student_id, st.full_name AS student_name
         FROM session_slots s
         LEFT JOIN session_bookings b ON b.slot_id = s.id
         LEFT JOIN students st        ON st.id = b.student_id
         WHERE s.starts_at > NOW()
         ORDER BY s.starts_at'
    )->fetchAll();
}

// All slots within a given month (YYYY-MM) with booking + student info.
function adminSlotsForMonth(string $ym): array {
    $start = $ym . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($ym . '-01'));
    $st = db()->prepare(
        'SELECT s.*, b.id AS booking_id, b.status AS booking_status,
                st.id AS student_id, st.full_name AS student_name
         FROM session_slots s
         LEFT JOIN session_bookings b ON b.slot_id = s.id
         LEFT JOIN students st        ON st.id = b.student_id
         WHERE s.starts_at BETWEEN ? AND ?
         ORDER BY s.starts_at'
    );
    $st->execute([$start, $end]);
    return $st->fetchAll();
}

function pendingTopups(): array {
    return db()->query(
        "SELECT t.*, st.full_name AS student_name, st.email AS student_email
         FROM session_topups t JOIN students st ON st.id = t.student_id
         WHERE t.status = 'pending' ORDER BY t.created_at"
    )->fetchAll();
}

// Approve a top-up: add its credits to the student and mark it approved.
function approveTopup(int $topupId, int $adminId): void {
    $db = db();
    $st = $db->prepare("SELECT * FROM session_topups WHERE id = ? AND status = 'pending'");
    $st->execute([$topupId]);
    $t = $st->fetch();
    if (!$t) return;
    try {
        $db->beginTransaction();
        $db->prepare('UPDATE students SET session_credits = session_credits + ? WHERE id = ?')
           ->execute([(int) $t['credits'], (int) $t['student_id']]);
        $db->prepare("UPDATE session_topups SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$adminId, $topupId]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
    }
}

function rejectTopup(int $topupId, int $adminId): void {
    db()->prepare("UPDATE session_topups SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'")
        ->execute([$adminId, $topupId]);
}

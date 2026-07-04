<?php
/**
 * Course/program options offered at enrollment, each mapped to its amount
 * (in PHP). Shared by the public enrollment form (student-signup.php) and the admin
 * student form (admin/students.php) so the two never drift apart.
 */
function coursePrograms(): array {
    return [
        'ONE ON ONE (F2F) - ₱9,000'    => 9000,
        'ONE ON ONE (ONLINE) - ₱2,000' => 2000,
        'ONE ON ONE (ONLINE) - ₱9,000' => 9000,
    ];
}

/**
 * Session credits granted by each program. Used when an admin approves a
 * payment and when a student tops up. Programs not listed grant 0 (e.g. a
 * pure Website-access membership isn't session-based).
 */
function programSessionCredits(): array {
    return [
        'ONE ON ONE (F2F) - ₱9,000'    => 6,
        'ONE ON ONE (ONLINE) - ₱2,000' => 1,
        'ONE ON ONE (ONLINE) - ₱9,000' => 6,
    ];
}

// Credits a given program is worth (0 if unknown / non-session).
function creditsForProgram(string $program): int {
    return programSessionCredits()[$program] ?? 0;
}

// Program name for the Website-access (content-only, non-session) membership.
const WEB_ACCESS_PROGRAM = 'Website access';

// True if the student has one-on-one session access — either current credits or
// a program that grants them. Web-access-only students return false. A null
// student (admin / legacy account with no enrollment row) keeps the default
// behaviour (true) so session links aren't hidden from non-students.
function studentHasSessions(?array $student): bool {
    if (!$student) return true;
    if ((int) ($student['session_credits'] ?? 0) > 0) return true;
    return creditsForProgram(trim((string) ($student['program'] ?? ''))) > 0;
}

// Give a self-signup (direct registration or social login) a Website-access
// student enrollment record, so every account shows up in the Students admin.
// No-op if the user already has a student record (by user_id or email). Never
// throws — signup/login must not break if the students table is unavailable.
function ensureWebAccessStudent(int $userId): void {
    if ($userId <= 0) return;
    try {
        $u = db()->prepare('SELECT email, first_name, last_name, currency FROM users WHERE id = ?');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) return;

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') return;

        $chk = db()->prepare('SELECT 1 FROM students WHERE user_id = ? OR email = ? LIMIT 1');
        $chk->execute([$userId, $email]);
        if ($chk->fetchColumn()) return; // already enrolled

        $first    = trim((string) ($user['first_name'] ?? ''));
        $last     = trim((string) ($user['last_name'] ?? ''));
        $full     = trim($last . ($first !== '' ? ', ' . $first : ''));
        if ($full === '') $full = $email;
        $currency = trim((string) ($user['currency'] ?? '')) ?: (function_exists('getUserCurrency') ? getUserCurrency() : 'PHP');
        $amount   = function_exists('getPrice') ? getPrice($currency) : null;

        // profile_completed = 0 → the account must finish its profile before
        // accessing content. Wrapped so a pre-migration DB (no such column)
        // still creates the student without the flag.
        try {
            db()->prepare(
                'INSERT INTO students
                   (user_id, enrolled_at, email, first_name, last_name, full_name,
                    program, program_amount, currency, payment_status, source_file, profile_completed)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, "Trial", "self-signup", 0)'
            )->execute([$userId, $email, $first, $last, $full, WEB_ACCESS_PROGRAM, $amount, $currency]);
        } catch (\Throwable $e) {
            db()->prepare(
                'INSERT INTO students
                   (user_id, enrolled_at, email, first_name, last_name, full_name,
                    program, program_amount, currency, payment_status, source_file)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, "Trial", "self-signup")'
            )->execute([$userId, $email, $first, $last, $full, WEB_ACCESS_PROGRAM, $amount, $currency]);
        }
    } catch (\Throwable $e) { /* never block signup/login */ }
}

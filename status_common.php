<?php
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$userEmail      = $_SESSION['user_email'] ?? 'unknown';
$isAdmin        = !empty($_SESSION['is_admin']);

// -------------------------- FUNCTION -------------------------

/**
 * Return a label like:
 *   "Team 1: Max Yaw, John Smith, Joe Blough"
 * for the team that $personId is on for $assignmentId.
 * Returns null if no team is found.
 */
function get_team_label_for_assignment(PDO $pdo, int $assignmentId, int $personId): ?string
{
    static $cache = [];

    $key = $assignmentId . ':' . $personId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sql = '
        SELECT ta.team_number,
               GROUP_CONCAT(
                   CONCAT(p.fname, " ", p.lname)
                   ORDER BY p.lname, p.fname
                   SEPARATOR ", "
               ) AS members
        FROM teamassignments ta
        JOIN teamassignments ta2
          ON ta2.assignment_id = ta.assignment_id
         AND ta2.team_number   = ta.team_number
        JOIN persons p
          ON p.id = ta2.person_id
        WHERE ta.assignment_id = :aid
          AND ta.person_id     = :pid
        GROUP BY ta.team_number
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':aid' => $assignmentId,
        ':pid' => $personId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$key] = null;
    }

    $label = 'Team ' . $row['team_number'] . ': ' . $row['members'];
    return $cache[$key] = $label;
}

// ---------- LOAD ALL ASSIGNMENTS ----------
$assignmentsStmt = $pdo->query('
    SELECT id, name, date_assigned, date_due
    FROM assignments
    ORDER BY date_assigned ASC, id ASC
');
$assignments = $assignmentsStmt->fetchAll();

// ---------- NON-ADMIN: STATS FOR THIS USER (GIVEN + RECEIVED) ----------
$givenStatsByAssignment    = [];
$receivedStatsByAssignment = [];

if (!$isAdmin) {
    // Reviews GIVEN by this user
    $stmtGiven = $pdo->prepare('
        SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
        FROM reviews
        WHERE reviewer_id = :uid
        GROUP BY assignment_id
    ');
    $stmtGiven->execute([':uid' => $loggedInUserId]);
    foreach ($stmtGiven->fetchAll() as $row) {
        $aid = (int)$row['assignment_id'];
        $givenStatsByAssignment[$aid] = [
            'cnt' => (int)$row['cnt'],
            'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
        ];
    }

    // Reviews RECEIVED by this user
    $stmtReceived = $pdo->prepare('
        SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
        FROM reviews
        WHERE student_id = :sid
        GROUP BY assignment_id
    ');
    $stmtReceived->execute([':sid' => $loggedInUserId]);
    foreach ($stmtReceived->fetchAll() as $row) {
        $aid = (int)$row['assignment_id'];
        $receivedStatsByAssignment[$aid] = [
            'cnt' => (int)$row['cnt'],
            'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
        ];
    }
}

// ---------- ADMIN: STATS BY ASSIGNMENT AND STUDENT (GIVEN + RECEIVED) ----------
$students = [];
$statsGivenByAssignmentAndStudent    = [];
$statsReceivedByAssignmentAndStudent = [];

if ($isAdmin) {
    // Load all non-admin students
    $studentsStmt = $pdo->query('
        SELECT id, fname, lname, email, isAdmin
        FROM persons
        ORDER BY lname ASC, fname ASC
    ');
    $allPersons = $studentsStmt->fetchAll();
    foreach ($allPersons as $p) {
        if (!empty($p['isAdmin'])) {
            continue; // skip admins in per-student summary
        }
        $students[] = $p;
    }

    // Reviews RECEIVED: group by assignment_id, student_id
    $aggReceived = $pdo->query('
        SELECT assignment_id, student_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
        FROM reviews
        GROUP BY assignment_id, student_id
    ');
    foreach ($aggReceived->fetchAll() as $row) {
        $aid = (int)$row['assignment_id'];
        $sid = (int)$row['student_id'];
        if (!isset($statsReceivedByAssignmentAndStudent[$aid])) {
            $statsReceivedByAssignmentAndStudent[$aid] = [];
        }
        $statsReceivedByAssignmentAndStudent[$aid][$sid] = [
            'cnt' => (int)$row['cnt'],
            'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
        ];
    }

    // Reviews GIVEN: group by assignment_id, reviewer_id
    $aggGiven = $pdo->query('
        SELECT assignment_id, reviewer_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
        FROM reviews
        GROUP BY assignment_id, reviewer_id
    ');
    foreach ($aggGiven->fetchAll() as $row) {
        $aid = (int)$row['assignment_id'];
        $sid = (int)$row['reviewer_id']; // same student table, but as reviewer
        if (!isset($statsGivenByAssignmentAndStudent[$aid])) {
            $statsGivenByAssignmentAndStudent[$aid] = [];
        }
        $statsGivenByAssignmentAndStudent[$aid][$sid] = [
            'cnt' => (int)$row['cnt'],
            'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
        ];
    }

        // Prepare detail queries for nested per-student breakdown
    $stmtDetailsGiven = $pdo->prepare('
        SELECT r.id, r.assignment_id, r.reviewer_id, r.student_id,
               r.rating, r.comments, r.date_last_edited, r.date_finalized,
               s.fname AS student_fname, s.lname AS student_lname
        FROM reviews r
        JOIN persons s ON r.student_id = s.id
        WHERE r.assignment_id = :aid
          AND r.reviewer_id   = :sid
        ORDER BY s.lname, s.fname
    ');

    $stmtDetailsReceived = $pdo->prepare('
        SELECT r.id, r.assignment_id, r.reviewer_id, r.student_id,
               r.rating, r.comments, r.date_last_edited, r.date_finalized,
               rv.fname AS reviewer_fname, rv.lname AS reviewer_lname
        FROM reviews r
        JOIN persons rv ON r.reviewer_id = rv.id
        WHERE r.assignment_id = :aid
          AND r.student_id    = :sid
        ORDER BY rv.lname, rv.fname
    ');
}



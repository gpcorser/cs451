<?php
session_start();
// require __DIR__ . '/config.php';
require '../database/config.php';
require __DIR__ . '/upload_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$userEmail      = $_SESSION['user_email'] ?? 'unknown';
$isAdmin        = !empty($_SESSION['is_admin']);

// Upload constraints
$MAX_FILES_PER_SCOPE = 3;
$MAX_UPLOAD_BYTES    = 2 * 1024 * 1024; // 2MB per file

$message = '';

/**
 * Return the team_number for a (assignment, person) pair, or 0 if none.
 */
function get_team_number(PDO $pdo, int $assignmentId, int $personId): int
{
    $stmt = $pdo->prepare('SELECT team_number FROM teamassignments WHERE assignment_id = :aid AND person_id = :pid LIMIT 1');
    $stmt->execute([':aid' => $assignmentId, ':pid' => $personId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * True if two users are on the same team for a given assignment.
 */
function is_same_team(PDO $pdo, int $assignmentId, int $personA, int $personB): bool
{
    $teamA = get_team_number($pdo, $assignmentId, $personA);
    if ($teamA <= 0) return false;
    $teamB = get_team_number($pdo, $assignmentId, $personB);
    return $teamA > 0 && $teamA === $teamB;
}

/**
 * Load submission ZIP file IDs per (person_id, file_index) for ONE assignment.
 * Returns: $map[person_id][file_index] = file_id
 */
/**
 * Load submission ZIP file IDs per (person_id, file_index) for ONE assignment.
 * Returns: $map[person_id][file_index] = file_id
 *
 * Compatibility:
 * - If legacy data uses file_index 0..2, shift to 1..3 for UI rendering.
 * - If modern data uses 1..3 (or higher), leave as-is.
 */
function load_submission_zip_ids_for_assignment(PDO $pdo, int $assignmentId): array
{
    $stmt = $pdo->prepare('
        SELECT id, person_id, file_index
        FROM teamassignment_files
        WHERE assignment_id = :aid
        ORDER BY person_id ASC, file_index ASC
    ');
    $stmt->execute([':aid' => $assignmentId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Detect legacy 0-based indexing for this assignment
    $minIdx = null;
    $maxIdx = null;
    foreach ($rows as $r) {
        $idx = (int)$r['file_index'];
        $minIdx = ($minIdx === null) ? $idx : min($minIdx, $idx);
        $maxIdx = ($maxIdx === null) ? $idx : max($maxIdx, $idx);
    }
    $isLegacyZeroBased = ($minIdx === 0 && $maxIdx !== null && $maxIdx <= 2);

    $map = [];
    foreach ($rows as $r) {
        $pid = (int)$r['person_id'];
        $idx = (int)$r['file_index'];

        if ($isLegacyZeroBased) {
            $idx = $idx + 1; // shift 0..2 -> 1..3
        }

        // Only map slots 1..3 for the UI buttons
        if ($idx >= 1 && $idx <= 3) {
            $map[$pid][$idx] = (int)$r['id'];
        }
    }

    return $map;
}


/**
 * Render compact "1 2 3" buttons for the ZIP submissions of a given student.
 * Disabled buttons indicate no file in that slot.
 */
function render_zip_buttons(array $zipIdMap, int $personId): string
{
    $html = '<div class="btn-group btn-group-sm" role="group" aria-label="ZIP files">';
    for ($i = 1; $i <= 3; $i++) {
        if (!empty($zipIdMap[$personId][$i])) {
            $fid = (int)$zipIdMap[$personId][$i];
            $html .= '<a class="btn btn-outline-secondary" title="Download ZIP ' . $i . '" '
                  .  'href="download.php?type=submission&id=' . $fid . '">' . $i . '</a>';
        } else {
            $html .= '<button type="button" class="btn btn-outline-secondary" disabled>' . $i . '</button>';
        }
    }
    $html .= '</div>';
    return $html;
}


// -------------------------- SUBMISSION UPLOAD / DELETE -------------------------

// Students upload ZIP submissions (per assignment, per student).
// Teachers/admins upload PDFs elsewhere (Assignments screen).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {

        if ($action === 'upload_submission') {
            $aid = (int)($_POST['assignment_id'] ?? 0);
            $pid = (int)($_POST['person_id'] ?? 0);

            if ($aid <= 0 || $pid <= 0) {
                throw new Exception('Invalid upload target.');
            }

            // Permission: admin OR uploading for yourself
            if (!$isAdmin && $pid !== $loggedInUserId) {
                throw new Exception('Not authorized to upload for this student.');
            }

            // Must be assigned to a team for that assignment (unless admin forcing)
            if (!$isAdmin) {
                $tn = get_team_number($pdo, $aid, $pid);
                if ($tn <= 0) {
                    throw new Exception('You are not assigned to a team for this assignment.');
                }
            }

            if (empty($_FILES['submission_zip'])) {
                throw new Exception('No file field found.');
            }

            $flat = flatten_files_array($_FILES['submission_zip']);
            $clean = validate_uploads($flat, 1, $MAX_UPLOAD_BYTES, ['zip']);
            $f = $clean[0];

            // Slot management: fill next available file_index (1..3)
            $slot = next_available_index($pdo, 'teamassignment_files', [
                'assignment_id' => $aid,
                'person_id'     => $pid,
            ], $MAX_FILES_PER_SCOPE);
            if ($slot <= 0) {
                throw new Exception('You already uploaded the maximum of ' . $MAX_FILES_PER_SCOPE . ' submission(s) for this assignment. Delete one to upload another.');
            }

            $stored = random_storage_name('zip');
            $dir = __DIR__ . '/uploads/submissions/' . $aid . '/' . $pid;
            ensure_dir($dir);
            $dest = $dir . '/' . $stored;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new Exception('Failed to move uploaded file.');
            }

            $ins = $pdo->prepare('
                INSERT INTO teamassignment_files
                    (assignment_id, person_id, file_index, original_name, stored_name, mime_type, file_size, uploaded_by)
                VALUES
                    (:aid, :pid, :idx, :orig, :stored, :mime, :size, :uby)
            ');
            $ins->execute([
                ':aid'    => $aid,
                ':pid'    => $pid,
                ':idx'    => $slot,
                ':orig'   => $f['orig_name'],
                ':stored' => $stored,
                ':mime'   => $f['mime'],
                ':size'   => $f['size'],
                ':uby'    => $loggedInUserId,
            ]);

            $message = 'Submission uploaded (slot ' . $slot . ').';
        }

        if ($action === 'delete_submission') {
            $fileId = (int)($_POST['file_id'] ?? 0);
            if ($fileId <= 0) {
                throw new Exception('Invalid file id.');
            }

            $stmt = $pdo->prepare('SELECT id, assignment_id, person_id, stored_name FROM teamassignment_files WHERE id = :id');
            $stmt->execute([':id' => $fileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('File not found.');
            }

            $aid = (int)$row['assignment_id'];
            $pid = (int)$row['person_id'];

            // Permission: admin OR owner
            if (!$isAdmin && $pid !== $loggedInUserId) {
                throw new Exception('Not authorized to delete this submission.');
            }

            $del = $pdo->prepare('DELETE FROM teamassignment_files WHERE id = :id');
            $del->execute([':id' => $fileId]);

            $path = __DIR__ . '/uploads/submissions/' . $aid . '/' . $pid . '/' . $row['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }

            $message = 'Submission deleted.';
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
    }
}

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

// ---------- FILE LISTS (instruction PDFs + my submissions) ----------
$assignmentPdfs = [];          // [assignment_id] => list of rows
$mySubmissionsByAssignment = []; // [assignment_id] => list of rows

if (!empty($assignments)) {
    $ids = array_map(fn($a) => (int)$a['id'], $assignments);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    // Instruction PDFs (teacher uploads)
    $stmtPdf = $pdo->prepare(
        'SELECT id, assignment_id, file_index, original_name, uploaded_at '
      . 'FROM assignment_files WHERE assignment_id IN (' . $in . ') '
      . 'ORDER BY assignment_id ASC, file_index ASC'
    );
    $stmtPdf->execute($ids);
    foreach ($stmtPdf->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aid = (int)$r['assignment_id'];
        $assignmentPdfs[$aid] ??= [];
        $assignmentPdfs[$aid][] = $r;
    }

    // My ZIP submissions (student uploads)
    $stmtMine = $pdo->prepare(
        'SELECT id, assignment_id, person_id, file_index, original_name, uploaded_at '
      . 'FROM teamassignment_files WHERE person_id = ? AND assignment_id IN (' . $in . ') '
      . 'ORDER BY assignment_id ASC, file_index ASC'
    );
    $stmtMine->execute(array_merge([$loggedInUserId], $ids));
    foreach ($stmtMine->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aid = (int)$r['assignment_id'];
        $mySubmissionsByAssignment[$aid] ??= [];
        $mySubmissionsByAssignment[$aid][] = $r;
    }
}

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



<?php
/**
 * assignments_logic.php
 *
 * All non-UI logic for assignments.php:
 * - generateTeamsForAssignment()
 * - handle admin POST actions (create/update/delete)
 * - load assignments and team assignments
 * - load editing state
 * - load review stats
 * - prepare form values
 *
 * Expects these variables to exist (set in assignments.php):
 *   - $pdo
 *   - $loggedInUserId
 *   - $userEmail
 *   - $isAdmin
 *   - $message
 */

/**
 * Generate team assignments for a given assignment using ONLY team sizes of 3 or 4.
 *
 * Rules:
 * - Prefer teams of 3
 * - Use teams of 4 ONLY when necessary to avoid 1- or 2-person leftover
 * - Never generate team sizes other than 3 or 4
 * - Existing teamassignments for this assignment are deleted first
 *
 * Edge cases:
 * - N < 3 -> impossible
 * - N = 5 -> impossible (cannot partition into only 3/4)
 * - N = 2,1 -> impossible
 * - N = 4 -> OK (one team of 4)
 *
 * @param PDO $pdo
 * @param int $assignmentId
 * @return int number of rows inserted into teamassignments
 * @throws Exception on error
 */
function generateTeamsForAssignment(PDO $pdo, int $assignmentId): int
{
    $assignmentId = (int)$assignmentId;

    // 1) Ensure assignment exists (team_size stored, but we enforce 3/4-only)
    $stmt = $pdo->prepare('SELECT team_size FROM assignments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $assignmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Assignment not found (id=' . $assignmentId . ').');
    }

    // 2) Load all non-admin student IDs
    $stmt = $pdo->query('SELECT id FROM persons WHERE isAdmin = 0 ORDER BY id');
    $studentIds = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $studentIds[] = (int)$r['id'];
    }

    $numStudents = count($studentIds);

    if ($numStudents < 3) {
        throw new Exception('Not enough students to form teams of 3 (or 4).');
    }
    if ($numStudents === 5) {
        throw new Exception('Cannot form teams of only size 3 or 4 with 5 students.');
    }

    // 3) Fisher–Yates shuffle for a thorough randomization
    for ($i = $numStudents - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $studentIds[$i];
        $studentIds[$i] = $studentIds[$j];
        $studentIds[$j] = $tmp;
    }

    // 4) Compute team sizes (only 3s and 4s)
    //
    // Prefer all 3s; adjust based on remainder:
    // - N mod 3 == 0 -> all 3s
    // - N mod 3 == 1 -> one 4 + rest 3s
    // - N mod 3 == 2 -> two 4s + rest 3s (requires N >= 8)
    $mod = $numStudents % 3;

    $numTeamsOf4 = 0;
    if ($mod === 0) {
        $numTeamsOf4 = 0;
    } elseif ($mod === 1) {
        // Need exactly one team of 4
        $numTeamsOf4 = 1;
    } elseif ($mod === 2) {
        // Need exactly two teams of 4; requires N >= 8
        if ($numStudents < 8) {
            throw new Exception('Cannot form teams of only size 3 or 4 with ' . $numStudents . ' students.');
        }
        $numTeamsOf4 = 2;
    }

    $remainingAfter4s = $numStudents - (4 * $numTeamsOf4);
    if ($remainingAfter4s < 0 || ($remainingAfter4s % 3) !== 0) {
        throw new Exception('Internal error computing 3/4 team split for N=' . $numStudents);
    }

    $numTeamsOf3 = (int)($remainingAfter4s / 3);

    // Build size list; randomize which team numbers are 4s
    $teamSizes = array_merge(
        array_fill(0, $numTeamsOf4, 4),
        array_fill(0, $numTeamsOf3, 3)
    );
    shuffle($teamSizes);

    $numTeams = count($teamSizes);

    // 5) Transaction: delete old rows for this assignment, then insert new ones
    $pdo->beginTransaction();

    try {
        $del = $pdo->prepare('DELETE FROM teamassignments WHERE assignment_id = :assignment_id');
        $del->execute([':assignment_id' => $assignmentId]);

        $insert = $pdo->prepare('
            INSERT INTO teamassignments (assignment_id, team_number, person_id)
            VALUES (:assignment_id, :team_number, :person_id)
        ');

        $insertCount = 0;
        $index = 0;

        for ($teamNumber = 1; $teamNumber <= $numTeams; $teamNumber++) {
            $thisTeamSize = $teamSizes[$teamNumber - 1];

            for ($k = 0; $k < $thisTeamSize; $k++) {
                if ($index >= $numStudents) {
                    break;
                }
                $personId = $studentIds[$index++];

                $insert->execute([
                    ':assignment_id' => $assignmentId,
                    ':team_number'   => $teamNumber,
                    ':person_id'     => $personId,
                ]);
                $insertCount++;
            }
        }

        $pdo->commit();
        return $insertCount;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}


// ---------- HANDLE ADMIN POST ACTIONS ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name         = trim($_POST['name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $dateAssigned = $_POST['date_assigned'] ?? '';
        $dateDue      = $_POST['date_due'] ?? '';

        // Team size: default 3; must be 3 or 4
        $teamSizeRaw = trim($_POST['team_size'] ?? '');
        $teamSize = ($teamSizeRaw === '') ? 3 : (int)$teamSizeRaw;

        if ($name === '' || $description === '' || $dateAssigned === '' || $dateDue === '') {
            $message = 'Please fill in all fields for the assignment.';
        } elseif (!in_array($teamSize, [3, 4], true)) {
            $message = 'Team size must be 3 (default) or 4.';
        } else {

            if ($action === 'create') {
                $stmt = $pdo->prepare('
                    INSERT INTO assignments (name, description, date_assigned, date_due, team_size)
                    VALUES (:name, :description, :date_assigned, :date_due, :team_size)
                ');
                $stmt->execute([
                    ':name'          => $name,
                    ':description'   => $description,
                    ':date_assigned' => $dateAssigned,
                    ':date_due'      => $dateDue,
                    ':team_size'     => $teamSize,
                ]);

                $newId = (int)$pdo->lastInsertId();

                // Always generate teams on create
                try {
                    $rows = generateTeamsForAssignment($pdo, $newId);
                    $message = 'Assignment created and ' . $rows . ' team assignment records generated.';
                } catch (Throwable $e) {
                    $message = 'Assignment created, but error generating teams: ' . $e->getMessage();
                }

            } else { // update
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0) {
                    // Check if teams already exist (if yes, do not allow changing team_size)
                    $check = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid');
                    $check->execute([':aid' => $id]);
                    $teamCount = (int)$check->fetchColumn();

                    if ($teamCount > 0) {
                        // Lock team_size once teams exist: overwrite $teamSize to current DB value
                        $cur = $pdo->prepare('SELECT team_size FROM assignments WHERE id = :id');
                        $cur->execute([':id' => $id]);
                        $curSize = (int)$cur->fetchColumn();
                        $teamSize = $curSize;
                    }

                    $stmt = $pdo->prepare('
                        UPDATE assignments
                        SET name = :name,
                            description = :description,
                            date_assigned = :date_assigned,
                            date_due = :date_due,
                            team_size = :team_size
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        ':name'          => $name,
                        ':description'   => $description,
                        ':date_assigned' => $dateAssigned,
                        ':date_due'      => $dateDue,
                        ':team_size'     => $teamSize,
                        ':id'            => $id,
                    ]);

                    // After update, generate teams ONLY if none exist yet
                    if ($teamCount === 0) {
                        try {
                            $rows = generateTeamsForAssignment($pdo, $id);
                            $message = 'Assignment updated and ' . $rows . ' team assignment records generated.';
                        } catch (Throwable $e) {
                            $message = 'Assignment updated, but error generating teams: ' . $e->getMessage();
                        }
                    } else {
                        $message = 'Assignment updated.';
                    }

                } else {
                    $message = 'Invalid assignment ID.';
                }
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $message = 'Assignment deleted.';
        } else {
            $message = 'Invalid assignment ID.';
        }
    }
}


// ---------- LOAD ASSIGNMENTS ----------
$assignmentsStmt = $pdo->query('
    SELECT id, name, description, date_assigned, date_due, team_size
    FROM assignments
    ORDER BY date_assigned ASC, id ASC
');
$assignments = $assignmentsStmt->fetchAll();


// ---------- LOAD TEAM ASSIGNMENTS FOR SELECTED ASSIGNMENT (IF ANY) ----------
$selectedTeamsAssignmentId   = null;
$selectedTeamsAssignmentName = '';
$teamAssignmentsRows         = [];

if (isset($_GET['show_teams'])) {
    $selectedTeamsAssignmentId = (int)$_GET['show_teams'];

    if ($selectedTeamsAssignmentId > 0) {
        // Get assignment name
        $stmt = $pdo->prepare('SELECT name FROM assignments WHERE id = :id');
        $stmt->execute([':id' => $selectedTeamsAssignmentId]);
        $assignmentRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($assignmentRow) {
            $selectedTeamsAssignmentName = $assignmentRow['name'];

            // Get team assignments with student names
            $stmt = $pdo->prepare('
                SELECT ta.team_number,
                       p.fname,
                       p.lname,
                       p.id AS person_id
                FROM teamassignments AS ta
                JOIN persons AS p ON ta.person_id = p.id
                WHERE ta.assignment_id = :aid
                ORDER BY ta.team_number ASC, p.lname ASC, p.fname ASC
            ');
            $stmt->execute([':aid' => $selectedTeamsAssignmentId]);
            $teamAssignmentsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}


// ---------- LOAD EDITING ASSIGNMENT (IF ANY) ----------
$editingAssignment = null;
$editingHasTeams   = false;

if ($isAdmin && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editingAssignment) {
            $message = 'Assignment not found for editing.';
        } else {
            // Check if this assignment already has teamassignments
            $check = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid');
            $check->execute([':aid' => $editId]);
            $editingHasTeams = ((int)$check->fetchColumn() > 0);
        }
    }
}


// ---------- REVIEW STATS FOR CURRENT USER (kept, even if you no longer show them) ----------
$givenStatsByAssignment    = [];
$receivedStatsByAssignment = [];

// Given
$stmtGiven = $pdo->prepare('
    SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
    FROM reviews
    WHERE reviewer_id = :uid
    GROUP BY assignment_id
');
$stmtGiven->execute([':uid' => $loggedInUserId]);
foreach ($stmtGiven->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $aid = (int)$row['assignment_id'];
    $givenStatsByAssignment[$aid] = [
        'cnt' => (int)$row['cnt'],
        'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
    ];
}

// Received
$stmtReceived = $pdo->prepare('
    SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
    FROM reviews
    WHERE student_id = :sid
    GROUP BY assignment_id
');
$stmtReceived->execute([':sid' => $loggedInUserId]);
foreach ($stmtReceived->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $aid = (int)$row['assignment_id'];
    $receivedStatsByAssignment[$aid] = [
        'cnt' => (int)$row['cnt'],
        'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
    ];
}


// ---------- FORM VALUES ----------
$formMode  = $editingAssignment ? 'update' : 'create';
$formTitle = $editingAssignment ? 'Edit Assignment' : 'Add New Assignment';

$valId           = $editingAssignment['id']            ?? 0;
$valName         = $editingAssignment['name']          ?? '';
$valDescription  = $editingAssignment['description']   ?? '';
$valDateAssigned = $editingAssignment['date_assigned'] ?? '';
$valDateDue      = $editingAssignment['date_due']      ?? '';
$valTeamSize     = $editingAssignment['team_size']     ?? 3;

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
$message        = '';

/**
 * Generate team assignments for a given assignment.
 *
 * - Reads assignments.team_size
 * - Includes all non-admin persons (persons.isAdmin = 0)
 * - Each student is assigned to exactly one team for this assignment
 * - Existing teamassignments for this assignment are deleted first
 *
 * @param PDO $pdo
 * @param int $assignmentId
 * @return int number of rows inserted into teamassignments
 * @throws Exception on error
 */

function generateTeamsForAssignment($pdo, $assignmentId)
{
    $assignmentId = (int)$assignmentId;

    // 1. Get team_size from assignments table
    $stmt = $pdo->prepare('SELECT team_size FROM assignments WHERE id = :id LIMIT 1');
    $stmt->execute(array(':id' => $assignmentId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Assignment not found (id=' . $assignmentId . ').');
    }

    $rawTeamSize = (int)$row['team_size'];

    // Enforce an effective minimum of 2 to honor "no one-person team"
    $teamSize = max(2, $rawTeamSize);

    // 2. Build array of all non-admin student IDs
    $stmt = $pdo->query('SELECT id FROM persons WHERE isAdmin = 0 ORDER BY id');
    $studentIds = array();
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $studentIds[] = (int)$r['id'];
    }

    $numStudents = count($studentIds);
    if ($numStudents === 0) {
        throw new Exception('No non-admin students found to assign.');
    }
    if ($numStudents === 1) {
        // Cannot form a team of size >= 2 with only one student
        throw new Exception('Only one non-admin student found; cannot form teams without a one-person team.');
    }

    // 3. Fisher–Yates shuffle for a thorough randomization
    for ($i = $numStudents - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $studentIds[$i];
        $studentIds[$i] = $studentIds[$j];
        $studentIds[$j] = $tmp;
    }

    // 4. Decide how many teams to make, guaranteeing no 1-person team
    //
    // We choose T = floor(numStudents / teamSize) (at least 1).
    // With teamSize >= 2 and numStudents >= 2, this ensures that
    // the "base" team size will be >= 2.
    $numTeams = (int)floor($numStudents / $teamSize);
    if ($numTeams < 1) {
        $numTeams = 1;
    }

    // baseSize = floor(numStudents / numTeams)
    // remainder = numStudents % numTeams
    // First "remainder" teams get baseSize+1, the rest get baseSize.
    // With teamSize >= 2, this guarantees baseSize >= 2 → no 1-person teams.
    $baseSize  = (int)floor($numStudents / $numTeams);
    $remainder = $numStudents % $numTeams;

    if ($baseSize < 2) {
        // This *shouldn't* happen with the logic above, but just in case,
        // fall back to 1 team with everyone (which will be >= 2 students).
        $numTeams = 1;
        $baseSize = $numStudents;
        $remainder = 0;
    }

    // 5. Transaction: delete old rows for this assignment, then insert new ones
    $pdo->beginTransaction();

    try {
        // Delete any existing teamassignments for this assignment
        $del = $pdo->prepare('DELETE FROM teamassignments WHERE assignment_id = :assignment_id');
        $del->execute(array(':assignment_id' => $assignmentId));

        // Prepare insert
        $insert = $pdo->prepare('
            INSERT INTO teamassignments (assignment_id, team_number, person_id)
            VALUES (:assignment_id, :team_number, :person_id)
        ');

        $insertCount = 0;
        $index       = 0; // pointer into $studentIds

        // For each team, decide its size and assign that many students
        for ($teamNumber = 1; $teamNumber <= $numTeams; $teamNumber++) {
            $thisTeamSize = $baseSize + (($teamNumber <= $remainder) ? 1 : 0);

            for ($k = 0; $k < $thisTeamSize; $k++) {
                if ($index >= $numStudents) {
                    break;
                }

                $personId = $studentIds[$index++];
                $insert->execute(array(
                    ':assignment_id' => $assignmentId,
                    ':team_number'   => $teamNumber,
                    ':person_id'     => $personId,
                ));
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

        // Handle team_size: default 5, must be >= 1
        $teamSizeRaw = trim($_POST['team_size'] ?? '');
        if ($teamSizeRaw === '') {
            $teamSize = 5;
        } else {
            $teamSize = (int)$teamSizeRaw;
        }

        if ($name === '' || $description === '' || $dateAssigned === '' || $dateDue === '') {
            $message = 'Please fill in all fields for the assignment.';
        } elseif ($teamSize < 1) {
            $message = 'Team size must be at least 1.';
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
                try {
                    $rows = generateTeamsForAssignment($pdo, $newId);
                    $message = 'Assignment created and ' . $rows . ' team assignment records generated.';
                } catch (Throwable $e) {
                    $message = 'Assignment created, but error generating teams: ' . $e->getMessage();
                }

            } else { // update
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
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
                    $check = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid');
                    $check->execute([':aid' => $id]);
                    $teamCount = (int)$check->fetchColumn();

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
        $editingAssignment = $stmt->fetch();
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


// ---------- REVIEW STATS FOR CURRENT USER ----------
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
foreach ($stmtGiven->fetchAll() as $row) {
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
foreach ($stmtReceived->fetchAll() as $row) {
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
$valTeamSize     = $editingAssignment['team_size']     ?? 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assignments - CS-451 Peer Review</title>
    <link rel="shortcut icon" href="https://mypages.svsu.edu/~gpcorser/cs451/cs451_icon_dalle.png" type="image/png">
    <link rel="icon" href="https://mypages.svsu.edu/~gpcorser/cs451/cs451_icon_dalle.png" type="image/png">

    <!-- Bootstrap 5 CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap"
        rel="stylesheet"
    >

    <!-- Custom CSS -->
    <link rel="stylesheet" href="cs451.css">
</head>
<body class="app-body">
    <div class="app-shell">
        <div class="app-header-row">
            <div>
                <h1 class="app-title-main">Assignments</h1>
                <p class="app-subline">
                    You are logged in as <?php echo htmlspecialchars($userEmail); ?>
                    (id=<?php echo $loggedInUserId; ?>)
                    <?php if ($isAdmin): ?>
                        — <strong>Admin</strong>
                    <?php endif; ?>
                </p>
            </div>
            <div class="app-actions">
                <a href="statusReport.php" class="btn btn-outline-modern btn-sm">Status Report</a>
                <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info alert-modern mb-3" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <!-- Form box with fieldset/legend -->
            <fieldset class="form-box-peach mb-4">
                <legend class="form-box-legend">
                    <?php echo htmlspecialchars($formTitle); ?>
                </legend>

                <form method="post" action="assignments.php<?php
                    echo $editingAssignment ? '?edit=' . (int)$valId : '';
                ?>">
                    <input type="hidden" name="action" value="<?php echo htmlspecialchars($formMode); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$valId; ?>">

                    <div class="row g-2 align-items-start">
                        <div class="col-md-3">
                            <label for="name" class="form-label mb-1">Name</label>
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                id="name"
                                name="name"
                                value="<?php echo htmlspecialchars($valName); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label for="description" class="form-label mb-1">Description</label>
                            <textarea
                                class="form-control form-control-sm"
                                id="description"
                                name="description"
                                rows="2"
                                required
                            ><?php echo htmlspecialchars($valDescription); ?></textarea>
                        </div>

                        <div class="col-md-1">
                            <label for="team_size" class="form-label mb-1">Team Size</label>
                            <input
                                type="number"
                                class="form-control form-control-sm"
                                id="team_size"
                                name="team_size"
                                min="1"
                                value="<?php echo htmlspecialchars($valTeamSize); ?>"
                                <?php echo (!empty($editingAssignment) && $editingHasTeams) ? 'readonly' : ''; ?>
                                required
                            >
                            <?php if (!empty($editingAssignment) && $editingHasTeams): ?>
                                <div class="form-text small">
                                    Team size cannot be changed after teams are generated.
                                </div>
                            <?php endif; ?>
                        </div>


                        <div class="col-md-2">
                            <label for="date_assigned" class="form-label mb-1">Assigned</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                id="date_assigned"
                                name="date_assigned"
                                value="<?php echo htmlspecialchars($valDateAssigned); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-2">
                            <label for="date_due" class="form-label mb-1">Due</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                id="date_due"
                                name="date_due"
                                value="<?php echo htmlspecialchars($valDateDue); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-1 d-flex align-items-end">
                            <div class="d-flex flex-column w-100">
                                <button type="submit" class="btn btn-modern btn-sm mb-1 w-100">
                                    <?php echo $editingAssignment ? 'Update' : 'Add'; ?>
                                </button>
                                <?php if ($editingAssignment): ?>
                                    <a href="assignments.php" class="btn btn-outline-modern btn-sm w-100">
                                        Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </fieldset>
        <?php else: ?>
            <p class="mb-3">
                Only the instructor can create or edit assignments. You can view the list below and add peer reviews.
            </p>
        <?php endif; ?>

        <h2 class="status-section-title">Assignment List</h2>

        <?php if (empty($assignments)): ?>
            <p>No assignments have been created yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle status-table">
                    <thead>
                        <tr>
                            <th>Assignment</th>
                            <th class="text-nowrap">Assigned</th>
                            <th class="text-nowrap">Due</th>
                            <th class="text-nowrap">Team Size</th>
                            <th>Reviews (you)</th>
                            <th style="width: 210px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <?php
                                $aid = (int)$a['id'];

                                $gStats = $givenStatsByAssignment[$aid]    ?? ['cnt' => 0, 'avg' => null];
                                $rStats = $receivedStatsByAssignment[$aid] ?? ['cnt' => 0, 'avg' => null];

                                $gCnt = $gStats['cnt'];
                                $gAvg = $gStats['avg'];
                                $rCnt = $rStats['cnt'];
                                $rAvg = $rStats['avg'];

                                $gAvgText = ($gCnt > 0 && $gAvg !== null) ? number_format($gAvg, 1) : '-';
                                $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format($rAvg, 1) : '-';

                                $reviewsSummary = sprintf(
                                    'written:%d(%s), received:%d(%s)',
                                    $gCnt,
                                    $gAvgText,
                                    $rCnt,
                                    $rAvgText
                                );
                            ?>
                            <tr>
<td>
    <a
        href="assignments.php?show_teams=<?php echo $aid; ?>"
        class="btn btn-outline-modern btn-sm mb-1"
    >
        Show Team Assignments
    </a><br>
    <strong><?php echo htmlspecialchars($a['name']); ?></strong><br>
    <small class="text-muted">
        <?php echo nl2br(htmlspecialchars($a['description'])); ?>
    </small>
</td>

                                <td class="text-nowrap"><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($a['date_due']); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($a['team_size']); ?></td>
                                <td><?php echo htmlspecialchars($reviewsSummary); ?></td>
                                <td>
                                    <a
                                        href="reviews.php?assignment_id=<?php echo $aid; ?>"
                                        class="btn btn-modern btn-sm mb-1 w-100"
                                    >
                                        Open Reviews
                                    </a>

                                    <?php if ($isAdmin): ?>
                                        <div class="d-flex gap-1">
                                            <a
                                                href="assignments.php?edit=<?php echo $aid; ?>"
                                                class="btn btn-outline-modern btn-sm flex-fill"
                                            >
                                                Edit
                                            </a>
                                            <form
                                                method="post"
                                                action="assignments.php"
                                                class="flex-fill"
                                                onsubmit="return confirm('Delete this assignment?');"
                                            >
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $aid; ?>">
                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-modern btn-sm w-100"
                                                >
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

<?php if ($selectedTeamsAssignmentId !== null && $selectedTeamsAssignmentName !== ''): ?>
            <hr class="mt-4 mb-3">
            <h2 class="status-section-title">
                Team Assignments for Assignment: <?php echo htmlspecialchars($selectedTeamsAssignmentName); ?>
            </h2>

            <?php if (empty($teamAssignmentsRows)): ?>
                <p>No team assignments found for this assignment.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle status-table">
                        <thead>
                            <tr>
                                <th class="text-nowrap">Team #</th>
                                <th>Student</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamAssignmentsRows as $row): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <?php echo (int)$row['team_number']; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $fullName = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                                            echo htmlspecialchars($fullName) . ' (' . (int)$row['person_id'] . ')';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>



    </div>


            


    <!-- Bootstrap JS -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

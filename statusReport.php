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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Status Report - CS-451 Peer Review</title>
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
                <h1 class="app-title-main">
                    Status Report (<?php echo htmlspecialchars(date('Y-m-d')); ?>)
                </h1>
                <p class="app-subline">
                    You are logged in as <?php echo htmlspecialchars($userEmail); ?>
                    (id=<?php echo $loggedInUserId; ?>)
                </p>
            </div>
            <div class="app-actions">
                <?php if ($isAdmin): ?>
                    <a href="assignments.php" class="btn btn-outline-modern btn-sm">Assignments</a>
                <?php endif; ?>

                <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
            </div>

        </div>

        <?php if (empty($assignments)): ?>
            <p>No assignments have been created yet.</p>
        <?php else: ?>

            <?php if (!$isAdmin): ?>
                <!-- NON-ADMIN VIEW: your own summary -->
                <h2 class="status-section-title">Summary by Assignment</h2>

                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle status-table">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Date Assigned</th>
                                <th>Date Due</th>
                                <th>Reviews Written</th>
                                <th>Avg</th>
                                <th>Reviews Received</th>
                                <th>Avg</th>
                                <th>Reviews</th> <!-- NEW COLUMN -->
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <?php
                                $aid   = (int)$a['id'];
                                $gStats = $givenStatsByAssignment[$aid]    ?? ['cnt' => 0, 'avg' => null];
                                $rStats = $receivedStatsByAssignment[$aid] ?? ['cnt' => 0, 'avg' => null];

                                $gCnt = $gStats['cnt'];
                                $gAvg = $gStats['avg'];
                                $rCnt = $rStats['cnt'];
                                $rAvg = $rStats['avg'];

                                $gAvgText = ($gCnt > 0 && $gAvg !== null) ? number_format($gAvg, 1) : '-';
                                $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format($rAvg, 1) : '-';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['name']); ?></td>
                                <td><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                                <td><?php echo htmlspecialchars($a['date_due']); ?></td>
                                <td><?php echo $gCnt; ?></td>
                                <td><?php echo $gAvgText; ?></td>
                                <td><?php echo $rCnt; ?></td>
                                <td><?php echo $rAvgText; ?></td>
                                <td>
                                    <a
                                        href="reviews.php?assignment_id=<?php echo $aid; ?>"
                                        class="btn btn-outline-modern btn-sm"
                                    >
                                        Reviews
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- ADMIN VIEW: all students, collapsible by assignment -->
                <h2 class="status-section-title">Summary by Assignment (All Students)</h2>

                <div class="accordion status-accordion" id="assignmentAccordion">
                    <?php foreach ($assignments as $a): ?>
                        <?php
                            $aid       = (int)$a['id'];
                            $headingId = 'heading' . $aid;
                            $collapseId = 'collapse' . $aid;
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                                <button
                                    class="accordion-button collapsed"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#<?php echo $collapseId; ?>"
                                    aria-expanded="false"
                                    aria-controls="<?php echo $collapseId; ?>"
                                >
                                    <?php echo htmlspecialchars($a['name']); ?>
                                    <span class="date-pill">
                                        <?php echo htmlspecialchars($a['date_assigned']); ?>
                                        &rarr;
                                        <?php echo htmlspecialchars($a['date_due']); ?>
                                    </span>
                                </button>
                            </h2>
                            <div
                                id="<?php echo $collapseId; ?>"
                                class="accordion-collapse collapse"
                                aria-labelledby="<?php echo $headingId; ?>"
                                data-bs-parent="#assignmentAccordion"
                            >
                                <div class="accordion-body">
                                    <?php if (empty($students)): ?>
                                        <p>No students found.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle status-table">
                                                <thead>
                                                    <tr>
                                                        <th>Student</th>
                                                        <th>Email</th>
                                                        <th>Reviews Given</th>
                                                        <th>Avg Given</th>
                                                        <th>Reviews Received</th>
                                                        <th>Avg Received</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $stu): ?>
                                                        <?php
                                                            $sid = (int)$stu['id'];

                                                            $gStats = $statsGivenByAssignmentAndStudent[$aid][$sid]    ?? ['cnt' => 0, 'avg' => null];
                                                            $rStats = $statsReceivedByAssignmentAndStudent[$aid][$sid] ?? ['cnt' => 0, 'avg' => null];

                                                            $gCnt = $gStats['cnt'];
                                                            $gAvg = $gStats['avg'];
                                                            $rCnt = $rStats['cnt'];
                                                            $rAvg = $rStats['avg'];

                                                            $gAvgText = ($gCnt > 0 && $gAvg !== null) ? number_format($gAvg, 1) : '-';
                                                            $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format($rAvg, 1) : '-';

                                                            $fullName = $stu['lname'] . ', ' . $stu['fname'];
                                                        ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($fullName); ?></td>
                                                            <td><?php echo htmlspecialchars($stu['email']); ?></td>
                                                            <td><?php echo $gCnt; ?></td>
                                                            <td><?php echo $gAvgText; ?></td>
                                                            <td><?php echo $rCnt; ?></td>
                                                            <td><?php echo $rAvgText; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Bootstrap JS for accordion -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

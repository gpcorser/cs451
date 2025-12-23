<?php
session_start();
// require __DIR__ . '/config.php';
require '../database/config.php';

// If not logged in, send back to login
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
    <title>Temporary Page</title>
    <style>
        .collapsible-content {
            display: none; /* default collapsed */
            margin-top: 0.5em;
            margin-bottom: 1.5em;
        }
        .collapse-toggle {
            margin: 0.25em 0;
        }
    </style>
    <script>
        function toggleSection(id) {
            var el = document.getElementById(id);
            if (!el) return;
            if (el.style.display === 'none' || el.style.display === '') {
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <h1>Welcome!</h1>
    <p>
        You are logged in as
        <?php echo htmlspecialchars($userEmail); ?>
        (ID <?php echo $loggedInUserId; ?>)
    </p>

    <?php if ($isAdmin): ?>
        <p><strong>You are an admin user.</strong></p>
    <?php else: ?>
        <p>You are a regular user.</p>
    <?php endif; ?>

    <p>(This is temp.php — you can replace this later with your real app homepage.)</p>

    <!-- Button to go to assignments list -->
    <form action="assignments.php" method="get" style="display:inline;">
        <button type="submit">View Assignments</button>
    </form>

    <hr>

    <?php if (empty($assignments)): ?>
        <p>No assignments have been created yet.</p>
    <?php else: ?>

        <?php if (!$isAdmin): ?>
            <!-- NON-ADMIN VIEW: per-assignment stats for THIS user (given + received) -->
            <h2>Your Review Summary by Assignment</h2>

            <table border="1" cellpadding="6" cellspacing="0">
                <tr>
                    <th>Assignment</th>
                    <th>Date Assigned</th>
                    <th>Date Due</th>
                    <th>Reviews Given</th>
                    <th>Avg Given</th>
                    <th>Reviews Received</th>
                    <th>Avg Received</th>
                </tr>
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
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['name']); ?></td>
                        <td><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                        <td><?php echo htmlspecialchars($a['date_due']); ?></td>
                        <td><?php echo $gCnt; ?></td>
                        <td><?php echo $gAvgText; ?></td>
                        <td><?php echo $rCnt; ?></td>
                        <td><?php echo $rAvgText; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

        <?php else: ?>
            <!-- ADMIN VIEW: per-assignment, per-student stats (given + received), collapsible -->
            <h2>Review Summary by Assignment and Student</h2>

            <?php foreach ($assignments as $a): ?>
                <?php
                    $aid        = (int)$a['id'];
                    $sectionId  = 'assignment_section_' . $aid;
                ?>
                <h3>
                    Assignment: <?php echo htmlspecialchars($a['name']); ?>
                    (<?php echo htmlspecialchars($a['date_assigned']); ?> &rarr; <?php echo htmlspecialchars($a['date_due']); ?>)
                </h3>

                <button type="button"
                        class="collapse-toggle"
                        onclick="toggleSection('<?php echo $sectionId; ?>')">
                    Show/Hide Student Reviews
                </button>

                <?php if (empty($students)): ?>
                    <p>No students found.</p>
                <?php endif; ?>

                <div id="<?php echo $sectionId; ?>" class="collapsible-content">
                    <?php if (!empty($students)): ?>
                        <table border="1" cellpadding="6" cellspacing="0">
                            <tr>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Reviews Given</th>
                                <th>Avg Given</th>
                                <th>Reviews Received</th>
                                <th>Avg Received</th>
                            </tr>
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
                        </table>
                    <?php endif; ?>
                </div>

                <br>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

    <p><a href="login.php">Back to login</a></p>
</body>
</html>

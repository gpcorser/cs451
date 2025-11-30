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

// ---------- HANDLE ADMIN POST ACTIONS ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name         = trim($_POST['name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $dateAssigned = $_POST['date_assigned'] ?? '';
        $dateDue      = $_POST['date_due'] ?? '';

        if ($name === '' || $description === '' || $dateAssigned === '' || $dateDue === '') {
            $message = 'Please fill in all fields for the assignment.';
        } else {
            if ($action === 'create') {
                $stmt = $pdo->prepare('
                    INSERT INTO assignments (name, description, date_assigned, date_due)
                    VALUES (:name, :description, :date_assigned, :date_due)
                ');
                $stmt->execute([
                    ':name'          => $name,
                    ':description'   => $description,
                    ':date_assigned' => $dateAssigned,
                    ':date_due'      => $dateDue,
                ]);
                $message = 'Assignment created.';
            } else { // update
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE assignments
                        SET name = :name,
                            description = :description,
                            date_assigned = :date_assigned,
                            date_due = :date_due
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        ':name'          => $name,
                        ':description'   => $description,
                        ':date_assigned' => $dateAssigned,
                        ':date_due'      => $dateDue,
                        ':id'            => $id,
                    ]);
                    $message = 'Assignment updated.';
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
    SELECT id, name, description, date_assigned, date_due
    FROM assignments
    ORDER BY date_assigned ASC, id ASC
');
$assignments = $assignmentsStmt->fetchAll();

// ---------- LOAD EDITING ASSIGNMENT (IF ANY) ----------
$editingAssignment = null;
if ($isAdmin && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingAssignment = $stmt->fetch();
        if (!$editingAssignment) {
            $message = 'Assignment not found for editing.';
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

                        <div class="col-md-4">
                            <label for="description" class="form-label mb-1">Description</label>
                            <textarea
                                class="form-control form-control-sm"
                                id="description"
                                name="description"
                                rows="2"
                                required
                            ><?php echo htmlspecialchars($valDescription); ?></textarea>
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
                                    <strong><?php echo htmlspecialchars($a['name']); ?></strong><br>
                                    <small class="text-muted">
                                        <?php echo nl2br(htmlspecialchars($a['description'])); ?>
                                    </small>
                                </td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                                <td class="text-nowrap"><?php echo htmlspecialchars($a['date_due']); ?></td>
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
    </div>

    <!-- Bootstrap JS -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

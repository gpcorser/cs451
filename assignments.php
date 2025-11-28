<?php
session_start();
require __DIR__ . '/config.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$isAdmin = !empty($_SESSION['is_admin']);
$message = '';
$editingAssignment = null;

// ---------- HANDLE ADMIN ACTIONS (CREATE / UPDATE / DELETE) ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name          = trim($_POST['name'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $dateAssigned  = trim($_POST['date_assigned'] ?? '');
        $dateDue       = trim($_POST['date_due'] ?? '');
        $id            = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if ($name === '' || $description === '' || $dateAssigned === '' || $dateDue === '') {
            $message = 'Please fill in all fields.';
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
            } elseif ($action === 'update' && $id > 0) {
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
            }
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $message = 'Assignment deleted.';
        }
    }
}

// ---------- LOAD ASSIGNMENT TO EDIT (IF ADMIN + GET ?edit=id) ----------
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

// ---------- FETCH ALL ASSIGNMENTS FOR LIST ----------
$stmt = $pdo->query('SELECT * FROM assignments ORDER BY date_assigned ASC, id ASC');
$assignments = $stmt->fetchAll();

// ---------- FETCH REVIEW STATS FOR THIS USER (WRITTEN / RECEIVED) ----------

// Reviews WRITTEN by logged-in user, grouped by assignment
$writtenStats = [];
$wStmt = $pdo->prepare('
    SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
    FROM reviews
    WHERE reviewer_id = :uid
    GROUP BY assignment_id
');
$wStmt->execute([':uid' => $loggedInUserId]);
foreach ($wStmt->fetchAll() as $row) {
    $aid = (int)$row['assignment_id'];
    $writtenStats[$aid] = [
        'cnt' => (int)$row['cnt'],
        'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
    ];
}

// Reviews RECEIVED by logged-in user, grouped by assignment
$receivedStats = [];
$rStmt = $pdo->prepare('
    SELECT assignment_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
    FROM reviews
    WHERE student_id = :uid
    GROUP BY assignment_id
');
$rStmt->execute([':uid' => $loggedInUserId]);
foreach ($rStmt->fetchAll() as $row) {
    $aid = (int)$row['assignment_id'];
    $receivedStats[$aid] = [
        'cnt' => (int)$row['cnt'],
        'avg' => $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assignments</title>
    <style> .nowrap { white-space: nowrap; } </style>

</head>
<body>

    <p>
        Logged in as <?php echo htmlspecialchars($_SESSION['user_email']); ?>
        (id=<?php echo (int)$_SESSION['user_id']; ?>)
    </p>

    <h1>Assignments</h1>

    <p><a href="temp.php">Back to home</a></p>

    <?php if ($message !== ''): ?>
        <p style="color: green;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <?php
        // Decide if we are in "create" or "edit" mode
        $formMode = $editingAssignment ? 'update' : 'create';
        $formTitle = $editingAssignment ? 'Edit Assignment' : 'Create New Assignment';

        $valName         = $editingAssignment['name']          ?? '';
        $valDescription  = $editingAssignment['description']   ?? '';
        $valDateAssigned = $editingAssignment['date_assigned'] ?? '';
        $valDateDue      = $editingAssignment['date_due']      ?? '';
        $valId           = $editingAssignment['id']            ?? 0;
        ?>
        <h2><?php echo htmlspecialchars($formTitle); ?></h2>

        <form method="post" action="assignments.php<?php echo $editingAssignment ? '?edit=' . (int)$valId : ''; ?>">
            <input type="hidden" name="action" value="<?php echo htmlspecialchars($formMode); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$valId; ?>">

            <label>
                Name:<br>
                <input type="text" name="name" value="<?php echo htmlspecialchars($valName); ?>" required style="width: 300px;">
            </label>
            <br><br>

            <label>
                Description:<br>
                <textarea name="description" rows="4" cols="60" required><?php
                    echo htmlspecialchars($valDescription);
                ?></textarea>
            </label>
            <br><br>

            <label>
                Date Assigned (YYYY-MM-DD):<br>
                <input type="date" name="date_assigned" value="<?php echo htmlspecialchars($valDateAssigned); ?>" required>
            </label>
            <br><br>

            <label>
                Date Due (YYYY-MM-DD):<br>
                <input type="date" name="date_due" value="<?php echo htmlspecialchars($valDateDue); ?>" required>
            </label>
            <br><br>

            <button type="submit">
                <?php echo $editingAssignment ? 'Update Assignment' : 'Create Assignment'; ?>
            </button>

            <?php if ($editingAssignment): ?>
                <a href="assignments.php" style="margin-left: 10px;">Cancel Edit</a>
            <?php endif; ?>
        </form>

        <hr>
    <?php endif; ?>

    <h2>Assignment List</h2>

    <?php if (empty($assignments)): ?>
        <p>No assignments posted yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="6" cellspacing="0">
            <tr>
                <th>Name</th>
                <th>Date Assigned</th>
                <th>Date Due</th>
                <th>Description</th>
                <th>Reviews</th>
                <?php if ($isAdmin): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>

            <?php foreach ($assignments as $a): ?>
                <?php
                    $aid = (int)$a['id'];

                    // written stats
                    $wCnt = $writtenStats[$aid]['cnt'] ?? 0;
                    $wAvg = $writtenStats[$aid]['avg'] ?? null;

                    // received stats
                    $rCnt = $receivedStats[$aid]['cnt'] ?? 0;
                    $rAvg = $receivedStats[$aid]['avg'] ?? null;

                    $wAvgText = ($wCnt > 0 && $wAvg !== null) ? number_format($wAvg, 1) : '-';
                    $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format($rAvg, 1) : '-';
                ?>
                <tr>
                    <td class="nowrap"><?php echo htmlspecialchars($a['name']); ?></td>
                    <td class="nowrap"><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                    <td class="nowrap"><?php echo htmlspecialchars($a['date_due']); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($a['description'])); ?></td>

                    <td>
                        <a href="reviews.php?assignment_id=<?php echo $aid; ?>">Reviews</a>
                        <?php
                            echo 'written:' . $wCnt . '(' . $wAvgText . '), received:' . $rCnt . '(' . $rAvgText . ')';
                        ?>
                    </td>

                    <?php if ($isAdmin): ?>
                        <td>
                            <a href="assignments.php?edit=<?php echo $aid; ?>">Edit</a>

                            <form action="assignments.php" method="post" style="display:inline;"
                                  onsubmit="return confirm('Delete this assignment?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $aid; ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

</body>
</html>

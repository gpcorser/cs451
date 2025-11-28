<?php
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$isAdmin        = !empty($_SESSION['is_admin']);
$message        = '';

$assignmentFilter = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

// ---------- HELPER: CAN THIS USER EDIT THIS REVIEW? ----------
function can_edit_review(array $review, int $userId, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    // Non-admin can edit only reviews they wrote AND not finalized
    if ((int)$review['reviewer_id'] === $userId && $review['date_finalized'] === null) {
        return true;
    }
    return false;
}

// ---------- LOAD ASSIGNMENTS (FOR DROPDOWN) ----------
$assignments = $pdo->query('SELECT id, name FROM assignments ORDER BY date_assigned ASC, id ASC')->fetchAll();

// ---------- LOAD STUDENTS (FOR DROPDOWN) ----------
$personsStmt = $pdo->query('SELECT id, fname, lname, email FROM persons ORDER BY lname, fname');
$persons     = $personsStmt->fetchAll();

// ---------- HANDLE POST ACTIONS (CREATE / UPDATE / DELETE / FINALIZE) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $studentId     = (int)($_POST['student_id'] ?? 0);
        $assignmentId  = (int)($_POST['assignment_id'] ?? 0);
        $rating        = (int)($_POST['rating'] ?? -1);
        $comments      = trim($_POST['comments'] ?? '');

        if ($studentId <= 0 || $assignmentId <= 0 || $comments === '' || $rating < 0 || $rating > 10) {
            $message = 'Please fill in all fields correctly. Rating must be 0–10.';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO reviews (reviewer_id, student_id, assignment_id, rating, comments)
                VALUES (:reviewer_id, :student_id, :assignment_id, :rating, :comments)
            ');
            $stmt->execute([
                ':reviewer_id'  => $loggedInUserId,
                ':student_id'   => $studentId,
                ':assignment_id'=> $assignmentId,
                ':rating'       => $rating,
                ':comments'     => $comments,
            ]);
            $message = 'Review created.';
        }

    } elseif (in_array($action, ['update', 'delete', 'finalize'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $message = 'Invalid review ID.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $review = $stmt->fetch();

            if (!$review) {
                $message = 'Review not found.';
            } else {
                if ($action === 'update') {
                    if (!can_edit_review($review, $loggedInUserId, $isAdmin)) {
                        $message = 'You do not have permission to edit this review.';
                    } else {
                        $studentId     = (int)($_POST['student_id'] ?? 0);
                        $assignmentId  = (int)($_POST['assignment_id'] ?? 0);
                        $rating        = (int)($_POST['rating'] ?? -1);
                        $comments      = trim($_POST['comments'] ?? '');

                        if ($studentId <= 0 || $assignmentId <= 0 || $comments === '' || $rating < 0 || $rating > 10) {
                            $message = 'Please fill in all fields correctly. Rating must be 0–10.';
                        } else {
                            $u = $pdo->prepare('
                                UPDATE reviews
                                SET student_id = :student_id,
                                    assignment_id = :assignment_id,
                                    rating = :rating,
                                    comments = :comments
                                WHERE id = :id
                            ');
                            $u->execute([
                                ':student_id'   => $studentId,
                                ':assignment_id'=> $assignmentId,
                                ':rating'       => $rating,
                                ':comments'     => $comments,
                                ':id'           => $id,
                            ]);
                            $message = 'Review updated.';
                        }
                    }

                } elseif ($action === 'delete') {
                    if (!can_edit_review($review, $loggedInUserId, $isAdmin)) {
                        $message = 'You do not have permission to delete this review.';
                    } else {
                        $d = $pdo->prepare('DELETE FROM reviews WHERE id = :id');
                        $d->execute([':id' => $id]);
                        $message = 'Review deleted.';
                    }

                } elseif ($action === 'finalize') {
                    // Non-admin: may only finalize their own un-finalized reviews
                    $canFinalize = $isAdmin
                        || ((int)$review['reviewer_id'] === $loggedInUserId && $review['date_finalized'] === null);

                    if (!$canFinalize) {
                        $message = 'You do not have permission to finalize this review.';
                    } else {
                        $f = $pdo->prepare('
                            UPDATE reviews
                            SET date_finalized = NOW()
                            WHERE id = :id
                        ');
                        $f->execute([':id' => $id]);
                        $message = 'Review finalized.';
                    }
                }
            }
        }
    }
}

// ---------- LOAD REVIEW TO EDIT (IF ANY) ----------
$editingReview = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM reviews WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingReview = $stmt->fetch();

        if (!$editingReview) {
            $message = 'Review not found for editing.';
        } elseif (!can_edit_review($editingReview, $loggedInUserId, $isAdmin)) {
            $message = 'You do not have permission to edit this review.';
            $editingReview = null;
        }
    }
}

// ---------- FETCH REVIEWS FOR DISPLAY ----------

// Admins see all (optionally filtered by assignment)
if ($isAdmin) {
    if ($assignmentFilter > 0) {
        $sql = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            WHERE r.assignment_id = :aid
            ORDER BY a.date_assigned, r.id
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':aid' => $assignmentFilter]);
    } else {
        $sql = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            ORDER BY a.date_assigned, r.id
        ';
        $stmt = $pdo->query($sql);
    }
    $allReviews = $stmt->fetchAll();
} else {
    // Non-admin: separate lists
    // Reviews written by me
    if ($assignmentFilter > 0) {
        $sqlMy = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            WHERE r.reviewer_id = :uid AND r.assignment_id = :aid
            ORDER BY a.date_assigned, r.id
        ';
        $stmtMy = $pdo->prepare($sqlMy);
        $stmtMy->execute([':uid' => $loggedInUserId, ':aid' => $assignmentFilter]);

        $sqlAbout = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            WHERE r.student_id = :uid AND r.assignment_id = :aid
            ORDER BY a.date_assigned, r.id
        ';
        $stmtAbout = $pdo->prepare($sqlAbout);
        $stmtAbout->execute([':uid' => $loggedInUserId, ':aid' => $assignmentFilter]);

    } else {
        $sqlMy = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            WHERE r.reviewer_id = :uid
            ORDER BY a.date_assigned, r.id
        ';
        $stmtMy = $pdo->prepare($sqlMy);
        $stmtMy->execute([':uid' => $loggedInUserId]);

        $sqlAbout = '
            SELECT r.*, 
                   a.name AS assignment_name,
                   rev.fname AS reviewer_fname, rev.lname AS reviewer_lname,
                   stu.fname AS student_fname, stu.lname AS student_lname
            FROM reviews r
            JOIN assignments a ON r.assignment_id = a.id
            JOIN persons rev ON r.reviewer_id = rev.id
            JOIN persons stu ON r.student_id = stu.id
            WHERE r.student_id = :uid
            ORDER BY a.date_assigned, r.id
        ';
        $stmtAbout = $pdo->prepare($sqlAbout);
        $stmtAbout->execute([':uid' => $loggedInUserId]);
    }

    $myReviews     = $stmtMy->fetchAll();
    $reviewsAbout  = $stmtAbout->fetchAll();
}

// ---------- VALUES FOR FORM (CREATE OR EDIT) ----------
$formMode  = $editingReview ? 'update' : 'create';
$formTitle = $editingReview ? 'Edit Review' : 'Create New Review';

$valId           = $editingReview['id']            ?? 0;
$valStudentId    = $editingReview['student_id']    ?? 0;
$valAssignmentId = $editingReview['assignment_id'] ?? ($assignmentFilter > 0 ? $assignmentFilter : 0);
$valRating       = $editingReview['rating']        ?? '';
$valComments     = $editingReview['comments']      ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Peer Reviews</title>
</head>
<body>

    <p>
        Logged in as <?php echo htmlspecialchars($_SESSION['user_email']); ?>
        (id=<?php echo (int)$_SESSION['user_id']; ?>)
    </p>
    
    <h1>Peer Reviews</h1>

    <p>
        <a href="assignments.php">Back to Assignments</a> |
        <a href="temp.php">Home</a>
    </p>

    <?php if ($assignmentFilter > 0): ?>
        <p>Filtering by assignment ID: <?php echo (int)$assignmentFilter; ?></p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <p style="color: green;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <h2><?php echo htmlspecialchars($formTitle); ?></h2>

    <form method="post" action="reviews.php<?php
        $qs = [];
        if ($assignmentFilter > 0) {
            $qs[] = 'assignment_id=' . $assignmentFilter;
        }
        if ($editingReview) {
            $qs[] = 'edit=' . (int)$valId;
        }
        echo $qs ? '?' . implode('&', $qs) : '';
    ?>">
        <input type="hidden" name="action" value="<?php echo htmlspecialchars($formMode); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$valId; ?>">

        <p><strong>Reviewer:</strong> (you) — ID <?php echo $loggedInUserId; ?></p>

        <label>
            Student being reviewed:<br>
            <select name="student_id" required>
                <option value="">-- Select student --</option>
                <?php foreach ($persons as $p): ?>
                    <?php
                    $pid = (int)$p['id'];
                    $fullName = $p['lname'] . ', ' . $p['fname'] . ' (' . $p['email'] . ')';
                    ?>
                    <option value="<?php echo $pid; ?>"
                        <?php if ($pid === (int)$valStudentId) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($fullName); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <br><br>

        <label>
            Assignment:<br>
            <select name="assignment_id" required>
                <option value="">-- Select assignment --</option>
                <?php foreach ($assignments as $a): ?>
                    <?php $aid = (int)$a['id']; ?>
                    <option value="<?php echo $aid; ?>"
                        <?php if ($aid === (int)$valAssignmentId) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($a['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <br><br>

        <label>
            Rating (0–10):<br>
            <input type="number" name="rating" min="0" max="10"
                   value="<?php echo htmlspecialchars($valRating); ?>" required>
        </label>
        <br><br>

        <label>
            Comments:<br>
            <textarea name="comments" rows="4" cols="60" required><?php
                echo htmlspecialchars($valComments);
            ?></textarea>
        </label>
        <br><br>

        <button type="submit">
            <?php echo $editingReview ? 'Update Review' : 'Create Review'; ?>
        </button>

        <?php if ($editingReview): ?>
            <a href="reviews.php<?php echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : ''; ?>"
               style="margin-left: 10px;">Cancel Edit</a>
        <?php endif; ?>
    </form>

    <hr>

    <?php if ($isAdmin): ?>
        <h2>All Reviews<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?></h2>

        <?php if (empty($allReviews)): ?>
            <p>No reviews found.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <tr>
                    <th>Assignment</th>
                    <th>Reviewer</th>
                    <th>Student</th>
                    <th>Rating</th>
                    <th>Comments</th>
                    <th>Last Edited</th>
                    <th>Finalized</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($allReviews as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['assignment_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['reviewer_lname'] . ', ' . $r['reviewer_fname']); ?></td>
                        <td><?php echo htmlspecialchars($r['student_lname'] . ', ' . $r['student_fname']); ?></td>
                        <td><?php echo (int)$r['rating']; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($r['comments'])); ?></td>
                        <td><?php echo htmlspecialchars($r['date_last_edited']); ?></td>
                        <td><?php echo htmlspecialchars($r['date_finalized'] ?? ''); ?></td>
                        <td>
                            <a href="reviews.php?edit=<?php echo (int)$r['id']; ?><?php
                                if ($assignmentFilter > 0) echo '&assignment_id=' . $assignmentFilter;
                            ?>">Edit</a>

                            <?php if ($r['date_finalized'] === null): ?>
                                <form method="post" action="reviews.php<?php
                                    echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="finalize">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit">Finalize</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="reviews.php<?php
                                echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                            ?>" style="display:inline;" onsubmit="return confirm('Delete this review?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php else: ?>
        <h2>Reviews You Have Written<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?></h2>

        <?php
        // stats for written
        $myCount = count($myReviews);
        $myAvg = $myCount > 0
            ? number_format(array_sum(array_column($myReviews, 'rating')) / $myCount, 1)
            : '-';
        ?>
        <p><strong>Total written:</strong> <?php echo $myCount; ?> (avg <?php echo $myAvg; ?>)</p>

        <?php if (empty($myReviews)): ?>
            <p>You have not written any reviews yet.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <tr>
                    <th>Assignment</th>
                    <th>Student</th>
                    <th>Rating</th>
                    <th>Comments</th>
                    <th>Last Edited</th>
                    <th>Finalized</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($myReviews as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['assignment_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['student_lname'] . ', ' . $r['student_fname']); ?></td>
                        <td><?php echo (int)$r['rating']; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($r['comments'])); ?></td>
                        <td><?php echo htmlspecialchars($r['date_last_edited']); ?></td>
                        <td><?php echo htmlspecialchars($r['date_finalized'] ?? ''); ?></td>
                        <td>
                            <?php if (can_edit_review($r, $loggedInUserId, $isAdmin)): ?>
                                <a href="reviews.php?edit=<?php echo (int)$r['id']; ?><?php
                                    if ($assignmentFilter > 0) echo '&assignment_id=' . $assignmentFilter;
                                ?>">Edit</a>

                                <?php if ($r['date_finalized'] === null): ?>
                                    <form method="post" action="reviews.php<?php
                                        echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                    ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="finalize">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit">Finalize</button>
                                    </form>

                                    <form method="post" action="reviews.php<?php
                                        echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                    ?>" style="display:inline;" onsubmit="return confirm('Delete this review?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                (no actions)
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h2>Reviews About You<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?></h2>

        <?php
        // stats for received
        $aboutCount = count($reviewsAbout);
        $aboutAvg = $aboutCount > 0
            ? number_format(array_sum(array_column($reviewsAbout, 'rating')) / $aboutCount, 1)
            : '-';
        ?>
        <p><strong>Total received:</strong> <?php echo $aboutCount; ?> (avg <?php echo $aboutAvg; ?>)</p>

        <?php if (empty($reviewsAbout)): ?>
            <p>No one has reviewed you yet.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <tr>
                    <th>Assignment</th>
                    <th>Reviewer</th>
                    <th>Rating</th>
                    <th>Comments</th>
                    <th>Last Edited</th>
                    <th>Finalized</th>
                </tr>
                <?php foreach ($reviewsAbout as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['assignment_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['reviewer_lname'] . ', ' . $r['reviewer_fname']); ?></td>
                        <td><?php echo (int)$r['rating']; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($r['comments'])); ?></td>
                        <td><?php echo htmlspecialchars($r['date_last_edited']); ?></td>
                        <td><?php echo htmlspecialchars($r['date_finalized'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>

<?php
// reviews_core.php
// Assumes: session_start() and require 'config.php' already done.
// Populates variables for reviews.php template.

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$userEmail      = $_SESSION['user_email'] ?? 'unknown';
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
$assignments = $pdo->query('
    SELECT id, name
    FROM assignments
    ORDER BY date_assigned ASC, id ASC
')->fetchAll();

// ---------- LOAD STUDENTS (FOR DROPDOWN) ----------
$personsStmt = $pdo->query('
    SELECT id, fname, lname, email
    FROM persons
    ORDER BY lname, fname
');
$persons = $personsStmt->fetchAll();

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
                ':reviewer_id'   => $loggedInUserId,
                ':student_id'    => $studentId,
                ':assignment_id' => $assignmentId,
                ':rating'        => $rating,
                ':comments'      => $comments,
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
$allReviews    = [];
$myReviews     = [];
$reviewsAbout  = [];

if ($isAdmin) {
    // Admins see all (optionally filtered by assignment)
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

    $myReviews    = $stmtMy->fetchAll();
    $reviewsAbout = $stmtAbout->fetchAll();
}

// ---------- VALUES FOR FORM (CREATE OR EDIT) ----------
$formMode  = $editingReview ? 'update' : 'create';
$formTitle = $editingReview ? 'Edit Review' : 'Create New Review';

$valId           = $editingReview['id']            ?? 0;
$valStudentId    = $editingReview['student_id']    ?? 0;
$valAssignmentId = $editingReview['assignment_id'] ?? ($assignmentFilter > 0 ? $assignmentFilter : 0);
$valRating       = $editingReview['rating']        ?? '';
$valComments     = $editingReview['comments']      ?? '';

// ---------- SUMMARY FOR NON-ADMINS ----------
$mySummaryCount     = 0;
$mySummaryAvg       = null;
$aboutSummaryCount  = 0;
$aboutSummaryAvg    = null;

if (!$isAdmin) {
    // Written
    $mySummaryCount = count($myReviews);
    $sum = 0;
    $cnt = 0;
    foreach ($myReviews as $r) {
        if ($r['rating'] !== null) {
            $sum += (int)$r['rating'];
            $cnt++;
        }
    }
    if ($cnt > 0) {
        $mySummaryAvg = $sum / $cnt;
    }

    // Received
    $aboutSummaryCount = count($reviewsAbout);
    $sum = 0;
    $cnt = 0;
    foreach ($reviewsAbout as $r) {
        if ($r['rating'] !== null) {
            $sum += (int)$r['rating'];
            $cnt++;
        }
    }
    if ($cnt > 0) {
        $aboutSummaryAvg = $sum / $cnt;
    }
}

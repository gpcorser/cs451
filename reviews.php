<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/reviews_core.php';
require __DIR__ . '/upload_helpers.php';

/*
 * ------------------------------------------------------------
 * DUPLICATE REVIEW GUARD (no schema change)
 * ------------------------------------------------------------
 * Requirement:
 * - A student should create only ONE review per (assignment, peer).
 * - If they attempt to submit another "new" review (id=0),
 *   show an error message and prevent persistence.
 *
 * Constraint:
 * - CRUD is implemented inside reviews_core.php (already executed).
 * - So we detect duplicates AFTER the core runs and rollback by deleting
 *   the newest row if it created a duplicate.
 *
 * Notes:
 * - This only triggers for non-admins, only for "create" (id=0).
 * - Editing an existing review (id>0) is allowed.
 */

try {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && !$isAdmin
        && isset($_POST['student_id'], $_POST['assignment_id'])
    ) {
        $postedId         = (int)($_POST['id'] ?? 0);
        $postedStudentId  = (int)($_POST['student_id'] ?? 0);
        $postedAssignId   = (int)($_POST['assignment_id'] ?? 0);
        $currentReviewer  = (int)($_SESSION['user_id'] ?? 0);

        // Only enforce on "create new" attempts
        if ($postedId === 0 && $postedStudentId > 0 && $postedAssignId > 0 && $currentReviewer > 0) {

            // Count matching reviews (if > 1, the last submission created a duplicate)
            $stmt = $pdo->prepare("
                SELECT id
                FROM reviews
                WHERE assignment_id = :aid
                  AND reviewer_id   = :rid
                  AND student_id    = :sid
                ORDER BY id DESC
            ");
            $stmt->execute([
                ':aid' => $postedAssignId,
                ':rid' => $currentReviewer,
                ':sid' => $postedStudentId,
            ]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            if (count($ids) > 1) {
                // Delete the newest duplicate (highest id)
                $deleteId = (int)$ids[0];
                $del = $pdo->prepare("DELETE FROM reviews WHERE id = :id LIMIT 1");
                $del->execute([':id' => $deleteId]);

                // Override message from reviews_core.php
                $message = 'Duplicate review blocked: you already submitted a review for this peer for this assignment. '
                         . 'Please edit your existing review instead.';
            }
        }
    }
} catch (Throwable $e) {
    // Fail closed in a user-friendly way; do not expose internals.
    $message = 'A system error occurred while checking for duplicate reviews. Please try again.';
}

# Load assignment name

$assignmentFilterName = '';

if ($assignmentFilter > 0) {
    $stmtAF = $pdo->prepare('SELECT name FROM assignments WHERE id = :id');
    $stmtAF->execute([':id' => $assignmentFilter]);
    $rowAF = $stmtAF->fetch(PDO::FETCH_ASSOC);
    if ($rowAF) {
        $assignmentFilterName = $rowAF['name'];
    }
}

// -------------------------- FILES: INSTRUCTIONS + TEAM SUBMISSIONS --------------------------

$instructionPdfs = [];
$teamMembers = [];
$submissionsByPerson = []; // [person_id] => list of submission rows

if ($assignmentFilter > 0) {
    // Instruction PDFs (teacher uploads)
    $stmtPdf = $pdo->prepare('SELECT id, file_index, original_name, uploaded_at FROM assignment_files WHERE assignment_id = :aid ORDER BY file_index ASC');
    $stmtPdf->execute([':aid' => $assignmentFilter]);
    $instructionPdfs = $stmtPdf->fetchAll(PDO::FETCH_ASSOC);

    // Team members for this assignment (includes self)
    $stmtT = $pdo->prepare('SELECT team_number FROM teamassignments WHERE assignment_id = :aid AND person_id = :pid LIMIT 1');
    $stmtT->execute([':aid' => $assignmentFilter, ':pid' => $loggedInUserId]);
    $teamNumber = (int)($stmtT->fetchColumn() ?: 0);

    if ($teamNumber > 0) {
        $stmtM = $pdo->prepare('
            SELECT p.id, p.fname, p.lname, p.email
            FROM teamassignments ta
            JOIN persons p ON p.id = ta.person_id
            WHERE ta.assignment_id = :aid AND ta.team_number = :tn
            ORDER BY p.lname ASC, p.fname ASC
        ');
        $stmtM->execute([':aid' => $assignmentFilter, ':tn' => $teamNumber]);
        $teamMembers = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($teamMembers)) {
            $ids = array_map(fn($r) => (int)$r['id'], $teamMembers);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmtS = $pdo->prepare(
                'SELECT id, assignment_id, person_id, file_index, original_name, uploaded_at '
              . 'FROM teamassignment_files '
              . 'WHERE assignment_id = ? AND person_id IN (' . $in . ') '
              . 'ORDER BY person_id ASC, file_index ASC'
            );
            $stmtS->execute(array_merge([$assignmentFilter], $ids));
            foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int)$r['person_id'];
                $submissionsByPerson[$pid] ??= [];
                $submissionsByPerson[$pid][] = $r;
            }
        }
    }
}


/**
 * Build the list of people the current user is allowed to review
 * for the currently filtered assignment.
 *
 * - Admins: can see everyone in $persons (unchanged behavior)
 * - Non-admins with assignmentFilter > 0: only teammates for that assignment
 * - Fallback: $persons
 */
$reviewablePersons = $persons; // default: full list

if (!$isAdmin && $assignmentFilter > 0) {
    // 1. Find this user's team_number for the current assignment
    $stmt = $pdo->prepare('
        SELECT team_number
        FROM teamassignments
        WHERE assignment_id = :aid
          AND person_id    = :pid
        LIMIT 1
    ');
    $stmt->execute([
        ':aid' => $assignmentFilter,
        ':pid' => $loggedInUserId,
    ]);
    $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($teamRow) {
        $teamNumber = (int)$teamRow['team_number'];

        // 2. Get all persons on that team for this assignment
        $stmt2 = $pdo->prepare('
            SELECT p.id, p.fname, p.lname, p.email
            FROM teamassignments AS ta
            JOIN persons AS p ON ta.person_id = p.id
            WHERE ta.assignment_id = :aid
              AND ta.team_number   = :tn
            ORDER BY p.lname ASC, p.fname ASC
        ');
        $stmt2->execute([
            ':aid' => $assignmentFilter,
            ':tn'  => $teamNumber,
        ]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // 3. Remove the current user from the list (no self-review)
        $reviewablePersons = [];
        foreach ($rows as $r) {
            if ((int)$r['id'] !== $loggedInUserId) {
                $reviewablePersons[] = $r;
            }
        }
    } else {
        // No team assignment found for this user in this assignment
        // -> empty list so they can't review anyone
        $reviewablePersons = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reviews (Evals) - CS-451 Peer Eval App</title>
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
                <h1 class="app-title-main">Ratings for:
                    <?php if ($assignmentFilter > 0): ?>
                        <strong><?php echo htmlspecialchars($assignmentFilterName); ?></strong>
                        (id=<?php echo (int)$assignmentFilter; ?>)
                    <?php endif; ?>
                </h1>
                <p class="app-subline">
                    You are logged in as <?php echo htmlspecialchars($userEmail); ?>
                    (id=<?php echo $loggedInUserId; ?>)
                    <?php if ($isAdmin): ?>
                        — <strong>Admin</strong>
                    <?php endif; ?>
                </p>
            </div>
            <div class="app-actions">
                <?php if ($isAdmin): ?>
                    <a href="assignments.php" class="btn btn-outline-modern btn-sm">Assignments</a>
                <?php endif; ?>
                <a href="statusReport.php" class="btn btn-outline-modern btn-sm">Status Report</a>
                <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info alert-modern mb-3" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($assignmentFilter > 0 && !$isAdmin): ?>
            <div class="row g-3 mb-4">
                <div class="col-lg-5">
                    <div class="form-box-peach">
                        <div class="fw-semibold mb-2">Assignment Instructions (PDF)</div>
                        <?php if (empty($instructionPdfs)): ?>
                            <div class="small text-muted">No PDFs uploaded yet.</div>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($instructionPdfs as $pf): ?>
                                    <a class="btn btn-outline-modern btn-sm"
                                       href="download.php?type=assignment&id=<?php echo (int)$pf['id']; ?>">
                                        PDF <?php echo (int)$pf['file_index']; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="form-box-peach">
                        <div class="fw-semibold mb-2">Team Submissions (ZIP)</div>
                        <?php if (empty($teamMembers)): ?>
                            <div class="small text-muted">You are not assigned to a team for this assignment (or no team members found).</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle status-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>ZIP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teamMembers as $tm): ?>
                                            <?php
                                                $pid = (int)$tm['id'];
                                                $files = $submissionsByPerson[$pid] ?? [];
                                                $name = $tm['lname'] . ', ' . $tm['fname'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($name); ?></td>
                                                <td>
                                                    <?php if (empty($files)): ?>
                                                        <span class="small text-muted">(none)</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php foreach ($files as $sf): ?>
                                                                <a class="btn btn-outline-modern btn-sm"
                                                                   href="download.php?type=submission&id=<?php echo (int)$sf['id']; ?>">
                                                                    ZIP <?php echo (int)$sf['file_index']; ?>
                                                                </a>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="small text-muted mt-2">Upload your ZIP from the Status Report screen (Actions → Upload ZIP).</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Review Form Box -->
        <fieldset class="form-box-peach mb-4">
            <legend class="form-box-legend">
                <?php echo htmlspecialchars($formTitle); ?>
            </legend>

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

                <p class="helper-text mb-2">
                    Reviewer: <strong><?php echo htmlspecialchars($userEmail); ?></strong>
                    (ID <?php echo $loggedInUserId; ?>)
                </p>

                <div class="row g-2 align-items-start">
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="student_id">Student being reviewed</label>
                        <select name="student_id" id="student_id" class="form-control form-control-sm" required>
                            <option value="">-- Select student --</option>
                            <?php foreach ($reviewablePersons as $p): ?>
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
                        <?php if (!$isAdmin && $assignmentFilter > 0 && empty($reviewablePersons)): ?>
                            <div class="form-text small text-danger">
                                You are not currently assigned to a team for this assignment, so no students are available to review.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label mb-1" for="assignment_id">Assignment</label>
                        <select name="assignment_id" id="assignment_id" class="form-control form-control-sm" required>
                            <option value="">-- Select assignment --</option>
                            <?php foreach ($assignments as $a): ?>
                                <?php $aid = (int)$a['id']; ?>
                                <option value="<?php echo $aid; ?>"
                                    <?php if ($aid === (int)$valAssignmentId) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($a['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1" for="rating">Rating (0–10)</label>
                        <input
                            type="number"
                            class="form-control form-control-sm"
                            id="rating"
                            name="rating"
                            min="0"
                            max="10"
                            value="<?php echo htmlspecialchars($valRating); ?>"
                            required
                        >
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-modern btn-sm w-100">
                            <?php echo $editingReview ? 'Update Review' : 'Submit'; ?>
                        </button>
                    </div>
                </div>

                <div class="mt-2">
                    <label class="form-label mb-1" for="comments">Comments</label>
                    <textarea
                        name="comments"
                        id="comments"
                        class="form-control form-control-sm"
                        rows="3"
                        required
                    ><?php echo htmlspecialchars($valComments); ?></textarea>
                </div>

                <?php if ($editingReview): ?>
                    <div class="mt-2">
                        <a href="reviews.php<?php echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : ''; ?>"
                           class="btn btn-outline-modern btn-sm">
                            Cancel Edit
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </fieldset>

        <?php if ($isAdmin): ?>
            <h2 class="status-section-title">
                All Reviews<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?>
            </h2>

            <?php if (empty($allReviews)): ?>
                <p>No reviews found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle status-table">
                        <thead>
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
                        </thead>
                        <tbody>
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
                                    ?>" class="btn btn-outline-modern btn-sm mb-1">
                                        Edit
                                    </a>

                                    <?php if ($r['date_finalized'] === null): ?>
                                        <form method="post"
                                              action="reviews.php<?php
                                                  echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                              ?>"
                                              class="d-inline">
                                            <input type="hidden" name="action" value="finalize">
                                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                            <button type="submit" class="btn btn-outline-modern btn-sm mb-1">
                                                Finalize
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post"
                                          action="reviews.php<?php
                                              echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                          ?>"
                                          class="d-inline"
                                          onsubmit="return confirm('Delete this review?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="btn btn-outline-modern btn-sm">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <h2 class="status-section-title">
                Reviews Written<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?>
            </h2>

            <p class="helper-text">
                Total written: <?php echo $mySummaryCount; ?>,
                average rating given:
                <?php echo $mySummaryAvg !== null ? number_format($mySummaryAvg, 1) : '-'; ?>
            </p>

            <?php if (empty($myReviews)): ?>
                <p>You have not written any reviews yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle status-table">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Student</th>
                                <th>Rating</th>
                                <th>Comments</th>
                                <th>Last Edited</th>
                                <th>Finalized</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
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
                                        ?>" class="btn btn-outline-modern btn-sm mb-1">
                                            Edit
                                        </a>

                                        <?php if ($r['date_finalized'] === null): ?>
                                            <form method="post"
                                                  action="reviews.php<?php
                                                      echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                                  ?>"
                                                  class="d-inline">
                                                <input type="hidden" name="action" value="finalize">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="btn btn-outline-modern btn-sm mb-1">
                                                    Finalize
                                                </button>
                                            </form>

                                            <form method="post"
                                                  action="reviews.php<?php
                                                      echo $assignmentFilter > 0 ? '?assignment_id=' . $assignmentFilter : '';
                                                  ?>"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Delete this review?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="btn btn-outline-modern btn-sm">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">(no actions)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h2 class="status-section-title">
                Reviews Received<?php echo $assignmentFilter > 0 ? ' (filtered)' : ''; ?>
            </h2>

            <p class="helper-text">
                Total received: <?php echo $aboutSummaryCount; ?>,
                average rating received:
                <?php echo $aboutSummaryAvg !== null ? number_format($aboutSummaryAvg, 1) : '-'; ?>
            </p>

            <?php if (empty($reviewsAbout)): ?>
                <p>No one has reviewed you yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle status-table">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Reviewer</th>
                                <th>Rating</th>
                                <th>Comments</th>
                                <th>Last Edited</th>
                                <th>Finalized</th>
                            </tr>
                        </thead>
                        <tbody>
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

<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/reviews_core.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Peer Reviews - CS-451 Peer Review</title>

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
                <h1 class="app-title-main">Peer Reviews</h1>
                <p class="app-subline">
                    You are logged in as <?php echo htmlspecialchars($userEmail); ?>
                    (id=<?php echo $loggedInUserId; ?>)
                    <?php if ($isAdmin): ?>
                        — <strong>Admin</strong>
                    <?php endif; ?>
                    <?php if ($assignmentFilter > 0): ?>
                        <br>Filtering by assignment ID: <?php echo (int)$assignmentFilter; ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="app-actions">
                <a href="assignments.php" class="btn btn-outline-modern btn-sm">Assignments</a>
                <a href="statusReport.php" class="btn btn-outline-modern btn-sm">Status Report</a>
                <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info alert-modern mb-3" role="alert">
                <?php echo htmlspecialchars($message); ?>
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
                            <?php echo $editingReview ? 'Update Review' : 'Create Review'; ?>
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

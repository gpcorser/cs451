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

// Load all DB / business logic
require __DIR__ . '/assignments_logic.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assignments - CS-451 Peer Eval App</title>

    <link rel="shortcut icon"
          href="https://mypages.svsu.edu/~gpcorser/cs451/cs451_icon_dalle.png"
          type="image/png">

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
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

    <!-- Header -->
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
            <a href="statusReport.php" class="btn btn-outline-modern btn-sm">
                Status Report
            </a>
            <a href="login.php" class="btn btn-outline-modern btn-sm">
                Back to Login
            </a>
        </div>
    </div>

    <!-- Message -->
    <?php if ($message !== ''): ?>
        <div class="alert alert-info alert-modern mb-3">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- ADMIN FORM -->
    <?php if ($isAdmin): ?>
        <fieldset class="form-box-peach mb-4">
            <legend class="form-box-legend">
                <?php echo htmlspecialchars($formTitle); ?>
            </legend>

            <form method="post" enctype="multipart/form-data"
                  action="assignments.php<?php echo $editingAssignment ? '?edit=' . (int)$valId : ''; ?>">

                <input type="hidden" name="action" value="<?php echo $formMode; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$valId; ?>">

                <div class="row g-2 align-items-start">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Name</label>
                        <input type="text"
                               name="name"
                               class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($valName); ?>"
                               required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label mb-1">Description</label>
                        <textarea name="description"
                                  class="form-control form-control-sm"
                                  rows="2"
                                  required><?php echo htmlspecialchars($valDescription); ?></textarea>
                    </div>

                    <div class="col-md-1">
                        <label class="form-label mb-1">Team Size</label>
                        <input type="number"
                               name="team_size"
                               min="3" max="4"
                               class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($valTeamSize); ?>"
                               <?php echo ($editingHasTeams ? 'readonly' : ''); ?>
                               required>
                        <?php if ($editingHasTeams): ?>
                            <div class="form-text small">
                                Team size locked once teams exist.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">Assigned</label>
                        <input type="date"
                               name="date_assigned"
                               class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($valDateAssigned); ?>"
                               required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">Due</label>
                        <input type="date"
                               name="date_due"
                               class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($valDateDue); ?>"
                               required>
                    </div>

                    <div class="col-md-1 d-flex align-items-end">
                        <div class="w-100">
                            <button class="btn btn-modern btn-sm w-100 mb-1">
                                <?php echo $editingAssignment ? 'Update' : 'Add'; ?>
                            </button>
                            <?php if ($editingAssignment): ?>
                                <a href="assignments.php"
                                   class="btn btn-outline-modern btn-sm w-100">
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label mb-1">Instruction PDFs (admin only, max 3, 2MB each)</label>

                    <input type="file"
                        name="assignment_pdfs[]"
                        accept=".pdf"
                        multiple
                        class="form-control form-control-sm"
                        style="max-width:520px;">

                    <div class="form-text small">Students will see these PDFs when they open the Teams view for the assignment.</div>

                    <?php if ($editingAssignment): ?>
                        <?php $editFiles = $assignmentFilesByAssignment[(int)$valId] ?? []; ?>
                        <?php if (!empty($editFiles)): ?>
                            <div class="mt-2">
                                <div class="text-muted small mb-1">Existing instruction PDFs:</div>
                                <ul class="mb-0">
                                    <?php foreach ($editFiles as $f): ?>
                                        <li>
                                            <a href="download.php?type=assignment&id=<?php echo (int)$f['id']; ?>">
                                                <?php echo htmlspecialchars($f['original_name']); ?>
                                            </a>
                                            <form method="post"
                                                  action="assignments.php?edit=<?php echo (int)$valId; ?>"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Delete this PDF?');">
                                                <input type="hidden" name="action" value="delete_uploaded_file">
                                                <input type="hidden" name="file_type" value="assignment">
                                                <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                                                <button type="submit" class="btn btn-outline-modern btn-sm">Delete</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        </fieldset>
    <?php endif; ?>

    <!-- ASSIGNMENT LIST -->


   <h2 class="status-section-title">Assignment List</h2>

<?php if (empty($assignments)): ?>
    <p>No assignments have been created yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle status-table">
            <thead>
                <tr>
                    <!-- Narrower assignment column -->
                    <th style="width: 40%;">Assignment</th>

                    <!-- Keep due compact -->
                    <th class="text-nowrap" style="width: 15%;">Due</th>

                    <!-- Wider actions column -->
                    <th style="width: 45%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <?php $aid = (int)$a['id']; ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($a['name']); ?></strong><br>
                            <small class="text-muted">
                                <?php echo nl2br(htmlspecialchars($a['description'])); ?>
                            </small>
                        </td>

                        <td class="text-nowrap">
                            <?php echo htmlspecialchars($a['date_due']); ?>
                        </td>

                        <td>
    <div class="d-flex flex-wrap gap-1 mb-1">
        <a
            href="reviews.php?assignment_id=<?php echo $aid; ?>"
            class="btn btn-modern btn-sm"
        >
            Evals
        </a>

        <a
            href="assignments.php?show_teams=<?php echo $aid; ?>"
            class="btn btn-modern btn-sm"
        >
            Teams
        </a>
    

    <?php if ($isAdmin): ?>

            <a
                href="assignments.php?edit=<?php echo $aid; ?>"
                class="btn btn-outline-modern btn-sm"
            >
                Edit
            </a>
            <form
                method="post"
                action="assignments.php"
                onsubmit="return confirm('Delete this assignment?');"
            >
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $aid; ?>">
                <button
                    type="submit"
                    class="btn btn-outline-modern btn-sm"
                >
                    Delete
                </button>
            </form>

    <?php endif; ?>

    </div>
</td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>


    <!-- TEAM ASSIGNMENTS -->
    <?php if ($selectedTeamsAssignmentId && $selectedTeamsAssignmentName): ?>
        <hr class="mt-4 mb-3">
        <h2 class="status-section-title">
            Team Assignments for: <?php echo htmlspecialchars($selectedTeamsAssignmentName); ?>
        </h2>

        <!-- ASSIGNMENT INSTRUCTION PDFs -->
        <div class="mb-3">
            <h3 class="h6 mb-2">Assignment Instructions (PDF)</h3>

            <?php
            $aFiles = $assignmentFilesByAssignment[$selectedTeamsAssignmentId] ?? [];
            ?>

            <?php if (empty($aFiles)): ?>
                <div class="text-muted small">No instruction PDFs uploaded for this assignment yet.</div>
            <?php else: ?>
                <ul class="mb-2">
                    <?php foreach ($aFiles as $f): ?>
                        <li>
                            <a href="download.php?type=assignment&id=<?php echo (int)$f['id']; ?>">
                                <?php echo htmlspecialchars($f['original_name']); ?>
                            </a>
                            <span class="text-muted small">
                                (<?php echo (int)$f['file_size']; ?> bytes)
                            </span>
                            <?php if ($isAdmin): ?>
                                <form method="post" action="assignments.php?show_teams=<?php echo (int)$selectedTeamsAssignmentId; ?>"
                                      class="d-inline"
                                      onsubmit="return confirm('Delete this file?');">
                                    <input type="hidden" name="action" value="delete_uploaded_file">
                                    <input type="hidden" name="file_type" value="assignment">
                                    <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                                    <button type="submit" class="btn btn-outline-modern btn-sm">Delete</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="form-text small">Students: re-read these instructions before evaluating a peer's submission.</div>
        </div>

        <?php if (empty($teamAssignmentsRows)): ?>
            <p>No team assignments found.</p>
        <?php else: ?>

            <?php
            // group students by team
            $teams = [];
            $userTeams = [];
            foreach ($teamAssignmentsRows as $r) {
                $tn = (int)$r['team_number'];
                if (!isset($teams[$tn])) $teams[$tn] = [];
                $teams[$tn][] = $r;
                if ((int)$r['person_id'] === $loggedInUserId) {
                    $userTeams[$tn] = true;
                }
            }
            ksort($teams);
            ?>

            <?php foreach ($teams as $teamNumber => $members): ?>
                <?php
                $canSeeThisTeam = $isAdmin || !empty($userTeams[$teamNumber]);
                ?>

                <div class="card shadow-sm mb-3" style="border-radius:16px;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h3 class="h6 mb-1">Team <?php echo (int)$teamNumber; ?></h3>
                                <div class="text-muted small">Members:</div>
                                <ul class="mb-0">
                                    <?php foreach ($members as $m): ?>
                                        <li>
                                            <?php
                                            $fullName = trim($m['fname'] . ' ' . $m['lname']);
                                            echo htmlspecialchars($fullName) . ' (' . (int)$m['person_id'] . ')';
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <?php if ($canSeeThisTeam): ?>
                                <div style="min-width: 420px;">
                                    <div class="text-muted small mb-1">Student submissions (ZIP)</div>

                                    <?php foreach ($members as $m): ?>
                                        <?php
                                            $pid = (int)$m['person_id'];
                                            $pKey = (int)$selectedTeamsAssignmentId . ':' . $pid;
                                            $subs = $submissionFilesByPersonKey[$pKey] ?? [];
                                            $isOwner = ($pid === $loggedInUserId);
                                        ?>

                                        <div class="border rounded-3 p-2 mb-2" style="background:#fff;">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars(trim($m['fname'] . ' ' . $m['lname'])); ?>
                                                        <span class="text-muted small">(id=<?php echo $pid; ?>)</span>
                                                    </div>

                                                    <?php if (empty($subs)): ?>
                                                        <div class="text-muted small">No ZIP uploaded yet.</div>
                                                    <?php else: ?>
                                                        <ul class="mb-1">
                                                            <?php foreach ($subs as $f): ?>
                                                                <li>
                                                                    <a href="download.php?type=submission&id=<?php echo (int)$f['id']; ?>">
                                                                        <?php echo htmlspecialchars($f['original_name']); ?>
                                                                    </a>
                                                                    <span class="text-muted small">(<?php echo (int)$f['file_size']; ?> bytes)</span>

                                                                    <?php if ($isAdmin || $isOwner): ?>
                                                                        <form method="post" action="assignments.php?show_teams=<?php echo (int)$selectedTeamsAssignmentId; ?>"
                                                                              class="d-inline" onsubmit="return confirm('Delete this ZIP?');">
                                                                            <input type="hidden" name="action" value="delete_uploaded_file">
                                                                            <input type="hidden" name="file_type" value="submission">
                                                                            <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                                                                            <button type="submit" class="btn btn-outline-modern btn-sm">Delete</button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($isAdmin || $isOwner): ?>
                                                    <div>
                                                        <form method="post" enctype="multipart/form-data"
                                                              action="assignments.php?show_teams=<?php echo (int)$selectedTeamsAssignmentId; ?>"
                                                              class="d-flex flex-wrap gap-2 align-items-center">
                                                            <input type="hidden" name="action" value="upload_submission_files">
                                                            <input type="hidden" name="assignment_id" value="<?php echo (int)$selectedTeamsAssignmentId; ?>">
                                                            <input type="hidden" name="person_id" value="<?php echo $pid; ?>">
                                                            <input type="file" name="files[]" accept=".zip" multiple
                                                                   class="form-control form-control-sm" style="max-width:320px" required>
                                                            <button type="submit" class="btn btn-modern btn-sm">Upload ZIP</button>
                                                        </form>
                                                        <div class="form-text small">Max 3 ZIPs total for this assignment. 2MB each.</div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">Submissions are visible only to that team (or admin).</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    <?php endif; ?>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    crossorigin="anonymous"></script>

</body>
</html>

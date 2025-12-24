<?php
// Team assignments view (HTML partial)
// Expects: $selectedTeamsAssignmentId, $selectedTeamsAssignmentName, $assignmentFilesByAssignment,
//          $teamAssignmentsRows, $loggedInUserId, $isAdmin, $submissionFilesByPersonKey
?>

<?php if ($selectedTeamsAssignmentId && $selectedTeamsAssignmentName): ?>
    <hr class="mt-4 mb-3">
    <h2 class="status-section-title">
        Team Assignments for: <?php echo htmlspecialchars($selectedTeamsAssignmentName); ?>
    </h2>

    <!-- ASSIGNMENT INSTRUCTION PDFs -->
    <div class="mb-3">
        <h3 class="h6 mb-2">Assignment Instructions (PDF)</h3>

        <?php $aFiles = $assignmentFilesByAssignment[$selectedTeamsAssignmentId] ?? []; ?>

        <?php if (empty($aFiles)): ?>
            <div class="text-muted small">No instruction PDFs uploaded for this assignment yet.</div>
        <?php else: ?>
            <ul class="mb-2">
                <?php foreach ($aFiles as $f): ?>
                    <li>
                        <a href="download.php?type=assignment&id=<?php echo (int)$f['id']; ?>">
                            <?php echo htmlspecialchars($f['original_name']); ?>
                        </a>
                        <span class="text-muted small">(<?php echo (int)$f['file_size']; ?> bytes)</span>
                        <?php if ($isAdmin): ?>
                            <form method="post"
                                  action="assignments.php?show_teams=<?php echo (int)$selectedTeamsAssignmentId; ?>"
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
            if (!isset($teams[$tn])) {
                $teams[$tn] = [];
            }
            $teams[$tn][] = $r;
            if ((int)$r['person_id'] === $loggedInUserId) {
                $userTeams[$tn] = true;
            }
        }
        ksort($teams);
        ?>

        <?php foreach ($teams as $teamNumber => $members): ?>
            <?php $canSeeThisTeam = $isAdmin || !empty($userTeams[$teamNumber]); ?>

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
                                                                    <form method="post"
                                                                          action="assignments.php?show_teams=<?php echo (int)$selectedTeamsAssignmentId; ?>"
                                                                          class="d-inline"
                                                                          onsubmit="return confirm('Delete this ZIP?');">
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

<?php
// ---------------------------------------------------------------------
// Roster (alphabetical by last name) for Teams view
// ---------------------------------------------------------------------
if (!empty($selectedTeamsAssignmentId) && !empty($teamAssignmentsRows)) {

    // Make an alphabetical copy (do NOT disturb existing team-group display)
    $alphaRows = $teamAssignmentsRows;
    usort($alphaRows, function ($a, $b) {
        $al = mb_strtolower(trim((string)($a['lname'] ?? '')));
        $bl = mb_strtolower(trim((string)($b['lname'] ?? '')));
        if ($al !== $bl) return $al <=> $bl;

        $af = mb_strtolower(trim((string)($a['fname'] ?? '')));
        $bf = mb_strtolower(trim((string)($b['fname'] ?? '')));
        if ($af !== $bf) return $af <=> $bf;

        return ((int)($a['person_id'] ?? 0)) <=> ((int)($b['person_id'] ?? 0));
    });
    ?>

    <hr class="my-4">

    <h5 class="mb-2">Roster (Alphabetical)</h5>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th style="width: 90px;">Team #</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alphaRows as $r): ?>
                <tr>
                    <td><?php echo (int)($r['team_number'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['fname'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['lname'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php } ?>

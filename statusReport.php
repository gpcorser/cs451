<?php
require __DIR__ . '/status_common.php';

/**
 * Build clickable PDF "emoji" links for this student's submissions on this assignment.
 * We reuse the existing $zipIdMap (loaded per-assignment) and filter to PDF files only.
 *
 * Expected $zipIdMap structure (as used by render_zip_buttons()):
 *   $zipIdMap[$studentId] = [
 *      ['id' => <file_id>, 'original_name' => '...', 'file_index' => 1, ...],
 *      ...
 *   ]
 *
 * If your load_submission_zip_ids_for_assignment() returns a different structure,
 * adjust the $fid / $oname pulls below accordingly.
 */
function render_pdf_emoji_links(array $zipIdMap, int $studentId): string
{
    $items = $zipIdMap[$studentId] ?? [];
    if (!is_array($items) || empty($items)) {
        return '';
    }

    $out = '';
    foreach ($items as $idx => $row) {
        if (!is_array($row)) { continue; }

        $fid   = isset($row['id']) ? (int)$row['id'] : 0;
        $oname = isset($row['original_name']) ? (string)$row['original_name'] : '';

        // Only show for PDFs
        $isPdf = (strtolower(pathinfo($oname, PATHINFO_EXTENSION)) === 'pdf');
        if ($fid <= 0 || !$isPdf) {
            continue;
        }

        $label = $oname !== '' ? $oname : ('PDF ' . ($idx + 1));

        // view=inline lets PDFs open in-browser if download.php supports it
        $href = 'download.php?type=submission&id=' . $fid . '&view=inline';

        $out .= ' <a class="pdf-emoji-link"'
              . ' href="' . htmlspecialchars($href) . '"'
              . ' target="_blank" rel="noopener noreferrer"'
              . ' title="' . htmlspecialchars($label) . '">📄</a>';
    }

    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Status Report - CS-451 Peer Eval App</title>
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

    <!-- Local tweaks for requested UI behavior -->
    <style>
        .cnt-bg-green { background: #d9f2d9 !important; }
        .cnt-bg-yellow { background: #fff3cd !important; }
        .cnt-bg-pink { background: #f8d7da !important; }
        .cnt-cell {
            text-align: center;
            font-weight: 600;
        }
        .pdf-emoji-link {
            text-decoration: none;
            margin-left: 4px;
        }
    </style>
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

                <a href="updatePerson.php" class="btn btn-outline-modern">
                    Update My Info
                </a>

                <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
            </div>

        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info alert-modern mb-3" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <?php
                                $aid    = (int)$a['id'];
                                $gStats = $givenStatsByAssignment[$aid]    ?? ['cnt' => 0, 'avg' => null];
                                $rStats = $receivedStatsByAssignment[$aid] ?? ['cnt' => 0, 'avg' => null];

                                $gCnt = $gStats['cnt'];
                                $gAvg = $gStats['avg'];
                                $rCnt = $rStats['cnt'];
                                $rAvg = $rStats['avg'];

                                $gAvgText = ($gCnt > 0 && $gAvg !== null) ? number_format($gAvg, 1) : '-';
                                $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format($rAvg, 1) : '-';

                                // compute team label for this assignment and logged-in user
                                $teamLabel = get_team_label_for_assignment($pdo, $aid, $loggedInUserId);
                            ?>
                            <!-- normal row -->
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($a['name']); ?></div>
                                    <?php if (!empty($assignmentPdfs[$aid])): ?>
                                        <div class="small mt-1">
                                            <span class="text-muted">Instructions:</span>
                                            <?php foreach ($assignmentPdfs[$aid] as $pf): ?>
<a class="btn btn-outline-modern btn-sm"
   href="download.php?type=assignment&id=<?php echo (int)$pf['id']; ?>&view=inline"
   target="_blank" rel="noopener">
   PDF <?php echo (int)$pf['file_index']; ?>
</a>

                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1">Instructions: (none uploaded yet)</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($a['date_assigned']); ?></td>
                                <td><?php echo htmlspecialchars($a['date_due']); ?></td>
                                <td><?php echo $gCnt; ?></td>
                                <td><?php echo $gAvgText; ?></td>
                                <td><?php echo $rCnt; ?></td>
                                <td><?php echo $rAvgText; ?></td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <a href="reviews.php?assignment_id=<?php echo $aid; ?>" class="btn btn-outline-modern btn-sm">Reviews</a>

                                        <button class="btn btn-outline-modern btn-sm" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#upload-<?php echo $aid; ?>"
                                                aria-expanded="false" aria-controls="upload-<?php echo $aid; ?>">
                                            Upload ZIP
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Upload / submission row -->
                            <tr class="collapse" id="upload-<?php echo $aid; ?>">
                                <td colspan="8">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="fw-semibold mb-1">My submission (ZIP)</div>
                                            <?php $mine = $mySubmissionsByAssignment[$aid] ?? []; ?>
                                            <?php if (empty($mine)): ?>
                                                <div class="small text-muted mb-2">No ZIP uploaded yet.</div>
                                            <?php else: ?>
                                                <ul class="list-unstyled small mb-2">
                                                    <?php foreach ($mine as $sf): ?>
                                                        <li class="mb-1">
                                                            <a class="btn btn-outline-modern btn-sm" href="download.php?type=submission&id=<?php echo (int)$sf['id']; ?>">
                                                                Download ZIP <?php echo (int)$sf['file_index']; ?>
                                                            </a>
                                                            <span class="ms-2 text-muted"><?php echo htmlspecialchars($sf['original_name']); ?></span>
                                                            <form method="post" action="statusReport.php" class="d-inline" onsubmit="return confirm('Delete this uploaded ZIP?');">
                                                                <input type="hidden" name="action" value="delete_submission">
                                                                <input type="hidden" name="file_id" value="<?php echo (int)$sf['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-modern btn-sm ms-2">Delete</button>
                                                            </form>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>

                                            <form method="post" action="statusReport.php" enctype="multipart/form-data" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="action" value="upload_submission">
                                                <input type="hidden" name="assignment_id" value="<?php echo $aid; ?>">
                                                <input type="hidden" name="person_id" value="<?php echo $loggedInUserId; ?>">
                                                <input class="form-control form-control-sm" type="file" name="submission_zip" accept=".zip,application/zip" required>
                                                <div class="small text-muted">ZIP only. Max 2MB. Up to 3 uploads per assignment.</div>
                                                <button type="submit" class="btn btn-modern btn-sm">Upload</button>
                                            </form>
                                        </div>

                                        <div class="col-md-6">
                                            <?php if ($teamLabel): ?>
                                                <div class="fw-semibold mb-1">My team</div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($teamLabel); ?></div>
                                                <div class="small text-muted mt-2">Peers will download your ZIP from the Reviews screen.</div>
                                            <?php else: ?>
                                                <div class="small text-danger">You are not assigned to a team for this assignment.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <?php if ($teamLabel): ?>
                            <!-- extra row: first cell empty, remaining merged -->
                            <tr>
                                <td></td>
                                <td colspan="7" class="small text-muted">
                                    <?php echo htmlspecialchars($teamLabel); ?>
                                </td>
                            </tr>
                            <?php endif; ?>

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
                            $aid        = (int)$a['id'];
                            $headingId  = 'heading' . $aid;
                            $collapseId = 'collapse' . $aid;

                            // Load submission file id map once per assignment (admin view)
                            // NOTE: Despite the name "zip", we also reuse this map for PDF filtering.
                            $zipIdMap = load_submission_zip_ids_for_assignment($pdo, $aid);
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
        <th>Details</th>
    </tr>
</thead>
<tbody>
    <?php foreach ($students as $stu): ?>
        <?php
            $sid = (int)$stu['id'];

            $gStats = $statsGivenByAssignmentAndStudent[$aid][$sid]    ?? ['cnt' => 0, 'avg' => null];
            $rStats = $statsReceivedByAssignmentAndStudent[$aid][$sid] ?? ['cnt' => 0, 'avg' => null];

            $gCnt = (int)($gStats['cnt'] ?? 0);
            $gAvg = $gStats['avg'] ?? null;
            $rCnt = (int)($rStats['cnt'] ?? 0);
            $rAvg = $rStats['avg'] ?? null;

            $gAvgText = ($gCnt > 0 && $gAvg !== null) ? number_format((float)$gAvg, 1) : '-';
            $rAvgText = ($rCnt > 0 && $rAvg !== null) ? number_format((float)$rAvg, 1) : '-';

            $fullName = $stu['lname'] . ', ' . $stu['fname'];

            // unique collapse ID per assignment + student
            $collapseIdStudent = 'details-' . $aid . '-' . $sid;

            // Fetch detailed reviews GIVEN and RECEIVED for this (assignment, student)
            $detailsGiven = [];
            $detailsReceived = [];

            if (isset($stmtDetailsGiven, $stmtDetailsReceived)) {
                $stmtDetailsGiven->execute([':aid' => $aid, ':sid' => $sid]);
                $detailsGiven = $stmtDetailsGiven->fetchAll(PDO::FETCH_ASSOC);

                $stmtDetailsReceived->execute([':aid' => $aid, ':sid' => $sid]);
                $detailsReceived = $stmtDetailsReceived->fetchAll(PDO::FETCH_ASSOC);
            }

            // Existing ZIP buttons (reused in both nested tables)
            $zipButtonsHtml = render_zip_buttons($zipIdMap, $sid);

            // NEW: PDF emoji links (shown in summary row, Reviews Received column)
            $pdfEmojiHtml = render_pdf_emoji_links($zipIdMap, $sid);

            // NEW: background class for Reviews Given count
            if ($gCnt >= 2) {
                $givenCntClass = 'cnt-bg-green';
            } elseif ($gCnt === 1) {
                $givenCntClass = 'cnt-bg-yellow';
            } else {
                $givenCntClass = 'cnt-bg-pink';
            }
        ?>
        <!-- Summary row -->
        <tr>
            <td><?php echo htmlspecialchars($fullName . ' (' . $sid . ')'); ?></td>
            <td><?php echo htmlspecialchars($stu['email']); ?></td>

            <!-- Reviews Given: colored backgrounds -->
            <td class="cnt-cell <?php echo $givenCntClass; ?>">
                <?php echo $gCnt; ?>
            </td>

            <td><?php echo htmlspecialchars($gAvgText); ?></td>

            <!-- Reviews Received: count + PDF emojis (only if PDF uploads exist) -->
            <td class="cnt-cell">
                <?php echo $rCnt; ?><?php echo $pdfEmojiHtml; ?>
            </td>

            <td><?php echo htmlspecialchars($rAvgText); ?></td>

            <td>
                <button
                    type="button"
                    class="btn btn-outline-modern btn-sm"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?php echo $collapseIdStudent; ?>"
                    aria-expanded="false"
                    aria-controls="<?php echo $collapseIdStudent; ?>"
                >
                    Details
                </button>
            </td>
        </tr>

        <!-- Nested detail row -->
        <tr class="collapse" id="<?php echo $collapseIdStudent; ?>">
            <td colspan="7">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Reviews Given</strong>
                        <?php if (empty($detailsGiven)): ?>
                            <p class="small text-muted mb-1">No reviews given for this assignment.</p>
                        <?php else: ?>
                            <table class="table table-sm mb-2">
                                <thead>
                                    <tr>
                                        <th>About</th>
                                        <th>Rating</th>
                                        <th>Comments</th>
                                        <th>ZIP files</th>
                                        <th>Finalized</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detailsGiven as $d): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($d['student_lname'] . ', ' . $d['student_fname']); ?></td>
                                            <td><?php echo (int)$d['rating']; ?></td>
                                            <td><?php echo nl2br(htmlspecialchars($d['comments'])); ?></td>
                                            <td><?php echo $zipButtonsHtml; ?></td>
                                            <td><?php echo htmlspecialchars($d['date_finalized'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <strong>Reviews Received</strong>
                        <?php if (empty($detailsReceived)): ?>
                            <p class="small text-muted mb-1">No reviews received for this assignment.</p>
                        <?php else: ?>
                            <table class="table table-sm mb-2">
                                <thead>
                                    <tr>
                                        <th>From</th>
                                        <th>Rating</th>
                                        <th>Comments</th>
                                        <th>ZIP files</th>
                                        <th>Finalized</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detailsReceived as $d): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($d['reviewer_lname'] . ', ' . $d['reviewer_fname']); ?></td>
                                            <td><?php echo (int)$d['rating']; ?></td>
                                            <td><?php echo nl2br(htmlspecialchars($d['comments'])); ?></td>
                                            <td><?php echo $zipButtonsHtml; ?></td>
                                            <td><?php echo htmlspecialchars($d['date_finalized'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
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

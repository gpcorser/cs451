<?php
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/upload_helpers.php';

$loggedInUserId = (int)$_SESSION['user_id'];
$isAdmin        = !empty($_SESSION['is_admin']);

$type   = $_GET['type'] ?? '';
$fileId = (int)($_GET['id'] ?? 0);

// New: optional inline viewing flag
$viewInline = (($_GET['view'] ?? '') === 'inline');

if (!in_array($type, ['assignment', 'submission'], true) || $fileId <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

if ($type === 'assignment') {
    $stmt = $pdo->prepare('
        SELECT af.id, af.assignment_id, af.original_name, af.stored_name, af.mime_type, af.file_size
        FROM assignment_files af
        WHERE af.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $fileId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('File not found.');
    }

    $path = __DIR__ . '/uploads/assignments/' . (int)$row['assignment_id'] . '/' . $row['stored_name'];

} else { // submission
    $stmt = $pdo->prepare('
        SELECT tf.id, tf.assignment_id, tf.person_id, tf.original_name, tf.stored_name, tf.mime_type, tf.file_size
        FROM teamassignment_files tf
        WHERE tf.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $fileId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('File not found.');
    }

    $aid = (int)$row['assignment_id'];
    $pid = (int)$row['person_id'];

    // Permission: admin OR the owner OR a member of the same team for that assignment
    if (!$isAdmin && $pid !== $loggedInUserId) {
        // Find submitter's team
        $teamStmt = $pdo->prepare('SELECT team_number FROM teamassignments WHERE assignment_id = :aid AND person_id = :pid LIMIT 1');
        $teamStmt->execute([':aid' => $aid, ':pid' => $pid]);
        $submitterTeam = (int)$teamStmt->fetchColumn();
        if ($submitterTeam <= 0) {
            http_response_code(403);
            exit('Not authorized.');
        }

        // Must be on the same team
        $check = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid AND team_number = :tn AND person_id = :uid');
        $check->execute([':aid' => $aid, ':tn' => $submitterTeam, ':uid' => $loggedInUserId]);
        if ((int)$check->fetchColumn() === 0) {
            http_response_code(403);
            exit('Not authorized.');
        }
    }

    $path = __DIR__ . '/uploads/submissions/' . $aid . '/' . $pid . '/' . $row['stored_name'];
}

if (!is_file($path)) {
    http_response_code(404);
    exit('File missing on server.');
}

$downloadName = safe_download_name((string)$row['original_name']);
$mime         = (string)($row['mime_type'] ?? 'application/octet-stream');

// Only allow inline viewing for PDFs.
// (This prevents someone from forcing inline on ZIPs or other types.)
if ($mime !== 'application/pdf') {
    $viewInline = false;
}

// Recommended: inline viewing primarily for assignment PDFs.
// If you want to allow inline PDF viewing for submissions too, remove this condition.
if ($type !== 'assignment') {
    $viewInline = false;
}

// Headers
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');

// Optional but helpful for PDFs opened in-browser:
header('Accept-Ranges: bytes');

if ($viewInline) {
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
}

header('Content-Length: ' . filesize($path));

readfile($path);
exit;

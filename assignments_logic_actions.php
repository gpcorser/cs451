<?php
/**
 * assignments_logic_actions_v2.php
 * Hardened POST dispatcher with action whitelist and early returns.
 */

function assignments_handle_post(PDO $pdo, int $loggedInUserId, bool $isAdmin, string &$message): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $action = (string)($_POST['action'] ?? '');
    $allowed = ['create', 'update', 'delete', 'upload_submission_files', 'delete_uploaded_file'];

    if (!in_array($action, $allowed, true)) {
        http_response_code(400);
        $message = 'Invalid action.';
        return;
    }

    try {
        switch ($action) {
            case 'create':
                if (!$isAdmin) { http_response_code(403); $message = 'Not authorized.'; return; }
                assignments_action_create($pdo, $loggedInUserId, $message);
                return;

            case 'update':
                if (!$isAdmin) { http_response_code(403); $message = 'Not authorized.'; return; }
                assignments_action_update($pdo, $loggedInUserId, $message);
                return;

            case 'delete':
                if (!$isAdmin) { http_response_code(403); $message = 'Not authorized.'; return; }
                assignments_action_delete($pdo, $message);
                return;

            case 'upload_submission_files':
                assignments_action_upload_submission_files($pdo, $loggedInUserId, $message);
                return;

            case 'delete_uploaded_file':
                assignments_action_delete_uploaded_file($pdo, $loggedInUserId, $isAdmin, $message);
                return;
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        return;
    }
}

function assignments_action_create(PDO $pdo, int $loggedInUserId, string &$message): void
//function assignments_action_create(PDO $pdo, string &$message): void
{
    $name        = trim((string)($_POST['name'] ?? ''));
    $dueDate     = trim((string)($_POST['date_due'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $teamSize    = (int)($_POST['team_size'] ?? 3);

    if ($name === '' || $dueDate === '' || $description === '') {
        $message = 'Please fill in all required fields.';
        return;
    }
    if ($teamSize !== 3 && $teamSize !== 4) $teamSize = 3;

    $stmt = $pdo->prepare('INSERT INTO assignments (name, date_due, description, team_size) VALUES (:n, :d, :desc, :ts)');
    $stmt->execute([':n'=>$name, ':d'=>$dueDate, ':desc'=>$description, ':ts'=>$teamSize]);
    $newId = (int)$pdo->lastInsertId();

    if (!empty($_FILES['assignment_pdfs']) && !empty($_FILES['assignment_pdfs']['name'])) {
        //assignments_handle_assignment_pdf_uploads($pdo, $newId, $_FILES['assignment_pdfs']);
        assignments_handle_assignment_pdf_uploads($pdo, $newId, $loggedInUserId, $_FILES['assignment_pdfs']);
    }

    $message = 'Assignment created.';
}

function assignments_action_update(PDO $pdo, int $loggedInUserId, string &$message): void
// function assignments_action_update(PDO $pdo, string &$message): void
{
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim((string)($_POST['name'] ?? ''));
    $dueDate     = trim((string)($_POST['date_due'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $teamSize    = (int)($_POST['team_size'] ?? 3);

    if ($id <= 0) { $message = 'Invalid assignment id.'; return; }
    if ($name === '' || $dueDate === '' || $description === '') { $message = 'Please fill in all required fields.'; return; }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid');
    $stmt->execute([':aid'=>$id]);
    $hasTeams = ((int)$stmt->fetchColumn() > 0);

    if ($hasTeams) {
        $stmt = $pdo->prepare('SELECT team_size FROM assignments WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        $teamSize = (int)$stmt->fetchColumn();
    } else {
        $teamSize = 3;
    }

    $stmt = $pdo->prepare('UPDATE assignments SET name=:n, date_due=:d, description=:desc, team_size=:ts WHERE id=:id');
    $stmt->execute([':n'=>$name, ':d'=>$dueDate, ':desc'=>$description, ':ts'=>$teamSize, ':id'=>$id]);

    if (!empty($_FILES['assignment_pdfs']) && !empty($_FILES['assignment_pdfs']['name'])) {
        assignments_handle_assignment_pdf_uploads($pdo, $id, $loggedInUserId, $_FILES['assignment_pdfs']);
    }

    if (!$hasTeams) {
        if (function_exists('generateTeamsForAssignment')) {
            $inserted = generateTeamsForAssignment($pdo, $id);
            $message = 'Assignment updated. Teams generated (' . (int)$inserted . ' students assigned).';
        } else {
            $message = 'Assignment updated. (Warning: team generator not available.)';
        }
    } else {
        $message = 'Assignment updated.';
    }
}

function assignments_action_delete(PDO $pdo, string &$message): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { $message = 'Invalid assignment id.'; return; }

    $stmt = $pdo->prepare('SELECT stored_name FROM assignment_files WHERE assignment_id = :aid');
    $stmt->execute([':aid'=>$id]);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $stored = (string)$r['stored_name'];
        $path = __DIR__ . '/uploads/assignments/' . $id . '/' . $stored;
        if ($stored !== '' && is_file($path)) @unlink($path);
    }
    $pdo->prepare('DELETE FROM assignment_files WHERE assignment_id = :aid')->execute([':aid'=>$id]);

    $stmt = $pdo->prepare('SELECT person_id, stored_name FROM teamassignment_files WHERE assignment_id = :aid');
    $stmt->execute([':aid'=>$id]);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $pid = (int)$r['person_id'];
        $stored = (string)$r['stored_name'];
        $path = __DIR__ . '/uploads/submissions/' . $id . '/' . $pid . '/' . $stored;
        if ($stored !== '' && is_file($path)) @unlink($path);
    }
    $pdo->prepare('DELETE FROM teamassignment_files WHERE assignment_id = :aid')->execute([':aid'=>$id]);
    $pdo->prepare('DELETE FROM teamassignments WHERE assignment_id = :aid')->execute([':aid'=>$id]);

    $pdo->prepare('DELETE FROM assignments WHERE id = :id')->execute([':id'=>$id]);

    $message = 'Assignment deleted.';
}

function assignments_action_upload_submission_files(PDO $pdo, int $loggedInUserId, string &$message): void
{
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if ($assignmentId <= 0) { $message = 'Invalid assignment.'; return; }

    $stmt = $pdo->prepare('SELECT team_number FROM teamassignments WHERE assignment_id = :aid AND person_id = :pid LIMIT 1');
    $stmt->execute([':aid'=>$assignmentId, ':pid'=>$loggedInUserId]);
    if ($stmt->fetchColumn() === false) { http_response_code(403); $message = 'Not authorized to upload for this assignment.'; return; }

    if (empty($_FILES['files']) || empty($_FILES['files']['name'])) { $message = 'No files selected.'; return; }

    $files = assignments_flatten_files_array($_FILES['files']);
    $clean = assignments_validate_uploads($files, 5, 30*1024*1024, ['zip']);

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(file_index), 0) FROM teamassignment_files WHERE assignment_id = :aid AND person_id = :pid');
    $stmt->execute([':aid'=>$assignmentId, ':pid'=>$loggedInUserId]);
    $nextIndex = ((int)$stmt->fetchColumn()) + 1;

    $pdo->beginTransaction();
    try {
        foreach ($clean as $f) {
            $stored = assignments_make_storage_name($f['ext']);
            $dir = __DIR__ . '/uploads/submissions/' . $assignmentId . '/' . $loggedInUserId;
            assignments_ensure_dir($dir);
            $dest = $dir . '/' . $stored;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Failed to move uploaded file.');
            }

            $stmt = $pdo->prepare('INSERT INTO teamassignment_files (assignment_id, person_id, file_index, original_name, stored_name, mime_type, file_size)
                                   VALUES (:aid,:pid,:idx,:on,:sn,:mt,:sz)');
            $stmt->execute([
                ':aid'=>$assignmentId, ':pid'=>$loggedInUserId, ':idx'=>$nextIndex,
                ':on'=>$f['name'], ':sn'=>$stored, ':mt'=>$f['type'], ':sz'=>$f['size']
            ]);
            $nextIndex++;
        }
        $pdo->commit();
        $message = 'Submission uploaded.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function assignments_action_delete_uploaded_file(PDO $pdo, int $loggedInUserId, bool $isAdmin, string &$message): void
{
    $fileType = (string)($_POST['file_type'] ?? '');
    $fileId   = (int)($_POST['file_id'] ?? 0);

    if ($fileId <= 0) { $message = 'Invalid file.'; return; }
    if ($fileType !== 'assignment' && $fileType !== 'submission') { $message = 'Invalid file type.'; return; }

    if ($fileType === 'assignment') {
        if (!$isAdmin) { http_response_code(403); $message = 'Not authorized.'; return; }

        $stmt = $pdo->prepare('SELECT assignment_id, stored_name FROM assignment_files WHERE id = :id');
        $stmt->execute([':id'=>$fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $message = 'File not found.'; return; }

        $aid = (int)$row['assignment_id'];
        $stored = (string)$row['stored_name'];
        $path = __DIR__ . '/uploads/assignments/' . $aid . '/' . $stored;
        if ($stored !== '' && is_file($path)) @unlink($path);

        $pdo->prepare('DELETE FROM assignment_files WHERE id = :id')->execute([':id'=>$fileId]);
        $message = 'File deleted.';
        return;
    }

    $stmt = $pdo->prepare('SELECT assignment_id, person_id, stored_name FROM teamassignment_files WHERE id = :id');
    $stmt->execute([':id'=>$fileId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $message = 'File not found.'; return; }

    $aid = (int)$row['assignment_id'];
    $pid = (int)$row['person_id'];
    if (!$isAdmin && $pid !== $loggedInUserId) { http_response_code(403); $message = 'Not authorized.'; return; }

    $stored = (string)$row['stored_name'];
    $path = __DIR__ . '/uploads/submissions/' . $aid . '/' . $pid . '/' . $stored;
    if ($stored !== '' && is_file($path)) @unlink($path);

    $pdo->prepare('DELETE FROM teamassignment_files WHERE id = :id')->execute([':id'=>$fileId]);
    $message = 'File deleted.';
}

function assignments_handle_assignment_pdf_uploads(PDO $pdo, int $assignmentId, int $uploadedBy, array $filesBag): void
// function assignments_handle_assignment_pdf_uploads(PDO $pdo, int $assignmentId, array $filesBag): void
{
    $files = assignments_flatten_files_array($filesBag);
    $clean = assignments_validate_uploads($files, 3, 10*1024*1024, ['pdf']);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assignment_files WHERE assignment_id = :aid');
    $stmt->execute([':aid'=>$assignmentId]);
    $existing = (int)$stmt->fetchColumn();
    $remaining = max(0, 3 - $existing);
    if ($remaining <= 0) return;
    if (count($clean) > $remaining) $clean = array_slice($clean, 0, $remaining);

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(file_index), 0) FROM assignment_files WHERE assignment_id = :aid');
    $stmt->execute([':aid'=>$assignmentId]);
    $nextIndex = ((int)$stmt->fetchColumn()) + 1;

    $pdo->beginTransaction();
    try {
        foreach ($clean as $f) {
            $stored = assignments_make_storage_name($f['ext']);
            $dir = __DIR__ . '/uploads/assignments/' . $assignmentId;
            assignments_ensure_dir($dir);
            $dest = $dir . '/' . $stored;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Failed to move uploaded PDF.');
            }

            if ($uploadedBy <= 0) {
                throw new RuntimeException('Invalid uploaded_by (not logged in).');
            }

            $stmt = $pdo->prepare('
                INSERT INTO assignment_files
                    (assignment_id, file_index, original_name, stored_name, mime_type, file_size, uploaded_by)
                VALUES
                    (:aid, :idx, :on, :sn, :mt, :sz, :ub)
            ');
            $stmt->execute([
                ':aid' => $assignmentId,
                ':idx' => $nextIndex,
                ':on'  => $f['name'],
                ':sn'  => $stored,
                ':mt'  => $f['type'],
                ':sz'  => $f['size'],
                ':ub'  => $uploadedBy,
            ]);

            $nextIndex++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function assignments_flatten_files_array(array $files): array
{
    $out = [];
    if (!isset($files['name'])) return $out;

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i=0; $i<$count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = [
                'name'=>(string)$files['name'][$i],
                'type'=>(string)($files['type'][$i] ?? ''),
                'tmp_name'=>(string)($files['tmp_name'][$i] ?? ''),
                'error'=>(int)($files['error'][$i] ?? UPLOAD_ERR_OK),
                'size'=>(int)($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    if (($files['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = [
            'name'=>(string)$files['name'],
            'type'=>(string)($files['type'] ?? ''),
            'tmp_name'=>(string)($files['tmp_name'] ?? ''),
            'error'=>(int)($files['error'] ?? UPLOAD_ERR_OK),
            'size'=>(int)($files['size'] ?? 0),
        ];
    }
    return $out;
}

/* ... rest of your helper functions remain unchanged ... */

function assignments_validate_uploads(array $files, int $maxFiles, int $maxBytesEach, array $allowedExts): array
{
    $allowed = array_map('strtolower', $allowedExts);
    $clean = [];

    foreach ($files as $f) {
        if (count($clean) >= $maxFiles) break;

        $err = (int)($f['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error code: ' . $err);

        $name = (string)$f['name'];
        $size = (int)($f['size'] ?? 0);
        $tmp  = (string)($f['tmp_name'] ?? '');

        if ($name === '' || $tmp === '') throw new RuntimeException('Invalid upload payload.');
        if ($size <= 0 || $size > $maxBytesEach) throw new RuntimeException('File too large.');

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) throw new RuntimeException('Invalid file type.');

        $clean[] = ['name'=>$name, 'type'=>(string)($f['type'] ?? ''), 'tmp_name'=>$tmp, 'size'=>$size, 'ext'=>$ext];
    }
    return $clean;
}

function assignments_ensure_dir(string $dir): void
{
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Failed to create directory: ' . $dir);
}

function assignments_make_storage_name(string $ext): string
{
    $ext = strtolower(trim($ext));
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

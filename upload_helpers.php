<?php
/**
 * upload_helpers.php
 *
 * Shared helpers for assignment/team file uploads.
 */

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Flatten a multi-upload $_FILES entry into a list.
 *
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function flatten_files_array(array $files): array
{
    $out = [];
    $names = $files['name'] ?? [];
    $types = $files['type'] ?? [];
    $tmps  = $files['tmp_name'] ?? [];
    $errs  = $files['error'] ?? [];
    $sizes = $files['size'] ?? [];

    if (!is_array($names)) {
        return [[
            'name'     => (string)($files['name'] ?? ''),
            'type'     => (string)($files['type'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error'    => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($files['size'] ?? 0),
        ]];
    }

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name'     => (string)($names[$i] ?? ''),
            'type'     => (string)($types[$i] ?? ''),
            'tmp_name' => (string)($tmps[$i] ?? ''),
            'error'    => (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($sizes[$i] ?? 0),
        ];
    }
    return $out;
}

/**
 * Validate upload(s) (PDF or ZIP only), size limit per file.
 *
 * @param array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
 * @return array<int,array{orig_name:string,ext:string,mime:string,size:int,tmp_name:string}>
 * @throws Exception
 */
function validate_uploads(array $files, int $maxFiles, int $maxBytes, array $allowedExts = ['pdf','zip']): array
{
    $files = array_values(array_filter($files, fn($f) => (int)($f['error'] ?? 0) !== UPLOAD_ERR_NO_FILE));

    if (count($files) === 0) {
        throw new Exception('No files selected.');
    }
    if (count($files) > $maxFiles) {
        throw new Exception('You may upload up to ' . $maxFiles . ' file(s) at a time.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $allowed = [];
    if (in_array('pdf', $allowedExts, true)) {
        $allowed['pdf'] = ['application/pdf'];
    }
    if (in_array('zip', $allowedExts, true)) {
        $allowed['zip'] = [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ];
    }

    $clean = [];
    foreach ($files as $f) {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed (error code ' . $err . ').');
        }

        $size = (int)($f['size'] ?? 0);
        if ($size <= 0) {
            throw new Exception('Empty file upload detected.');
        }
        if ($size > $maxBytes) {
            throw new Exception('File too large. Max is ' . round($maxBytes / (1024 * 1024), 2) . ' MB.');
        }

        $orig = (string)($f['name'] ?? '');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            throw new Exception('Only ' . strtoupper(implode(' or ', $allowedExts)) . ' files are allowed.');
        }

        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new Exception('Invalid upload temp file.');
        }

        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        $ok = false;
        if (!isset($allowed[$ext])) {
            throw new Exception('File extension not allowed.');
        }
        foreach ($allowed[$ext] as $allowedMime) {
            if ($mime === $allowedMime) { $ok = true; break; }
        }
        if (!$ok) {
            if ($ext === 'pdf' && str_contains($mime, 'pdf')) { $ok = true; }
            if ($ext === 'zip' && str_contains($mime, 'zip')) { $ok = true; }
        }
        if (!$ok) {
            throw new Exception('File type not allowed (detected: ' . $mime . ').');
        }

        $clean[] = [
            'orig_name' => $orig,
            'ext'       => $ext,
            'mime'      => $mime,
            'size'      => $size,
            'tmp_name'  => $tmp,
        ];
    }

    return $clean;
}

/**
 * Pick the next available file_index in [1..3] for a given scope.
 * Returns 0 if none available.
 */
function next_available_index(PDO $pdo, string $table, array $where, int $maxIndex = 3): int
{
    $clauses = [];
    $params  = [];
    foreach ($where as $k => $v) {
        $clauses[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    $sql = "SELECT file_index FROM {$table} WHERE " . implode(' AND ', $clauses);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $used = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $used[(int)$r['file_index']] = true;
    }
    for ($i = 1; $i <= $maxIndex; $i++) {
        if (empty($used[$i])) return $i;
    }
    return 0;
}

function safe_download_name(string $name): string
{
    $name = preg_replace('/[^\w\-. ()]+/u', '_', $name);
    return trim((string)$name, '._ ');
}

function random_storage_name(string $ext): string
{
    $bytes = bin2hex(random_bytes(16));
    return $bytes . '.' . $ext;
}

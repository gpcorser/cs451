<?php
/**
 * assignments_logic_load.php
 * Loads assignments + team view model.
 */

function assignments_load_view_model(
    PDO $pdo,
    int $loggedInUserId,
    bool $isAdmin,
    string &$message,
    array &$assignments,
    ?array &$editingAssignment,
    bool &$editingHasTeams,
    string &$formMode,
    string &$formTitle,
    int &$valId,
    string &$valName,
    string &$valDueDate,
    string &$valDescription,
    int &$valTeamSize,
    array &$assignmentFilesByAssignment,
    ?int &$selectedTeamsAssignmentId,
    string &$selectedTeamsAssignmentName,
    array &$teamAssignmentsRows,
    array &$submissionFilesByPersonKey
): void {

    // HTML5 <input type="date"> requires YYYY-MM-DD
    $normalizeDate = static function ($raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    };

    // assignments
    $stmt = $pdo->query('SELECT * FROM assignments ORDER BY date_due ASC, id ASC');
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // assignment PDFs
    $assignmentFilesByAssignment = [];
    if (!empty($assignments)) {
        $ids = array_map(fn($r) => (int)$r['id'], $assignments);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM assignment_files
            WHERE assignment_id IN ($ph)
            ORDER BY assignment_id ASC, file_index ASC
        ");
        $stmt->execute($ids);

        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $aid = (int)$r['assignment_id'];
            if (!isset($assignmentFilesByAssignment[$aid])) $assignmentFilesByAssignment[$aid] = [];
            $assignmentFilesByAssignment[$aid][] = $r;
        }
    }

    // edit
    $editingAssignment = null;
    $editingHasTeams = false;

    if ($isAdmin && isset($_GET['edit'])) {
        $editId = (int)$_GET['edit'];
        if ($editId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id');
            $stmt->execute([':id'=>$editId]);
            $editingAssignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($editingAssignment) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM teamassignments WHERE assignment_id = :aid');
                $stmt->execute([':aid'=>$editId]);
                $editingHasTeams = ((int)$stmt->fetchColumn() > 0);

                if (!isset($assignmentFilesByAssignment[$editId])) {
                    $stmt = $pdo->prepare('SELECT * FROM assignment_files WHERE assignment_id = :aid ORDER BY file_index ASC');
                    $stmt->execute([':aid'=>$editId]);
                    $assignmentFilesByAssignment[$editId] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }
        }
    }

    // form values
    if ($editingAssignment) {
        $formMode  = 'update';
        $formTitle = 'Update Assignment';

        $valId          = (int)($editingAssignment['id'] ?? 0);
        $valName        = (string)($editingAssignment['name'] ?? '');
        $valDescription = (string)($editingAssignment['description'] ?? '');
        $valTeamSize    = (int)($editingAssignment['team_size'] ?? 3);

        // legacy out param (kept)
        $valDueDate = $normalizeDate($editingAssignment['date_due'] ?? '');

        // IMPORTANT: variables the admin form partial expects
        $GLOBALS['valDateAssigned'] = $normalizeDate($editingAssignment['date_assigned'] ?? '');
        $GLOBALS['valDateDue']      = $normalizeDate($editingAssignment['date_due'] ?? '');

    } else {
        $formMode  = 'create';
        $formTitle = 'Create Assignment';

        $valId = 0;
        $valName = '';
        $valDueDate = '';
        $valDescription = '';
        $valTeamSize = 3;

        $GLOBALS['valDateAssigned'] = '';
        $GLOBALS['valDateDue']      = '';
    }

    // teams view
    $selectedTeamsAssignmentId = null;
    $selectedTeamsAssignmentName = '';
    $teamAssignmentsRows = [];
    $submissionFilesByPersonKey = [];

    if (isset($_GET['show_teams'])) {
        $aid = (int)$_GET['show_teams'];
        if ($aid > 0) {
            $selectedTeamsAssignmentId = $aid;

            $stmt = $pdo->prepare('SELECT name FROM assignments WHERE id = :id');
            $stmt->execute([':id'=>$aid]);
            $selectedTeamsAssignmentName = (string)($stmt->fetchColumn() ?: '');

            $stmt = $pdo->prepare('
                SELECT ta.assignment_id, ta.person_id, ta.team_number, p.fname, p.lname, p.email
                FROM teamassignments ta
                JOIN persons p ON ta.person_id = p.id
                WHERE ta.assignment_id = :aid
                ORDER BY ta.team_number ASC, p.lname ASC, p.fname ASC
            ');
            $stmt->execute([':aid'=>$aid]);
            $teamAssignmentsRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare('SELECT * FROM teamassignment_files WHERE assignment_id = :aid ORDER BY person_id ASC, file_index ASC');
            $stmt->execute([':aid'=>$aid]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                $key = ((int)$r['assignment_id']) . ':' . ((int)$r['person_id']);
                if (!isset($submissionFilesByPersonKey[$key])) $submissionFilesByPersonKey[$key] = [];
                $submissionFilesByPersonKey[$key][] = $r;
            }

            if (!isset($assignmentFilesByAssignment[$aid])) {
                $stmt = $pdo->prepare('SELECT * FROM assignment_files WHERE assignment_id = :aid ORDER BY file_index ASC');
                $stmt->execute([':aid'=>$aid]);
                $assignmentFilesByAssignment[$aid] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    }
}

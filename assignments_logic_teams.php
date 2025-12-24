<?php
/**
 * assignments_logic_teams.php
 *
 * Team generation utilities.
 */

/**
 * Generate team assignments for a given assignment using ONLY team sizes of 3 or 4.
 *
 * Rules:
 * - Prefer teams of 3
 * - Use teams of 4 ONLY when necessary to avoid 1- or 2-person leftover
 * - Never generate team sizes other than 3 or 4
 * - Existing teamassignments for this assignment are deleted first
 *
 * Edge cases:
 * - N < 3 -> impossible
 * - N = 5 -> impossible (cannot partition into only 3/4)
 * - N = 4 -> OK (one team of 4)
 */
function generateTeamsForAssignment(PDO $pdo, int $assignmentId): int
{
    $assignmentId = (int)$assignmentId;

    // Ensure assignment exists
    $stmt = $pdo->prepare('SELECT team_size FROM assignments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $assignmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Assignment not found (id=' . $assignmentId . ').');
    }

    // Load all non-admin student IDs
    $stmt = $pdo->query('SELECT id FROM persons WHERE isAdmin = 0 ORDER BY id');
    $studentIds = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $studentIds[] = (int)$r['id'];
    }

    $numStudents = count($studentIds);

    if ($numStudents < 3) {
        throw new Exception('Not enough students to form teams of 3 (or 4).');
    }
    if ($numStudents === 5) {
        throw new Exception('Cannot form teams of only size 3 or 4 with 5 students.');
    }

    // Fisher–Yates shuffle
    for ($i = $numStudents - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $studentIds[$i];
        $studentIds[$i] = $studentIds[$j];
        $studentIds[$j] = $tmp;
    }

    // Compute 3/4 split
    $mod = $numStudents % 3;
    $numTeamsOf4 = 0;
    if ($mod === 1) {
        $numTeamsOf4 = 1;
    } elseif ($mod === 2) {
        if ($numStudents < 8) {
            throw new Exception('Cannot form teams of only size 3 or 4 with ' . $numStudents . ' students.');
        }
        $numTeamsOf4 = 2;
    }

    $remainingAfter4s = $numStudents - (4 * $numTeamsOf4);
    if ($remainingAfter4s < 0 || ($remainingAfter4s % 3) !== 0) {
        throw new Exception('Internal error computing 3/4 team split for N=' . $numStudents);
    }

    $numTeamsOf3 = (int)($remainingAfter4s / 3);
    $teamSizes = array_merge(array_fill(0, $numTeamsOf4, 4), array_fill(0, $numTeamsOf3, 3));
    shuffle($teamSizes);

    $numTeams = count($teamSizes);

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM teamassignments WHERE assignment_id = :assignment_id');
        $del->execute([':assignment_id' => $assignmentId]);

        $ins = $pdo->prepare('INSERT INTO teamassignments (assignment_id, team_number, person_id) VALUES (:aid, :tn, :pid)');
        $insertCount = 0;
        $index = 0;

        for ($teamNumber = 1; $teamNumber <= $numTeams; $teamNumber++) {
            $thisTeamSize = (int)$teamSizes[$teamNumber - 1];
            for ($k = 0; $k < $thisTeamSize; $k++) {
                if ($index >= $numStudents) {
                    break;
                }
                $pid = $studentIds[$index++];
                $ins->execute([':aid' => $assignmentId, ':tn' => $teamNumber, ':pid' => $pid]);
                $insertCount++;
            }
        }

        $pdo->commit();
        return $insertCount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

<?php
/**
 * assignments_logic.php (orchestrator) - v2
 *
 * Expects these variables to be defined by assignments.php:
 *   - $pdo (PDO)
 *   - $loggedInUserId (int)
 *   - $userEmail (string)
 *   - $isAdmin (bool)
 *   - $message (string)
 *
 * Produces variables consumed by assignments.php + HTML partials.
 */

require_once __DIR__ . '/assignments_logic_teams.php';
require_once __DIR__ . '/assignments_logic_actions.php';
require_once __DIR__ . '/assignments_logic_load.php';

// Defaults (prevents null-by-ref TypeErrors)
$assignments                 = [];
$assignmentFilesByAssignment = [];
$editingAssignment            = null;
$editingHasTeams              = false;

$formMode  = 'create';
$formTitle = 'Create Assignment';

$valId          = 0;
$valName        = '';
$valDueDate     = '';
$valDescription = '';
$valTeamSize    = 3;

$selectedTeamsAssignmentId   = null;
$selectedTeamsAssignmentName = '';
$teamAssignmentsRows         = [];
$submissionFilesByPersonKey  = [];

// Handle POST actions (exactly one action per request)
assignments_handle_post($pdo, $loggedInUserId, $isAdmin, $message);

// Load view model
assignments_load_view_model(
    $pdo,
    $loggedInUserId,
    $isAdmin,
    $message,
    $assignments,
    $editingAssignment,
    $editingHasTeams,
    $formMode,
    $formTitle,
    $valId,
    $valName,
    $valDueDate,
    $valDescription,
    $valTeamSize,
    $assignmentFilesByAssignment,
    $selectedTeamsAssignmentId,
    $selectedTeamsAssignmentName,
    $teamAssignmentsRows,
    $submissionFilesByPersonKey
);

<?php
session_start();
require '../database/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$userEmail      = $_SESSION['user_email'] ?? 'unknown';
$isAdmin        = !empty($_SESSION['is_admin']);
$message        = '';

// Load DB + business logic. This file sets up all variables used by the HTML partials.
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
            <a href="statusReport.php" class="btn btn-outline-modern btn-sm">Status Report</a>
            <a href="login.php" class="btn btn-outline-modern btn-sm">Back to Login</a>
        </div>
    </div>

    <!-- Message -->
    <?php if ($message !== ''): ?>
        <div class="alert alert-info alert-modern mb-3">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php
    // Admin create/update form
    require __DIR__ . '/assignments_html_adminform.php';

    // Assignment list
    require __DIR__ . '/assignments_html_assignmentlist.php';

    // Team assignments view (if selected)
    require __DIR__ . '/assignments_html_teamassignments.php';
    ?>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    crossorigin="anonymous"></script>

</body>
</html>

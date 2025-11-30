<?php
session_start();
require __DIR__ . '/config.php'; // brings in $pdo

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$message = '';
$success = '';

// ----- FETCH CURRENT USER DATA -----
$stmt = $pdo->prepare('SELECT fname, lname, mobile, email, pwdhash, pwdsalt FROM persons WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    // If somehow the session points to a non-existing user
    $message = 'User not found. Please log in again.';
}

// Initialize form values from DB
$fname  = $person['fname']  ?? '';
$lname  = $person['lname']  ?? '';
$mobile = $person['mobile'] ?? '';
$email  = $person['email']  ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get submitted values
    $fname           = trim($_POST['fname'] ?? '');
    $lname           = trim($_POST['lname'] ?? '');
    $mobile          = trim($_POST['mobile'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($email === '') {
        $message = 'Email cannot be empty.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif ($newPassword !== '' || $confirmPassword !== '') {
        // If either password field is non-empty, require both and they must match
        if ($newPassword === '' || $confirmPassword === '') {
            $message = 'Please fill in both password fields.';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New password and confirmation do not match.';
        }
    }

    if ($message === '') {
        // Check for email uniqueness (except for this user)
        $stmt = $pdo->prepare('SELECT id FROM persons WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute([
            ':email' => $email,
            ':id'    => $userId,
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            $message = 'That email is already in use by another account.';
        } else {
            // Decide whether to change password
            if ($newPassword !== '') {
                $salt    = bin2hex(random_bytes(16));
                $pwdhash = hash('sha256', $salt . $newPassword);
            } else {
                // Keep existing hash/salt
                $salt    = $person['pwdsalt'];
                $pwdhash = $person['pwdhash'];
            }

            // Update this user's own row
            $stmt = $pdo->prepare('
                UPDATE persons
                SET fname = :fname,
                    lname = :lname,
                    mobile = :mobile,
                    email = :email,
                    pwdhash = :pwdhash,
                    pwdsalt = :pwdsalt
                WHERE id = :id
                LIMIT 1
            ');
            $stmt->execute([
                ':fname'   => $fname,
                ':lname'   => $lname,
                ':mobile'  => $mobile === '' ? null : $mobile,
                ':email'   => $email,
                ':pwdhash' => $pwdhash,
                ':pwdsalt' => $salt,
                ':id'      => $userId,
            ]);

            // Update session email if changed
            $_SESSION['user_email'] = $email;

            // Refresh $person for future use if needed
            $person['fname']   = $fname;
            $person['lname']   = $lname;
            $person['mobile']  = $mobile;
            $person['email']   = $email;
            $person['pwdhash'] = $pwdhash;
            $person['pwdsalt'] = $salt;

            $success = 'Your information has been updated.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CS-451: Update My Info</title>
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

    <!-- Your custom styles -->
    <link rel="stylesheet" href="cs451.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="d-flex align-items-center mb-3">
            <div class="me-3">
                <img src="cs451-icon.png" alt="CS-451 Peer Review Icon" class="brand-icon">
            </div>
            <div>
                <div class="brand-title">CS-451: Peer Review App</div>
                <h1 class="app-title h4 mb-1">Update My Info</h1>
                <p class="app-subtitle mb-0">
                    Edit your profile and change your app password.
                </p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-danger alert-modern mt-2" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success alert-modern mt-2" role="alert">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="updatePerson.php" class="mt-3">
            <div class="mb-3">
                <label for="fname" class="form-label">First Name</label>
                <input
                    type="text"
                    class="form-control"
                    id="fname"
                    name="fname"
                    value="<?php echo htmlspecialchars($fname); ?>"
                >
            </div>

            <div class="mb-3">
                <label for="lname" class="form-label">Last Name</label>
                <input
                    type="text"
                    class="form-control"
                    id="lname"
                    name="lname"
                    value="<?php echo htmlspecialchars($lname); ?>"
                >
            </div>

            <div class="mb-3">
                <label for="mobile" class="form-label">Mobile (optional)</label>
                <input
                    type="text"
                    class="form-control"
                    id="mobile"
                    name="mobile"
                    value="<?php echo htmlspecialchars($mobile); ?>"
                >
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">SVSU Email</label>
                <input
                    type="email"
                    class="form-control"
                    id="email"
                    name="email"
                    value="<?php echo htmlspecialchars($email); ?>"
                    required
                >
            </div>

            <hr class="my-3">

            <p class="mb-2"><strong>Change Password (optional)</strong></p>
            <p class="helper-text mb-2">
                Leave these fields blank if you do not want to change your password.
            </p>

            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input
                    type="password"
                    class="form-control"
                    id="new_password"
                    name="new_password"
                    placeholder="Enter a new app password"
                >
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input
                    type="password"
                    class="form-control"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Re-enter the new password"
                >
            </div>

            <div class="d-flex justify-content-between mt-3">
                <a href="statusReport.php" class="btn btn-outline-modern">
                    &larr; Back to Status Report
                </a>
                <button type="submit" class="btn btn-modern">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Bootstrap JS -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

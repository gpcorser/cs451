<?php
session_start();
require __DIR__ . '/config.php'; // brings in $pdo

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $message = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        if ($action === 'login') {
            // ----- LOGIN FLOW -----
            $stmt = $pdo->prepare('SELECT id, pwdhash, pwdsalt, isAdmin FROM persons WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                $salt      = $user['pwdsalt'];
                $stored    = $user['pwdhash'];
                $candidate = hash('sha256', $salt . $password);

                if (hash_equals($stored, $candidate)) {
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['is_admin']   = (int)$user['isAdmin'];

                    header('Location: statusReport.php');
                    exit;
                }
            }

            $message = 'Invalid email or password.';

        } elseif ($action === 'join') {
            // ----- JOIN / REGISTER FLOW -----
            $stmt = $pdo->prepare('SELECT id FROM persons WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch();

            if ($existing) {
                $message = 'An account with that email already exists. Please log in.';
            } else {
                $salt    = bin2hex(random_bytes(16));
                $pwdhash = hash('sha256', $salt . $password);

                $isAdmin = ($email === 'gpcorser@svsu.edu') ? 1 : 0;

                $stmt = $pdo->prepare('
                    INSERT INTO persons (fname, lname, mobile, email, pwdhash, pwdsalt, isAdmin)
                    VALUES ("", "", "", :email, :pwdhash, :pwdsalt, :isAdmin)
                ');
                $stmt->execute([
                    ':email'   => $email,
                    ':pwdhash' => $pwdhash,
                    ':pwdsalt' => $salt,
                    ':isAdmin' => $isAdmin,
                ]);

                $newId = $pdo->lastInsertId();

                $_SESSION['user_id']    = $newId;
                $_SESSION['user_email'] = $email;
                $_SESSION['is_admin']   = $isAdmin;

                header('Location: statusReport.php');
                exit;
            }
        } else {
            $message = 'Unknown action.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CS-451: Peer Grading App</title>
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
                <!-- Update src if your icon file has a different name -->
                <img src="cs451-icon.png" alt="CS-451 Peer Grading App Icon" class="brand-icon">
            </div>
            <div>
                <div class="brand-title">CS-451: Peer Grading App</div>
                <h1 class="app-title h4 mb-1">Login</h1>
                <p class="app-subtitle mb-0">
                    Ratings and comments by and of fellow students.
                </p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-danger alert-modern mt-2" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" class="mt-3">
            <div class="mb-3">
                <label for="email" class="form-label">SVSU Email</label>
                <input
                    type="email"
                    class="form-control"
                    id="email"
                    name="email"
                    placeholder="you@svsu.edu"
                    required
                >
            </div>

            <div class="mb-1">
                <label for="password" class="form-label">Password</label>
                <input
                    type="password"
                    class="form-control"
                    id="password"
                    name="password"
                    placeholder="Enter your app password"
                    required
                >
            </div>

            <div class="helper-text">
                Please do not use your SVSU password for this app.
            </div>

            <div class="btn-group-flex">
                <button type="submit" name="action" value="login" class="btn btn-modern w-50">
                    <span>Login</span>
                </button>
                <button type="submit" name="action" value="join" class="btn btn-outline-modern w-50">
                    <span>Join</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Bootstrap JS (for accordion etc.) -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

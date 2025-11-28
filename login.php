<?php
session_start();
require __DIR__ . '/config.php'; // brings in $pdo

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $email = trim($_POST['email'] ?? '');
    }
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
                    // success: set session and redirect
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['is_admin']  = (int)$user['isAdmin'];

                    header('Location: temp.php');
                    exit;
                }
            }

            $message = 'Invalid email or password.';

        } elseif ($action === 'join') {
            // ----- JOIN / REGISTER FLOW -----
            // Check whether this email already exists
            $stmt = $pdo->prepare('SELECT id FROM persons WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch();

            if ($existing) {
                $message = 'An account with that email already exists. Please log in.';
            } else {
                // create salt + hash (simple example; for production use password_hash)
                $salt    = bin2hex(random_bytes(16));
                $pwdhash = hash('sha256', $salt . $password);

                // make you the admin
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

                // log them in immediately
                $_SESSION['user_id']   = $newId;
                $_SESSION['user_email'] = $email;
                $_SESSION['is_admin']  = $isAdmin;

                header('Location: temp.php');
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
    <title>Login / Join</title>
</head>
<body>
    <h1>Login / Join</h1>

    <?php if ($message !== ''): ?>
        <p style="color:red;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label>
            Email:
            <input type="email" name="email" required>
        </label>
        <br><br>

        <label>
            Password:
            <input type="password" name="password" required>
        </label>
        <br><br>

        <button type="submit" name="action" value="login">Login</button>
        <button type="submit" name="action" value="join">Join</button>
    </form>
</body>
</html>

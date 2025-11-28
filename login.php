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
                    // success: set session and redirect
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['is_admin']   = (int)$user['isAdmin'];

                    header('Location: temp.php');
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
    <title>CS-451: Peer Review App</title>

    <!-- Bootstrap 5 CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <!-- Optional Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap"
        rel="stylesheet"
    >

    <style>
        :root {
            /* peachy gradient */
            --bg-gradient: linear-gradient(135deg, #ff9a8b 0%, #fecf71 100%);
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-gradient);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
            animation: fadeInBody 0.7s ease-out;
        }

        @keyframes fadeInBody {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 1rem;
            box-shadow:
                0 20px 45px rgba(0, 0, 0, 0.32),
                0 0 0 1px rgba(251, 146, 60, 0.25);
            max-width: 460px;
            width: 100%;
            padding: 2.25rem 2.5rem;
            backdrop-filter: blur(18px);
            animation: slideUpCard 0.5s ease-out;
        }

        @keyframes slideUpCard {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .brand-title {
            font-weight: 600;
            letter-spacing: 0.04em;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: #fb923c; /* peach accent */
        }

        .app-title {
            font-weight: 600;
            margin-top: 0.35rem;
            margin-bottom: 0.4rem;
            color: #111827;
        }

        .app-subtitle {
            font-size: 0.9rem;
            color: #4b5563;
        }

        .brand-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            object-fit: cover;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.28);
        }

        .form-label {
            font-size: 0.9rem;
            color: #111827;
        }

        .form-control {
            background-color: #f9fafb;
            border-color: #d1d5db;
            color: #111827;
            font-size: 0.95rem;
        }

        .form-control:focus {
            background-color: #ffffff;
            border-color: #fb923c;
            color: #111827;
            box-shadow: 0 0 0 0.15rem rgba(251, 146, 60, 0.45);
        }

        .btn-modern {
            border-radius: 999px;
            padding: 0.55rem 1.3rem;
            font-size: 0.93rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition:
                transform 0.12s ease-out,
                box-shadow 0.12s ease-out,
                background-position 0.25s ease-out;
            background-image: linear-gradient(135deg, #fb923c 0%, #f97316 50%, #fec89a 100%);
            background-size: 140% 140%;
            border: none;
            color: #111827;
        }

        .btn-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25);
            background-position: 100% 0%;
        }

        .btn-outline-modern {
            border-radius: 999px;
            padding: 0.55rem 1.3rem;
            font-size: 0.93rem;
            font-weight: 500;
            border: 1px solid rgba(249, 115, 22, 0.7);
            color: #f97316;
            background: transparent;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition:
                background-color 0.12s ease-out,
                color 0.12s ease-out,
                border-color 0.12s ease-out,
                transform 0.12s ease-out;
        }

        .btn-outline-modern:hover {
            background-color: rgba(254, 243, 199, 0.9);
            color: #c2410c;
            border-color: #ea580c;
            transform: translateY(-1px);
        }

        .btn-group-flex {
            display: flex;
            gap: 0.5rem;
            justify-content: space-between;
            margin-top: 0.75rem;
        }

        .alert-modern {
            border-radius: 999px;
            padding: 0.6rem 0.9rem;
            font-size: 0.85rem;
        }

        .helper-text {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.4rem;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="d-flex align-items-center mb-3">
            <div class="me-3">
                <!-- Update src to match your actual icon file name/path -->
                <img src="cs451-icon.png" alt="CS-451 Peer Review Icon" class="brand-icon">
            </div>
            <div>
                <div class="brand-title">CS-451: Peer Review App</div>
                <h1 class="app-title h4 mb-1">Login</h1>
                <p class="app-subtitle mb-0">
                    Write and read peer reviews, ratings and comments.
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

    <!-- Bootstrap JS (optional, for future modals/toasts/etc.) -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>
</body>
</html>

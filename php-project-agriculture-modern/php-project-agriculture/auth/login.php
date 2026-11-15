<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$database = new Database();
$pdo = $database->connect();
$obj = new Database_access($pdo);

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = "Please enter your username and password.";
    } else {
        $user = $obj->getByUsername($username);
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role_name'];
            header("Location: ../index.php");
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
        
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f3a29">
    <title>Login | Agriculture Information System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/css/app.css">
    <style>
        /* the page has its own show/hide eye, so hide the one the browser (Edge) adds inside password fields */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none; }
    </style>
</head>
<body>
<div class="auth">
    <aside class="auth-art" aria-hidden="true">
        <div class="furrows"></div>
        <div class="sun"></div>
        <div class="brand" style="padding:0">
            <span class="brand-mark">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M12 21v-9"/><path d="M12 12c0-4 2.6-6.5 7.5-6.5 0 4.2-2.8 6.5-7.5 6.5z"/><path d="M12 15.5c0-3-2-5-6.5-5 0 3.2 2.2 5 6.5 5z"/></svg>
            </span>
            <span class="brand-name">AIS</span>
        </div>
        <div class="stagger">
            <h2 class="auth-headline">From first seed to final harvest.</h2>
            <p class="auth-copy">Farmers, farms, crops, plantings and harvests, all in one place.</p>
        </div>
        <span></span>
    </aside>

    <main class="auth-form-side">
        <div class="auth-card stagger">
            <div>
                <h1 class="font-display" style="font-size:2rem;font-weight:700;margin:0;line-height:1.1">Welcome back</h1>
                <p style="color:var(--muted);margin:.4rem 0 1.5rem">Please log in to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20" style="flex:none"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" style="display:grid;gap:1rem">
                <div>
                    <label class="field-label" for="username">Username</label>
                    <input id="username" type="text" name="username" autocomplete="username" autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input <?= $error ? 'is-invalid' : '' ?>" required>
                </div>
                <div>
                    <label class="field-label" for="password">Password</label>
                    <div class="pw-wrap">
                        <input id="password" type="password" name="password" autocomplete="current-password" class="input <?= $error ? 'is-invalid' : '' ?>" required>
                        <button type="button" id="pw-toggle" class="pw-toggle" aria-label="Show password">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="height:2.9rem;width:100%">Login</button>
            </form>

        </div>
    </main>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>
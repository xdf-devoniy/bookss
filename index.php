<?php
session_start();

const ACCOUNT_NAME = 'Oxford Book Management';
const ACCESS_PASSWORD = 'oxford0624';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['authenticated'])) {
    header('Location: konto.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (hash_equals(ACCESS_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        header('Location: konto.php');
        exit;
    }

    $error = 'Parol notoʼgʼri. Qayta urinib koʼring.';
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oxford Book Management — Kirish</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="min-h-screen flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-md space-y-8 rounded-3xl border border-slate-200 bg-white p-10 shadow-xl">
            <div class="space-y-3 text-center">
                <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Boshqaruv paneli</p>
                <h1 class="text-3xl font-bold text-slate-900"><?= htmlspecialchars(ACCOUNT_NAME) ?></h1>
                <p class="text-sm text-slate-600">Davom etish uchun maxfiy parolni kiriting.</p>
            </div>

            <?php if ($error): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-6">
                <div class="space-y-2">
                    <label for="password" class="block text-sm font-medium text-slate-700">Kirish paroli</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm shadow-sm transition focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-300"
                        placeholder="Parolni kiriting"
                    >
                </div>
                <button type="submit" class="w-full rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg transition hover:bg-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-300">
                    Kirish
                </button>
            </form>
        </div>
    </div>
</body>
</html>

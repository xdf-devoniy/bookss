<?php
$accounts = ['KIDS', 'DAPA'];
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitoblar boshqaruvi</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen flex flex-col items-center justify-center p-6">
        <div class="max-w-xl w-full bg-white shadow-lg rounded-2xl border border-slate-200 p-8 space-y-6">
            <div class="text-center space-y-2">
                <h1 class="text-3xl font-bold text-slate-900">Kitoblar va inventar boshqaruvi</h1>
                <p class="text-slate-600">Hisobni tanlang va o'z kitoblaringizni boshqaring.</p>
            </div>
            <div class="grid grid-cols-1 gap-4">
                <?php foreach ($accounts as $account): ?>
                    <a href="konto.php?account=<?= urlencode($account) ?>" class="block rounded-xl border border-slate-200 bg-slate-100 hover:bg-slate-200 transition p-5 text-center">
                        <h2 class="text-2xl font-semibold text-slate-800"><?= htmlspecialchars($account) ?></h2>
                        <p class="text-slate-600 mt-2">Inventar, sotuvlar va hisobotlar</p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>

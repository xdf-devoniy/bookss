<?php
require __DIR__ . '/db.php';

$accounts = ['KIDS', 'DAPA'];
$account = $_GET['account'] ?? 'KIDS';
if (!in_array($account, $accounts, true)) {
    header('Location: index.php');
    exit;
}

$errors = [];

$today = new DateTime('today');
$defaultStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
$defaultEnd = $today->format('Y-m-d');

$startInput = $_GET['start_date'] ?? $defaultStart;
$endInput = $_GET['end_date'] ?? $defaultEnd;

$startDate = DateTime::createFromFormat('Y-m-d', $startInput) ?: DateTime::createFromFormat('Y-m-d', $defaultStart);
$endDate = DateTime::createFromFormat('Y-m-d', $endInput) ?: DateTime::createFromFormat('Y-m-d', $defaultEnd);

if (!$startDate || !$endDate) {
    $errors[] = 'Sana formatini tekshiring.';
    $startDate = DateTime::createFromFormat('Y-m-d', $defaultStart);
    $endDate = DateTime::createFromFormat('Y-m-d', $defaultEnd);
}

if ($startDate > $endDate) {
    $errors[] = 'Boshlanish sanasi tugash sanasidan kech boʼlmasligi kerak.';
    [$startDate, $endDate] = [$endDate, $startDate];
}

$rangeStart = $startDate->format('Y-m-d') . ' 00:00:00';
$rangeEnd = $endDate->format('Y-m-d') . ' 23:59:59';

$rangeSummaryStmt = $pdo->prepare('SELECT SUM(quantity) AS quantity, SUM(total_cost) AS cost, SUM(total_revenue) AS revenue, SUM(profit) AS profit FROM sales WHERE account = ? AND sold_at BETWEEN ? AND ?');
$rangeSummaryStmt->execute([$account, $rangeStart, $rangeEnd]);
$rangeSummary = $rangeSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['quantity' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];

$rangeSalesStmt = $pdo->prepare('SELECT s.*, b.title FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ? AND s.sold_at BETWEEN ? AND ? ORDER BY s.sold_at DESC');
$rangeSalesStmt->execute([$account, $rangeStart, $rangeEnd]);
$rangeSales = $rangeSalesStmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyStmt = $pdo->prepare("SELECT strftime('%Y-%m', sold_at) AS period, SUM(quantity) AS quantity, SUM(total_revenue) AS revenue, SUM(profit) AS profit, SUM(total_cost) AS cost FROM sales WHERE account = ? GROUP BY period ORDER BY period DESC");
$monthlyStmt->execute([$account]);
$monthlyRows = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($account) ?> hisobi &mdash; Hisobotlar</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen p-6 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4 bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($account) ?> hisobidagi hisobotlar</h1>
                <p class="text-slate-600">Oylik va sanalar oraligʼidagi sotuvlarni tahlil qiling.</p>
            </div>
            <a href="konto.php?account=<?= urlencode($account) ?>" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 transition">&larr; Inventarga qaytish</a>
        </header>

        <?php if ($errors): ?>
            <div class="bg-amber-100 border border-amber-300 text-amber-900 rounded-xl p-4">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">Sana oraligʼi boʼyicha tahlil</h2>
                    <p class="text-sm text-slate-600">Kerakli sanalarni kiriting va sotuvlarni filtrlang.</p>
                </div>
            </div>
            <form method="get" class="grid md:grid-cols-[repeat(3,minmax(0,1fr))_auto] gap-4">
                <input type="hidden" name="account" value="<?= htmlspecialchars($account) ?>">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Boshlanish sanasi</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate->format('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Tugash sanasi</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate->format('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-indigo-500 text-white hover:bg-indigo-600 transition">Hisobotni koʼrish</button>
                </div>
                <div class="flex items-end">
                    <a href="reports.php?account=<?= urlencode($account) ?>" class="w-full px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 transition text-center">Tozalash</a>
                </div>
            </form>

            <dl class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="p-4 bg-slate-100 rounded-2xl">
                    <dt class="text-sm text-slate-600">Sotilgan nusxalar</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= (int)$rangeSummary['quantity'] ?></dd>
                </div>
                <div class="p-4 bg-slate-100 rounded-2xl">
                    <dt class="text-sm text-slate-600">Tushum</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$rangeSummary['revenue'], 2, '.', ' ') ?> soʼm</dd>
                </div>
                <div class="p-4 bg-slate-100 rounded-2xl">
                    <dt class="text-sm text-slate-600">Xarid qiymati</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$rangeSummary['cost'], 2, '.', ' ') ?> soʼm</dd>
                </div>
                <div class="p-4 bg-slate-100 rounded-2xl">
                    <dt class="text-sm text-slate-600">Foyda</dt>
                    <dd class="text-2xl font-semibold text-emerald-600"><?= number_format((float)$rangeSummary['profit'], 2, '.', ' ') ?> soʼm</dd>
                </div>
            </dl>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Sana</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kitob</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Miqdor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Tushum</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Foyda</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$rangeSales): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Tanlangan davrda sotuvlar mavjud emas.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rangeSales as $sale): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm text-slate-600"><?= htmlspecialchars(date('Y-m-d', strtotime($sale['sold_at']))) ?></td>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($sale['title'] ?? 'Kitob oʼchirib tashlangan') ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$sale['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$sale['total_revenue'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-600 font-semibold"><?= number_format((float)$sale['profit'], 2, '.', ' ') ?> soʼm</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Oylik hisobot</h2>
                <p class="text-sm text-slate-600">Har oy uchun sotilgan nusxalar, tushum va foyda.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Oy</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Sotilgan nusxalar</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Tushum</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Xarid qiymati</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Foyda</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$monthlyRows): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Hozircha oylik hisobotlar yoʼq.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($monthlyRows as $row): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($row['period']) ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$row['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$row['revenue'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$row['cost'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-600 font-semibold"><?= number_format((float)$row['profit'], 2, '.', ' ') ?> soʼm</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>

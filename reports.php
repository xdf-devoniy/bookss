<?php
session_start();

if (empty($_SESSION['authenticated'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/db.php';

const ACCOUNT_NAME = 'Oxford Book Management';
const ACCOUNT_KEY = 'OXFORD';

$account = ACCOUNT_KEY;
$accountLabel = ACCOUNT_NAME;

$errors = [];

$paymentLabels = [
    'cash' => 'Naqd',
    'click' => 'Click (karta)',
];

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

$rangeSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, SUM(quantity) AS quantity, SUM(total_cost) AS cost, SUM(total_revenue) AS revenue, SUM(profit) AS profit FROM sales WHERE account = ? AND sold_at BETWEEN ? AND ?');
$rangeSummaryStmt->execute([$account, $rangeStart, $rangeEnd]);
$rangeSummary = $rangeSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'quantity' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];

$rangeSalesStmt = $pdo->prepare('SELECT s.*, b.title, COALESCE(b.quantity, 0) AS current_quantity FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ? AND s.sold_at BETWEEN ? AND ? ORDER BY s.sold_at DESC');
$rangeSalesStmt->execute([$account, $rangeStart, $rangeEnd]);
$rangeSales = $rangeSalesStmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyStmt = $pdo->prepare("SELECT strftime('%Y-%m', sold_at) AS period, SUM(quantity) AS quantity, SUM(total_revenue) AS revenue, SUM(profit) AS profit, SUM(total_cost) AS cost FROM sales WHERE account = ? GROUP BY period ORDER BY period ASC");
$monthlyStmt->execute([$account]);
$monthlyRows = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

$rangePaymentBreakdown = [];
foreach ($paymentLabels as $key => $label) {
    $rangePaymentBreakdown[$key] = ['quantity' => 0, 'revenue' => 0.0];
}
$rangePaymentStmt = $pdo->prepare('SELECT payment_method, SUM(quantity) AS quantity, SUM(total_revenue) AS revenue FROM sales WHERE account = ? AND sold_at BETWEEN ? AND ? GROUP BY payment_method');
$rangePaymentStmt->execute([$account, $rangeStart, $rangeEnd]);
foreach ($rangePaymentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $method = $row['payment_method'] ?? 'cash';
    if (!isset($rangePaymentBreakdown[$method])) {
        $rangePaymentBreakdown[$method] = ['quantity' => 0, 'revenue' => 0.0];
    }
    $rangePaymentBreakdown[$method]['quantity'] = (int) ($row['quantity'] ?? 0);
    $rangePaymentBreakdown[$method]['revenue'] = (float) ($row['revenue'] ?? 0);
}

$rangeTopBooksStmt = $pdo->prepare('SELECT b.title, SUM(s.quantity) AS sold_quantity, COALESCE(b.quantity, 0) AS remaining_quantity FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ? AND s.sold_at BETWEEN ? AND ? GROUP BY s.book_id, b.title, b.quantity ORDER BY sold_quantity DESC LIMIT 5');
$rangeTopBooksStmt->execute([$account, $rangeStart, $rangeEnd]);
$rangeTopBooks = $rangeTopBooksStmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyChartData = [
    'labels' => [],
    'revenue' => [],
    'profit' => [],
];
foreach ($monthlyRows as $row) {
    $monthlyChartData['labels'][] = $row['period'];
    $monthlyChartData['revenue'][] = (float) ($row['revenue'] ?? 0);
    $monthlyChartData['profit'][] = (float) ($row['profit'] ?? 0);
}

$paymentChartData = [
    'labels' => [],
    'revenue' => [],
];
foreach ($paymentLabels as $key => $label) {
    $paymentChartData['labels'][] = $label;
    $paymentChartData['revenue'][] = (float) ($rangePaymentBreakdown[$key]['revenue'] ?? 0);
}

function formatCurrency(float $amount): string
{
    return number_format($amount, 2, '.', ' ');
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($accountLabel) ?> — Hisobotlar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <header class="bg-white border-b border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500">Hisob</p>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($accountLabel) ?> — hisobotlar</h1>
                <p class="text-sm text-slate-600">Oylik tendensiyalar, toʼlov usullari va eng koʼp sotilgan kitoblarni kuzating.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="debtors.php" class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Qarzdorlar</a>
                <a href="konto.php" class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">&larr; Inventarga qaytish</a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <?php if ($errors): ?>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Sana oraligʼi boʼyicha tahlil</h2>
                    <p class="text-sm text-slate-600">Quyidagi davr: <span class="font-semibold text-slate-900"><?= htmlspecialchars($startDate->format('Y-m-d')) ?></span> &rarr; <span class="font-semibold text-slate-900"><?= htmlspecialchars($endDate->format('Y-m-d')) ?></span></p>
                </div>
            </div>
            <form method="get" class="grid gap-4 md:grid-cols-[repeat(2,minmax(0,1fr))_auto_auto]">
                <div>
                    <label class="block text-sm font-medium text-slate-600">Boshlanish sanasi</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate->format('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Tugash sanasi</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate->format('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Hisobotni koʼrish</button>
                </div>
                <div class="flex items-end">
                    <a href="reports.php" class="w-full rounded-xl border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 transition hover:bg-slate-100">Tozalash</a>
                </div>
            </form>

            <dl class="grid gap-4 md:grid-cols-5 text-sm">
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Sotuvlar soni</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($rangeSummary['sales_count'] ?? 0) ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Sotilgan nusxalar</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) ($rangeSummary['quantity'] ?? 0) ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Tushum</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-900"><?= formatCurrency((float) ($rangeSummary['revenue'] ?? 0)) ?> soʼm</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Xarid qiymati</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-900"><?= formatCurrency((float) ($rangeSummary['cost'] ?? 0)) ?> soʼm</dd>
                </div>
                <div class="rounded-xl bg-emerald-50 p-4">
                    <dt class="text-emerald-600">Foyda</dt>
                    <dd class="mt-1 text-2xl font-semibold text-emerald-600"><?= formatCurrency((float) ($rangeSummary['profit'] ?? 0)) ?> soʼm</dd>
                </div>
            </dl>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-800">Oylik tushum va foyda</h3>
                        <span class="text-xs text-slate-500">Oxirgi <?= count($monthlyChartData['labels']) ?> oy</span>
                    </div>
                    <div class="mt-4">
                        <div class="relative h-64">
                            <canvas id="monthlyChart" class="absolute inset-0"></canvas>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-800">Toʼlovlar taqsimoti</h3>
                    <div class="mt-4 aspect-square">
                        <canvas id="paymentChart" class="h-full w-full"></canvas>
                    </div>
                    <ul class="mt-4 space-y-2 text-sm">
                        <?php foreach ($paymentLabels as $key => $label): ?>
                            <li class="flex items-center justify-between">
                                <span class="text-slate-600"><?= htmlspecialchars($label) ?></span>
                                <span class="text-slate-900 font-semibold"><?= (int) ($rangePaymentBreakdown[$key]['quantity'] ?? 0) ?> ta • <?= formatCurrency((float) ($rangePaymentBreakdown[$key]['revenue'] ?? 0)) ?> soʼm</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Tanlangan davr sotuvlari</h2>
                    <p class="text-sm text-slate-600">Har bir sotuv boʼyicha toʼlov usuli va izohlarni koʼring.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">Jami: <?= count($rangeSales) ?> ta qayd</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Sana</th>
                            <th class="px-4 py-3 text-left">Kitob</th>
                            <th class="px-4 py-3 text-right">Miqdor</th>
                            <th class="px-4 py-3 text-left">Toʼlov</th>
                            <th class="px-4 py-3 text-right">Tushum</th>
                            <th class="px-4 py-3 text-right">Foyda</th>
                            <th class="px-4 py-3 text-left">Izoh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$rangeSales): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-slate-500">Tanlangan davrda sotuvlar mavjud emas.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rangeSales as $sale): ?>
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-600">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars(date('Y-m-d', strtotime($sale['sold_at']))) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars(date('H:i', strtotime($sale['sold_at']))) ?></div>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($sale['title'] ?? 'Kitob oʼchirib tashlangan') ?></td>
                                <td class="px-4 py-3 text-right text-slate-600"><?= (int) $sale['quantity'] ?></td>
                                <td class="px-4 py-3 text-left">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold <?= ($sale['payment_method'] ?? 'cash') === 'cash' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700' ?>">
                                        <?= htmlspecialchars($paymentLabels[$sale['payment_method'] ?? 'cash'] ?? $sale['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600"><?= formatCurrency((float) $sale['total_revenue']) ?> soʼm</td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600"><?= formatCurrency((float) $sale['profit']) ?> soʼm</td>
                                <td class="px-4 py-3 text-slate-600">
                                    <?php if (!empty($sale['note'])): ?>
                                        <span class="block text-xs uppercase text-slate-400">Izoh</span>
                                        <span><?= nl2br(htmlspecialchars($sale['note'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Izoh qoʼyilmagan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[2fr_3fr]">
            <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Eng koʼp sotilgan kitoblar</h2>
                <?php if (!$rangeTopBooks): ?>
                    <p class="text-sm text-slate-500">Tanlangan davrda yetarli maʼlumot yoʼq.</p>
                <?php else: ?>
                    <ul class="space-y-3 text-sm">
                        <?php foreach ($rangeTopBooks as $row): ?>
                            <li class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3">
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($row['title'] ?? 'Kitob') ?></p>
                                    <p class="text-xs text-slate-500">Omborda: <?= (int) ($row['remaining_quantity'] ?? 0) ?> ta</p>
                                </div>
                                <span class="text-sm font-semibold text-emerald-600">Sotilgan: <?= (int) ($row['sold_quantity'] ?? 0) ?> ta</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Oylik hisobot</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left">Oy</th>
                                <th class="px-4 py-3 text-right">Sotilgan</th>
                                <th class="px-4 py-3 text-right">Tushum</th>
                                <th class="px-4 py-3 text-right">Xarid</th>
                                <th class="px-4 py-3 text-right">Foyda</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$monthlyRows): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-slate-500">Hozircha oylik hisobotlar yoʼq.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($monthlyRows as $row): ?>
                                <tr class="transition hover:bg-slate-50">
                                    <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($row['period']) ?></td>
                                    <td class="px-4 py-3 text-right text-slate-600"><?= (int) ($row['quantity'] ?? 0) ?></td>
                                    <td class="px-4 py-3 text-right text-slate-600"><?= formatCurrency((float) ($row['revenue'] ?? 0)) ?> soʼm</td>
                                    <td class="px-4 py-3 text-right text-slate-600"><?= formatCurrency((float) ($row['cost'] ?? 0)) ?> soʼm</td>
                                    <td class="px-4 py-3 text-right font-semibold text-emerald-600"><?= formatCurrency((float) ($row['profit'] ?? 0)) ?> soʼm</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const monthlyCtx = document.getElementById('monthlyChart');
        const paymentCtx = document.getElementById('paymentChart');
        const monthlyData = <?= json_encode($monthlyChartData, JSON_UNESCAPED_UNICODE) ?>;
        const paymentData = <?= json_encode($paymentChartData, JSON_UNESCAPED_UNICODE) ?>;

        if (monthlyCtx && monthlyData.labels.length) {
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: monthlyData.labels,
                    datasets: [
                        {
                            label: 'Tushum',
                            data: monthlyData.revenue,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.15)',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                        },
                        {
                            label: 'Foyda',
                            data: monthlyData.profit,
                            borderColor: '#0ea5e9',
                            backgroundColor: 'rgba(14, 165, 233, 0.15)',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => value.toLocaleString('uz-UZ'),
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                font: {
                                    size: 12,
                                }
                            }
                        }
                    }
                }
            });
        }

        if (paymentCtx) {
            const dataValues = paymentData.revenue.some((v) => v > 0) ? paymentData.revenue : paymentData.revenue.map(() => 1);
            new Chart(paymentCtx, {
                type: 'doughnut',
                data: {
                    labels: paymentData.labels,
                    datasets: [
                        {
                            data: dataValues,
                            backgroundColor: ['#34d399', '#38bdf8'],
                            borderColor: '#ffffff',
                            borderWidth: 2,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    aspectRatio: 1,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 12,
                                }
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>

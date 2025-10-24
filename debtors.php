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
$messages = [];

function tofloat($value)
{
    $normalized = str_replace([' ', ','], ['', '.'], (string) $value);
    return (float) $normalized;
}

$paymentLabels = [
    'cash' => 'Naqd',
    'click' => 'Click (karta)',
];
$allowedPaymentMethods = array_keys($paymentLabels);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    try {
        if ($formType === 'update_debt') {
            $debtId = (int) ($_POST['debt_id'] ?? 0);
            $debtorName = trim($_POST['debtor_name'] ?? '');
            $debtorPhone = trim($_POST['debtor_phone'] ?? '');
            $debtorGroup = trim($_POST['debtor_group'] ?? '');
            $quantity = (int) ($_POST['debt_quantity'] ?? 0);
            $pricePerUnit = tofloat($_POST['debt_price'] ?? '0');
            $note = trim($_POST['debt_note'] ?? '');

            if ($debtId <= 0) {
                $errors[] = 'Qarz yozuvi topilmadi.';
            } elseif ($debtorName === '' || $quantity <= 0 || $pricePerUnit <= 0) {
                $errors[] = 'Qarzdor ismi, miqdor va narx toʼgʼri kiritilishi kerak.';
            } else {
                $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? AND account = ?');
                $debtStmt->execute([$debtId, $account]);
                $debt = $debtStmt->fetch(PDO::FETCH_ASSOC);

                if (!$debt) {
                    $errors[] = 'Qarz yozuvi topilmadi.';
                } elseif (!empty($debt['paid_at'])) {
                    $errors[] = 'Allaqachon yopilgan qarzni tahrirlab boʼlmaydi.';
                } else {
                    $bookStmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                    $bookStmt->execute([(int) $debt['book_id'], $account]);
                    $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$book) {
                        $errors[] = 'Qarzdor bilan bogʼliq kitob inventarda topilmadi.';
                    } else {
                        $oldQuantity = (int) $debt['quantity'];
                        $difference = $quantity - $oldQuantity;
                        $currentStock = (int) $book['quantity'];

                        if ($difference > 0 && $currentStock < $difference) {
                            $errors[] = 'Omborda yetarli miqdor mavjud emas.';
                        } else {
                            $pdo->beginTransaction();
                            try {
                                $newStock = $currentStock - $difference;
                                $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                                $updateBook->execute([
                                    $newStock,
                                    $currentStock,
                                    -$difference,
                                    (int) $debt['book_id'],
                                    $account,
                                ]);

                                $newTotal = $pricePerUnit * $quantity;
                                $buyUnit = (float) ($book['buy_price'] ?? 0);
                                $updateDebt = $pdo->prepare('UPDATE debts SET debtor_name = ?, phone = ?, group_name = ?, quantity = ?, price_per_unit = ?, total_price = ?, buy_price_per_unit = ?, note = ? WHERE id = ? AND account = ?');
                                $updateDebt->execute([
                                    $debtorName,
                                    $debtorPhone !== '' ? $debtorPhone : null,
                                    $debtorGroup !== '' ? $debtorGroup : null,
                                    $quantity,
                                    $pricePerUnit,
                                    $newTotal,
                                    $buyUnit,
                                    $note !== '' ? $note : null,
                                    $debtId,
                                    $account,
                                ]);

                                $pdo->commit();
                                $messages[] = 'Qarz maʼlumotlari yangilandi.';
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                $errors[] = 'Qarz maʼlumotlarini yangilashda xatolik: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }

        if ($formType === 'delete_debt') {
            $debtId = (int) ($_POST['debt_id'] ?? 0);
            if ($debtId <= 0) {
                $errors[] = 'Qarz yozuvini aniqlab boʼlmadi.';
            } else {
                $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? AND account = ?');
                $debtStmt->execute([$debtId, $account]);
                $debt = $debtStmt->fetch(PDO::FETCH_ASSOC);

                if (!$debt) {
                    $errors[] = 'Qarz yozuvi topilmadi.';
                } elseif (!empty($debt['paid_at'])) {
                    $errors[] = 'Toʼlangan qarzni oʼchirib boʼlmaydi.';
                } else {
                    $bookStmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                    $bookStmt->execute([(int) $debt['book_id'], $account]);
                    $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

                    $pdo->beginTransaction();
                    try {
                        if ($book) {
                            $currentStock = (int) $book['quantity'];
                            $restore = $currentStock + (int) $debt['quantity'];
                            $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                            $updateBook->execute([
                                $restore,
                                $currentStock,
                                (int) $debt['quantity'],
                                (int) $debt['book_id'],
                                $account,
                            ]);
                        }

                        $delete = $pdo->prepare('DELETE FROM debts WHERE id = ? AND account = ?');
                        $delete->execute([$debtId, $account]);

                        $pdo->commit();
                        $messages[] = $book
                            ? 'Qarz oʼchirildi va ombordagi miqdor tiklandi.'
                            : 'Qarz oʼchirildi.';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Qarz yozuvini oʼchirishda xatolik: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($formType === 'mark_debt_paid') {
            $debtId = (int) ($_POST['debt_id'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $paidInput = trim($_POST['paid_total'] ?? '');
            $paymentNote = trim($_POST['payment_note'] ?? '');

            if ($debtId <= 0) {
                $errors[] = 'Qarz yozuvi topilmadi.';
            } elseif (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                $errors[] = 'Toʼlov usuli notoʼgʼri tanlandi.';
            } else {
                $debtStmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? AND account = ?');
                $debtStmt->execute([$debtId, $account]);
                $debt = $debtStmt->fetch(PDO::FETCH_ASSOC);

                if (!$debt) {
                    $errors[] = 'Qarz yozuvi topilmadi.';
                } elseif (!empty($debt['paid_at'])) {
                    $errors[] = 'Bu qarz allaqachon yopilgan.';
                } else {
                    $bookStmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                    $bookStmt->execute([(int) $debt['book_id'], $account]);
                    $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$book) {
                        $errors[] = 'Qarzdor bilan bogʼliq kitob inventarda topilmadi.';
                    } else {
                        $quantity = (int) $debt['quantity'];
                        if ($quantity <= 0) {
                            $errors[] = 'Qarz miqdori notoʼgʼri.';
                        } else {
                            $customPaid = $paidInput === '' ? null : tofloat($paidInput);
                            if ($customPaid !== null && $customPaid <= 0) {
                                $errors[] = 'Toʼlov summasi musbat boʼlishi kerak.';
                            } else {
                                $revenue = $customPaid !== null ? $customPaid : (float) ($debt['total_price'] ?? 0);
                                if ($revenue <= 0) {
                                    $errors[] = 'Qarz summasi 0 dan katta boʼlishi kerak.';
                                } else {
                                    $unitCost = (float) ($debt['buy_price_per_unit'] ?? $book['buy_price'] ?? 0);
                                    $totalCost = $unitCost * $quantity;
                                    $profit = $revenue - $totalCost;

                                    $noteParts = [];
                                    $noteParts[] = 'Qarz toʼlov: ' . ($debt['debtor_name'] ?? '');
                                    if (!empty($debt['group_name'])) {
                                        $noteParts[] = 'Guruh: ' . $debt['group_name'];
                                    }
                                    if (!empty($debt['phone'])) {
                                        $noteParts[] = 'Tel: ' . $debt['phone'];
                                    }
                                    if ($paymentNote !== '') {
                                        $noteParts[] = $paymentNote;
                                    }
                                    if (!empty($debt['note'])) {
                                        $noteParts[] = 'Izoh: ' . $debt['note'];
                                    }
                                    $saleNote = trim(implode(' | ', array_filter($noteParts)));

                                    $pdo->beginTransaction();
                                    try {
                                        $insertSale = $pdo->prepare('INSERT INTO sales (account, book_id, quantity, total_cost, total_revenue, profit, payment_method, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                                        $insertSale->execute([
                                            $account,
                                            (int) $debt['book_id'],
                                            $quantity,
                                            $totalCost,
                                            $revenue,
                                            $profit,
                                            $paymentMethod,
                                            $saleNote !== '' ? $saleNote : null,
                                        ]);

                                        $updateDebt = $pdo->prepare('UPDATE debts SET paid_at = CURRENT_TIMESTAMP, payment_method = ?, paid_total = ? WHERE id = ? AND account = ?');
                                        $updateDebt->execute([
                                            $paymentMethod,
                                            $revenue,
                                            $debtId,
                                            $account,
                                        ]);

                                        $pdo->commit();
                                        $messages[] = 'Qarz toʼlandi va sotuvlar jurnaliga qoʼshildi.';
                                    } catch (Exception $e) {
                                        $pdo->rollBack();
                                        $errors[] = 'Qarz toʼlovini qayd etishda xatolik: ' . $e->getMessage();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Amal bajarilmadi: ' . $e->getMessage();
    }
}

$debtsStmt = $pdo->prepare('SELECT d.*, b.title, COALESCE(b.quantity, 0) AS current_quantity, b.sell_price FROM debts d LEFT JOIN books b ON b.id = d.book_id WHERE d.account = ? AND d.paid_at IS NULL ORDER BY d.created_at DESC');
$debtsStmt->execute([$account]);
$debts = $debtsStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare('SELECT COUNT(*) AS debt_entries, SUM(quantity) AS debt_quantity, SUM(total_price) AS debt_value FROM debts WHERE account = ? AND paid_at IS NULL');
$summaryStmt->execute([$account]);
$debtSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['debt_entries' => 0, 'debt_quantity' => 0, 'debt_value' => 0];

$uniqueDebtors = [];
$overdueCount = 0;
$now = new DateTime('now');
$overdueLimit = (clone $now)->modify('-3 days');
foreach ($debts as $row) {
    if (!empty($row['debtor_name'])) {
        $uniqueDebtors[strtolower($row['debtor_name'])] = true;
    }
    if (!empty($row['created_at'])) {
        try {
            $createdAt = new DateTime($row['created_at']);
            if ($createdAt <= $overdueLimit) {
                $overdueCount++;
            }
        } catch (Exception $e) {
            // Ignore invalid dates
        }
    }
}
$uniqueDebtorCount = count($uniqueDebtors);

$debtsByBook = [];
foreach ($debts as $debt) {
    $bookId = isset($debt['book_id']) ? (int) $debt['book_id'] : 0;
    $title = trim((string) ($debt['title'] ?? ''));
    if ($title === '') {
        $title = 'Aniqlanmagan kitob';
        if ($bookId === 0) {
            $title .= ' (inventardan oʼchirib tashlangan boʼlishi mumkin)';
        }
    }

    $groupKey = $bookId > 0 ? 'book_' . $bookId : 'bookless_' . md5($title);

    if (!isset($debtsByBook[$groupKey])) {
        $debtsByBook[$groupKey] = [
            'book_id' => $bookId,
            'title' => $title,
            'items' => [],
            'entry_count' => 0,
            'total_quantity' => 0,
            'total_value' => 0.0,
            'current_quantity' => isset($debt['current_quantity']) ? (int) $debt['current_quantity'] : null,
        ];
    }

    $debtsByBook[$groupKey]['items'][] = $debt;
    $debtsByBook[$groupKey]['entry_count']++;
    $debtsByBook[$groupKey]['total_quantity'] += (int) ($debt['quantity'] ?? 0);
    $debtsByBook[$groupKey]['total_value'] += (float) ($debt['total_price'] ?? 0);
    if (isset($debt['current_quantity'])) {
        $debtsByBook[$groupKey]['current_quantity'] = (int) $debt['current_quantity'];
    }
}

$debtBookCount = count($debtsByBook);

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
    <title><?= htmlspecialchars($accountLabel) ?> — Qarzdorlar</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <header class="bg-white border-b border-slate-200">
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500">Hisob</p>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($accountLabel) ?> — qarzdorlar</h1>
                <p class="text-sm text-slate-600">Qarzga berilgan kitoblarni kuzating va toʼlovlarni nazorat qiling.</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="konto.php" class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Inventar</a>
                <a href="reports.php" class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Hisobotlar</a>
                <a href="index.php?logout=1" class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">Chiqish</a>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:space-y-8">
        <?php if ($errors): ?>
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($messages): ?>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($messages as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold text-slate-900">Qarzdorlar statistikasi</h2>
                    <p class="text-sm text-slate-600">Qarzga berilgan kitoblar va toʼlov holati boʼyicha umumiy maʼlumot.</p>
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Faol qarzlar</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= (int) ($debtSummary['debt_entries'] ?? 0) ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Kitob turlari</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= $debtBookCount ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Qarzdorlar soni</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= $uniqueDebtorCount ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Qarzdagi nusxalar</dt>
                    <dd class="text-2xl font-semibold text-slate-900"><?= (int) ($debtSummary['debt_quantity'] ?? 0) ?></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Qarz summasi</dt>
                    <dd class="text-2xl font-semibold text-amber-600"><?= formatCurrency((float) ($debtSummary['debt_value'] ?? 0)) ?> soʼm</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-slate-500">3 kundan oshganlar</dt>
                    <dd class="text-2xl font-semibold text-rose-600"><?= $overdueCount ?></dd>
                </div>
            </dl>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold text-slate-900">Faol qarzdorlar roʼyxati</h2>
                    <p class="text-sm text-slate-600">Qarzlar kitob nomi boʼyicha guruhlangan, 3 kundan oshgan qarzdorlar sariq rangda ajratiladi.</p>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm md:text-base">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Qarzdor</th>
                            <th class="px-4 py-3 text-left">Kitob</th>
                            <th class="px-4 py-3 text-left">Miqdor</th>
                            <th class="px-4 py-3 text-left">Narx</th>
                            <th class="px-4 py-3 text-left">Sana</th>
                            <th class="px-4 py-3 text-left">Holati</th>
                            <th class="px-4 py-3 text-right">Amallar</th>
                        </tr>
                    </thead>
                    <?php if (!$debts): ?>
                        <tbody>
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-slate-500">Faol qarzdorlar mavjud emas.</td>
                            </tr>
                        </tbody>
                    <?php else: ?>
                        <?php foreach ($debtsByBook as $group): ?>
                            <tbody class="divide-y divide-slate-100">
                                <tr class="bg-slate-100/70">
                                    <td colspan="7" class="px-4 py-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Kitob</p>
                                                <p class="text-base font-semibold text-slate-900"><?= htmlspecialchars($group['title']) ?></p>
                                            </div>
                                            <div class="flex flex-wrap gap-3 text-xs text-slate-600 sm:text-sm">
                                                <span><span class="font-semibold text-slate-900"><?= $group['entry_count'] ?></span> ta qarz</span>
                                                <span><span class="font-semibold text-slate-900"><?= $group['total_quantity'] ?></span> ta nusxa</span>
                                                <span><span class="font-semibold text-slate-900"><?= formatCurrency($group['total_value']) ?></span> soʼm</span>
                                                <?php if ($group['current_quantity'] !== null): ?>
                                                    <span>Omborda: <span class="font-semibold text-slate-900"><?= (int) $group['current_quantity'] ?></span> ta</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php foreach ($group['items'] as $debt): ?>
                                    <?php
                                        $createdAt = null;
                                        $overdue = false;
                                        $daysAgoText = '';
                                        if (!empty($debt['created_at'])) {
                                            try {
                                                $createdAt = new DateTime($debt['created_at']);
                                                $diff = $createdAt->diff(new DateTime('now'));
                                                $daysAgo = (int) $diff->format('%a');
                                                $daysAgoText = $daysAgo === 0 ? 'Bugun' : ($daysAgo . ' kun oldin');
                                                $overdue = $createdAt <= $overdueLimit;
                                            } catch (Exception $e) {
                                                $daysAgoText = '';
                                            }
                                        }
                                        $rowClasses = 'transition hover:bg-slate-50';
                                        if ($overdue) {
                                            $rowClasses .= ' border-l-4 border-amber-400 bg-amber-50/70 hover:bg-amber-50';
                                        }
                                        $totalPrice = (float) ($debt['total_price'] ?? 0);
                                        $pricePerUnit = (float) ($debt['price_per_unit'] ?? 0);
                                        $quantity = (int) ($debt['quantity'] ?? 0);
                                        $stock = (int) ($debt['current_quantity'] ?? 0);
                                    ?>
                                    <tr class="<?= $rowClasses ?>">
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars($debt['debtor_name'] ?? 'Nomaʼlum') ?></div>
                                            <?php if (!empty($debt['group_name'])): ?>
                                                <div class="text-xs text-slate-500">Guruh: <?= htmlspecialchars($debt['group_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($debt['phone'])): ?>
                                                <div class="text-xs text-slate-500">Tel: <?= htmlspecialchars($debt['phone']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium text-slate-900"><?= htmlspecialchars($debt['title'] ?? 'Kitob topilmadi') ?></div>
                                            <?php if (!empty($debt['note'])): ?>
                                                <div class="text-xs text-slate-500">Izoh: <?= htmlspecialchars($debt['note']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            <div class="font-semibold text-slate-900"><?= $quantity ?> ta</div>
                                            <div class="text-xs text-slate-500">Ombor: <?= $stock ?> ta</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            <div class="font-semibold text-slate-900"><?= formatCurrency($totalPrice) ?> soʼm</div>
                                            <div class="text-xs text-slate-500"><?= formatCurrency($pricePerUnit) ?> soʼm / ta</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-slate-700">
                                            <?php if ($createdAt): ?>
                                                <div class="font-medium text-slate-900"><?= htmlspecialchars($createdAt->format('Y-m-d')) ?></div>
                                                <?php if ($daysAgoText !== ''): ?>
                                                    <div class="text-xs text-slate-500"><?= htmlspecialchars($daysAgoText) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="text-xs text-slate-500">Sana mavjud emas</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <?php if ($overdue): ?>
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">3 kundan oshgan</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Faol</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top text-right">
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <button type="button" data-open-debt-edit
                                                    data-debt-id="<?= (int) $debt['id'] ?>"
                                                    data-debt-name="<?= htmlspecialchars($debt['debtor_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-phone="<?= htmlspecialchars($debt['phone'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-group="<?= htmlspecialchars($debt['group_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-quantity="<?= $quantity ?>"
                                                    data-debt-price="<?= $pricePerUnit ?>"
                                                    data-debt-note="<?= htmlspecialchars($debt['note'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-title="<?= htmlspecialchars($debt['title'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-stock="<?= $stock ?>"
                                                    class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700 transition hover:bg-sky-200">Tahrirlash</button>
                                                <button type="button" data-open-debt-pay
                                                    data-debt-id="<?= (int) $debt['id'] ?>"
                                                    data-debt-name="<?= htmlspecialchars($debt['debtor_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-title="<?= htmlspecialchars($debt['title'] ?? '', ENT_QUOTES) ?>"
                                                    data-debt-quantity="<?= $quantity ?>"
                                                    data-debt-total="<?= $totalPrice ?>"
                                                    data-debt-price="<?= $pricePerUnit ?>"
                                                    class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-200">Toʼlov</button>
                                                <form method="post" class="inline" onsubmit="return confirm('Qarz yozuvini oʼchirilsinmi?');">
                                                    <input type="hidden" name="form_type" value="delete_debt">
                                                    <input type="hidden" name="debt_id" value="<?= (int) $debt['id'] ?>">
                                                    <button type="submit" class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-200">Oʼchirish</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </section>
    </main>

    <div id="modal-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/40 backdrop-blur"></div>

    <div id="debt-edit-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Qarz maʼlumotlarini tahrirlash</h2>
                    <p id="debt-edit-info" class="text-sm text-slate-600">Qarzdor maʼlumotlarini yangilang.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="debt-edit-form">
                <input type="hidden" name="form_type" value="update_debt">
                <input type="hidden" name="debt_id" value="">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700">Qarzdor ismi</label>
                        <input type="text" name="debtor_name" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Telefon</label>
                        <input type="tel" name="debtor_phone" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Guruh/klassi</label>
                        <input type="text" name="debtor_group" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                        <input type="number" name="debt_quantity" min="1" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
                        <p id="debt-edit-helper" class="mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Kitob narxi</label>
                        <input type="number" name="debt_price" min="0" step="0.01" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
                        <p class="mt-1 text-xs text-slate-500">Bitta kitob uchun sotuv narxi.</p>
                    </div>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-900">Jami summa:</span>
                    <span id="debt-edit-total">0</span> soʼm
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                    <textarea name="debt_note" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="debt-pay-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Qarz toʼlovini qayd etish</h2>
                    <p id="debt-pay-info" class="text-sm text-slate-600">Toʼlov usuli va tushgan summani kiriting.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="debt-pay-form">
                <input type="hidden" name="form_type" value="mark_debt_paid">
                <input type="hidden" name="debt_id" value="">
                <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-900">Qarz summasi:</span>
                    <span id="debt-pay-due">0</span> soʼm
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium text-slate-700">Toʼlov usuli</span>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($paymentLabels as $value => $label): ?>
                            <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm transition hover:border-emerald-400">
                                <input type="radio" name="payment_method" value="<?= htmlspecialchars($value) ?>" <?= $value === 'cash' ? 'checked' : '' ?> class="text-emerald-500 focus:ring-emerald-500">
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Toʼlangan summa</label>
                    <input type="number" name="paid_total" min="0" step="0.01" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 150000">
                    <p class="mt-1 text-xs text-slate-500">Boʼsh qoldirilsa, qarz summasi bilan teng deb qabul qilinadi.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                    <textarea name="payment_note" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: naqd toʼladi"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Toʼlovni saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const body = document.body;
        const backdrop = document.getElementById('modal-backdrop');
        const editModal = document.getElementById('debt-edit-modal');
        const payModal = document.getElementById('debt-pay-modal');
        const modals = [editModal, payModal];
        const formatter = new Intl.NumberFormat('uz-UZ');

        function openModal(modal) {
            if (!modal) return;
            if (backdrop) {
                backdrop.classList.remove('hidden');
            }
            modals.forEach((item) => {
                if (item && item !== modal) {
                    item.classList.add('hidden');
                    item.classList.remove('flex');
                }
            });
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            body.classList.add('overflow-hidden');
        }

        function closeModals() {
            if (backdrop) {
                backdrop.classList.add('hidden');
            }
            modals.forEach((modal) => {
                if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });
            body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', closeModals);
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeModals);
        }

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModals();
            }
        });

        const editForm = document.getElementById('debt-edit-form');
        const editInfo = document.getElementById('debt-edit-info');
        const editHelper = document.getElementById('debt-edit-helper');
        const editTotal = document.getElementById('debt-edit-total');
        const editQuantity = editForm ? editForm.querySelector('input[name="debt_quantity"]') : null;
        const editPrice = editForm ? editForm.querySelector('input[name="debt_price"]') : null;

        function updateEditTotal() {
            if (!editTotal) return;
            const qty = editQuantity ? parseInt(editQuantity.value || '0', 10) : 0;
            const price = editPrice ? parseFloat(editPrice.value || '0') : 0;
            const total = qty > 0 && price > 0 ? qty * price : 0;
            editTotal.textContent = formatter.format(Math.max(total, 0));
        }

        if (editQuantity) {
            editQuantity.addEventListener('input', updateEditTotal);
        }
        if (editPrice) {
            editPrice.addEventListener('input', updateEditTotal);
        }

        document.querySelectorAll('[data-open-debt-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!editForm) return;
                const id = button.getAttribute('data-debt-id') || '';
                const name = button.getAttribute('data-debt-name') || '';
                const phone = button.getAttribute('data-debt-phone') || '';
                const group = button.getAttribute('data-debt-group') || '';
                const quantity = parseInt(button.getAttribute('data-debt-quantity') || '0', 10);
                const price = parseFloat(button.getAttribute('data-debt-price') || '0');
                const note = button.getAttribute('data-debt-note') || '';
                const title = button.getAttribute('data-debt-title') || '';
                const stock = parseInt(button.getAttribute('data-debt-stock') || '0', 10);
                const maxQuantity = quantity + Math.max(stock, 0);

                editForm.reset();
                editForm.debt_id.value = id;
                editForm.debtor_name.value = name;
                editForm.debtor_phone.value = phone;
                editForm.debtor_group.value = group;
                if (editQuantity) {
                    editQuantity.value = quantity > 0 ? quantity : 1;
                    editQuantity.max = Math.max(maxQuantity, 1);
                }
                if (editPrice) {
                    editPrice.value = price > 0 ? price.toString() : '';
                }
                editForm.debt_note.value = note;

                if (editHelper) {
                    editHelper.textContent = stock > 0
                        ? `Omborda qoʼshimcha ${stock} ta mavjud. Maksimal miqdor ${maxQuantity} ta.`
                        : 'Omborda qoʼshimcha nusxa mavjud emas.';
                }
                if (editInfo) {
                    editInfo.textContent = `${title} — ${name !== '' ? name : 'qarzdor'}`;
                }
                updateEditTotal();
                openModal(editModal);
            });
        });

        const payForm = document.getElementById('debt-pay-form');
        const payInfo = document.getElementById('debt-pay-info');
        const payDue = document.getElementById('debt-pay-due');
        const payTotalInput = payForm ? payForm.querySelector('input[name="paid_total"]') : null;

        document.querySelectorAll('[data-open-debt-pay]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!payForm) return;
                const id = button.getAttribute('data-debt-id') || '';
                const name = button.getAttribute('data-debt-name') || '';
                const title = button.getAttribute('data-debt-title') || '';
                const quantity = parseInt(button.getAttribute('data-debt-quantity') || '0', 10);
                const total = parseFloat(button.getAttribute('data-debt-total') || '0');

                payForm.reset();
                payForm.debt_id.value = id;
                if (payInfo) {
                    const infoText = `${name !== '' ? name : 'Qarzdor'} — ${title !== '' ? title : 'kitob'} (${quantity} ta)`;
                    payInfo.textContent = infoText;
                }
                if (payDue) {
                    payDue.textContent = formatter.format(Math.max(total, 0));
                }
                if (payTotalInput) {
                    payTotalInput.value = total > 0 ? total.toString() : '';
                }
                payForm.querySelectorAll('input[name="payment_method"]').forEach((radio) => {
                    radio.checked = radio.value === 'cash';
                });
                openModal(payModal);
            });
        });
    });
    </script>
</body>
</html>

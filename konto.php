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
        if ($formType === 'create' || $formType === 'update') {
            $title = trim($_POST['title'] ?? '');
            $buyPrice = tofloat($_POST['buy_price'] ?? '0');
            $sellPrice = tofloat($_POST['sell_price'] ?? '0');
            $quantity = (int) ($_POST['quantity'] ?? 0);

            if ($title === '' || $buyPrice <= 0 || $sellPrice <= 0 || $quantity < 0) {
                $errors[] = 'Maʼlumotlarni toʼgʼri kiriting. Narxlar 0 dan katta, miqdor esa manfiy boʼlmasligi kerak.';
            } else {
                if ($formType === 'create') {
                    $stmt = $pdo->prepare('INSERT INTO books (account, title, author, category, buy_price, sell_price, quantity, last_quantity_snapshot, last_quantity_change) VALUES (?, ?, "", "", ?, ?, ?, NULL, 0)');
                    $stmt->execute([$account, $title, $buyPrice, $sellPrice, $quantity]);
                    $messages[] = 'Kitob muvaffaqiyatli qoʼshildi!';
                } else {
                    $bookId = (int) ($_POST['book_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT quantity FROM books WHERE id = ? AND account = ?');
                    $stmt->execute([$bookId, $account]);
                    $book = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$book) {
                        $errors[] = 'Kitob topilmadi.';
                    } else {
                        $previousQuantity = (int) $book['quantity'];
                        $difference = $quantity - $previousQuantity;
                        $snapshot = $difference !== 0 ? $previousQuantity : null;
                        $update = $pdo->prepare('UPDATE books SET title = ?, buy_price = ?, sell_price = ?, quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                        $update->execute([$title, $buyPrice, $sellPrice, $quantity, $snapshot, $difference, $bookId, $account]);
                        $messages[] = 'Kitob maʼlumotlari yangilandi.';
                    }
                }
            }
        }

        if ($formType === 'delete') {
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM books WHERE id = ? AND account = ?');
            $stmt->execute([$bookId, $account]);
            if ($stmt->rowCount() > 0) {
                $messages[] = 'Kitob oʼchirildi.';
            } else {
                $errors[] = 'Kitob topilmadi yoki allaqachon oʼchirilgan.';
            }
        }

        if ($formType === 'sell') {
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $sellQuantity = (int) ($_POST['sell_quantity'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $note = trim($_POST['note'] ?? '');

            if ($sellQuantity <= 0) {
                $errors[] = 'Sotiladigan miqdor 1 dan kam boʼlmasligi kerak.';
            } elseif (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                $errors[] = 'Toʼlov usuli notoʼgʼri tanlandi.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                $stmt->execute([$bookId, $account]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    $errors[] = 'Kitob topilmadi.';
                } elseif ((int) $book['quantity'] < $sellQuantity) {
                    $errors[] = 'Yetarli miqdor mavjud emas.';
                } else {
                    $paidInput = trim($_POST['paid_amount'] ?? '');
                    $customRevenue = $paidInput === '' ? null : tofloat($paidInput);
                    if ($customRevenue !== null && $customRevenue < 0) {
                        $errors[] = 'Toʼlangan summa manfiy boʼlishi mumkin emas.';
                    } else {
                        $totalCost = (float) $book['buy_price'] * $sellQuantity;
                        $totalRevenue = $customRevenue !== null
                            ? (float) $customRevenue
                            : (float) $book['sell_price'] * $sellQuantity;
                        $profit = $totalRevenue - $totalCost;

                        $pdo->beginTransaction();
                        try {
                            $insertSale = $pdo->prepare('INSERT INTO sales (account, book_id, quantity, total_cost, total_revenue, profit, payment_method, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                            $insertSale->execute([
                                $account,
                                $bookId,
                                $sellQuantity,
                                $totalCost,
                                $totalRevenue,
                                $profit,
                                $paymentMethod,
                                $note !== '' ? $note : null,
                            ]);

                            $newQuantity = (int) $book['quantity'] - $sellQuantity;
                            $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                            $updateBook->execute([$newQuantity, (int) $book['quantity'], -$sellQuantity, $bookId, $account]);

                            $pdo->commit();
                            $messages[] = 'Sotuv muvaffaqiyatli qayd etildi.';
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $errors[] = 'Sotuvni saqlashda xatolik: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        if ($formType === 'create_debt') {
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $debtQuantity = (int) ($_POST['debt_quantity'] ?? 0);
            $debtorName = trim($_POST['debtor_name'] ?? '');
            $debtorPhone = trim($_POST['debtor_phone'] ?? '');
            $debtorGroup = trim($_POST['debtor_group'] ?? '');
            $pricePerUnit = tofloat($_POST['debt_price'] ?? '0');
            $note = trim($_POST['debt_note'] ?? '');

            if ($bookId <= 0 || $debtQuantity <= 0) {
                $errors[] = 'Qarzga berish uchun kitob va miqdorni toʼgʼri tanlang.';
            } elseif ($debtorName === '') {
                $errors[] = 'Qarzdor ismini kiriting.';
            } elseif ($pricePerUnit <= 0) {
                $errors[] = 'Qarz narxi 0 dan katta boʼlishi kerak.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                $stmt->execute([$bookId, $account]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$book) {
                    $errors[] = 'Kitob topilmadi.';
                } elseif ((int) $book['quantity'] < $debtQuantity) {
                    $errors[] = 'Omborda yetarli miqdor mavjud emas.';
                } else {
                    $totalPrice = $pricePerUnit * $debtQuantity;
                    $buyUnit = (float) ($book['buy_price'] ?? 0);
                    $pdo->beginTransaction();
                    try {
                        $insertDebt = $pdo->prepare('INSERT INTO debts (account, book_id, debtor_name, phone, group_name, quantity, price_per_unit, total_price, buy_price_per_unit, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $insertDebt->execute([
                            $account,
                            $bookId,
                            $debtorName,
                            $debtorPhone !== '' ? $debtorPhone : null,
                            $debtorGroup !== '' ? $debtorGroup : null,
                            $debtQuantity,
                            $pricePerUnit,
                            $totalPrice,
                            $buyUnit,
                            $note !== '' ? $note : null,
                        ]);

                        $newQuantity = (int) $book['quantity'] - $debtQuantity;
                        $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                        $updateBook->execute([
                            $newQuantity,
                            (int) $book['quantity'],
                            -$debtQuantity,
                            $bookId,
                            $account,
                        ]);

                        $pdo->commit();
                        $messages[] = 'Kitob qarzga berildi.';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Qarzga berishda xatolik: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($formType === 'edit_sale') {
            $saleId = (int) ($_POST['sale_id'] ?? 0);
            $newQuantity = (int) ($_POST['sale_quantity'] ?? 0);
            $paymentMethod = $_POST['sale_payment_method'] ?? 'cash';
            $note = trim($_POST['sale_note'] ?? '');

            if ($newQuantity <= 0) {
                $errors[] = 'Sotuv miqdori 1 dan kam boʼlmasligi kerak.';
            } elseif (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                $errors[] = 'Toʼlov usuli notoʼgʼri tanlandi.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND account = ?');
                $stmt->execute([$saleId, $account]);
                $sale = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sale) {
                    $errors[] = 'Sotuv topilmadi yoki tegishli kitob oʼchirilgan.';
                } else {
                    $bookStmt = $pdo->prepare('SELECT quantity, buy_price, sell_price FROM books WHERE id = ? AND account = ?');
                    $bookStmt->execute([(int) $sale['book_id'], $account]);
                    $book = $bookStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$book) {
                        $errors[] = 'Kitob mavjud emas, sotuvni tahrirlab boʼlmaydi.';
                    } else {
                        $oldQuantity = (int) $sale['quantity'];
                        $difference = $newQuantity - $oldQuantity;
                        $currentStock = (int) $book['quantity'];
                        if ($difference > 0 && $currentStock < $difference) {
                            $errors[] = 'Kitob omborida yetarli miqdor mavjud emas.';
                        } else {
                            $revenueInput = trim($_POST['sale_total_revenue'] ?? '');
                            $customRevenue = $revenueInput === '' ? null : tofloat($revenueInput);
                            if ($customRevenue !== null && $customRevenue < 0) {
                                $errors[] = 'Tushum qiymati manfiy boʼlishi mumkin emas.';
                            } else {
                                $unitCost = $oldQuantity > 0 ? ((float) $sale['total_cost'] / $oldQuantity) : (float) $book['buy_price'];
                                $unitRevenue = $oldQuantity > 0 ? ((float) $sale['total_revenue'] / $oldQuantity) : (float) $book['sell_price'];
                                $newTotalCost = $unitCost * $newQuantity;
                                $calculatedRevenue = $unitRevenue * $newQuantity;
                                $newTotalRevenue = $customRevenue !== null ? (float) $customRevenue : $calculatedRevenue;
                                $newProfit = $newTotalRevenue - $newTotalCost;

                                $pdo->beginTransaction();
                                try {
                                    $newBookQuantity = $currentStock - $difference;
                                    $inventoryChange = -$difference;
                                    $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                                    $updateBook->execute([
                                        $newBookQuantity,
                                        $currentStock,
                                        $inventoryChange,
                                        (int) $sale['book_id'],
                                        $account,
                                    ]);

                                    $updateSale = $pdo->prepare('UPDATE sales SET quantity = ?, total_cost = ?, total_revenue = ?, profit = ?, payment_method = ?, note = ? WHERE id = ? AND account = ?');
                                    $updateSale->execute([
                                        $newQuantity,
                                        $newTotalCost,
                                        $newTotalRevenue,
                                        $newProfit,
                                        $paymentMethod,
                                        $note !== '' ? $note : null,
                                        $saleId,
                                        $account,
                                    ]);

                                    $pdo->commit();
                                    $messages[] = 'Sotuv maʼlumotlari yangilandi.';
                                } catch (Exception $e) {
                                    $pdo->rollBack();
                                    $errors[] = 'Sotuvni yangilashda xatolik: ' . $e->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($formType === 'delete_sale') {
            $saleId = (int) ($_POST['sale_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND account = ?');
            $stmt->execute([$saleId, $account]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                $errors[] = 'Sotuv topilmadi.';
            } else {
                $bookStmt = $pdo->prepare('SELECT quantity FROM books WHERE id = ? AND account = ?');
                $bookStmt->execute([(int) $sale['book_id'], $account]);
                $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

                $pdo->beginTransaction();
                try {
                    if ($book) {
                        $currentStock = (int) $book['quantity'];
                        $restoreQuantity = $currentStock + (int) $sale['quantity'];
                        $updateBook = $pdo->prepare('UPDATE books SET quantity = ?, last_quantity_snapshot = ?, last_quantity_change = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                        $updateBook->execute([$restoreQuantity, $currentStock, (int) $sale['quantity'], (int) $sale['book_id'], $account]);
                    }
                    $deleteSale = $pdo->prepare('DELETE FROM sales WHERE id = ? AND account = ?');
                    $deleteSale->execute([$saleId, $account]);

                    $pdo->commit();
                    $messages[] = $book
                        ? 'Sotuv oʼchirildi va kitob miqdori qayta tiklandi.'
                        : 'Sotuv oʼchirildi, ammo tegishli kitob topilmagani uchun miqdor oʼzgarmadi.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Sotuvni oʼchirishda xatolik: ' . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Amal bajarilmadi: ' . $e->getMessage();
    }
}

$booksStmt = $pdo->prepare('SELECT * FROM books WHERE account = ? ORDER BY updated_at DESC, title ASC');
$booksStmt->execute([$account]);
$books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

$inventorySummaryStmt = $pdo->prepare('SELECT COUNT(*) AS total_books, SUM(quantity) AS total_quantity, SUM(buy_price * quantity) AS total_cost_value, SUM(sell_price * quantity) AS potential_revenue FROM books WHERE account = ?');
$inventorySummaryStmt->execute([$account]);
$inventorySummary = $inventorySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_books' => 0, 'total_quantity' => 0, 'total_cost_value' => 0, 'potential_revenue' => 0];

$salesSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, SUM(quantity) AS sold_quantity, SUM(total_revenue) AS revenue, SUM(profit) AS profit FROM sales WHERE account = ?');
$salesSummaryStmt->execute([$account]);
$salesSummary = $salesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'sold_quantity' => 0, 'revenue' => 0, 'profit' => 0];

$debtSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS debt_entries, SUM(quantity) AS debt_quantity, SUM(total_price) AS debt_value FROM debts WHERE account = ? AND paid_at IS NULL');
$debtSummaryStmt->execute([$account]);
$debtSummary = $debtSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['debt_entries' => 0, 'debt_quantity' => 0, 'debt_value' => 0];

$paymentStatsStmt = $pdo->prepare('SELECT payment_method, COUNT(*) AS sales_count, SUM(quantity) AS quantity, SUM(total_revenue) AS revenue FROM sales WHERE account = ? GROUP BY payment_method');
$paymentStatsStmt->execute([$account]);
$paymentStats = [
    'cash' => ['sales_count' => 0, 'quantity' => 0, 'revenue' => 0.0],
    'click' => ['sales_count' => 0, 'quantity' => 0, 'revenue' => 0.0],
];
foreach ($paymentStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $method = $row['payment_method'] ?? 'cash';
    if (!isset($paymentStats[$method])) {
        $paymentStats[$method] = ['sales_count' => 0, 'quantity' => 0, 'revenue' => 0.0];
    }
    $paymentStats[$method]['sales_count'] = (int) ($row['sales_count'] ?? 0);
    $paymentStats[$method]['quantity'] = (int) ($row['quantity'] ?? 0);
    $paymentStats[$method]['revenue'] = (float) ($row['revenue'] ?? 0);
}

$topSoldStmt = $pdo->prepare('SELECT b.title, SUM(s.quantity) AS sold_quantity, COALESCE(b.quantity, 0) AS remaining_quantity FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ? GROUP BY s.book_id, b.title, b.quantity ORDER BY sold_quantity DESC LIMIT 5');
$topSoldStmt->execute([$account]);
$topSold = $topSoldStmt->fetchAll(PDO::FETCH_ASSOC);

$saleFromInput = $_GET['sale_from'] ?? '';
$saleToInput = $_GET['sale_to'] ?? '';
$saleFrom = $saleFromInput !== '' ? DateTime::createFromFormat('Y-m-d', $saleFromInput) : null;
$saleTo = $saleToInput !== '' ? DateTime::createFromFormat('Y-m-d', $saleToInput) : null;
if ($saleFrom && !$saleTo) {
    $saleTo = clone $saleFrom;
}
if ($saleFrom && $saleTo && $saleFrom > $saleTo) {
    [$saleFrom, $saleTo] = [$saleTo, $saleFrom];
}

$salesQuery = 'SELECT s.*, b.title, COALESCE(b.quantity, 0) AS current_quantity FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ?';
$salesParams = [$account];
$saleRangeActive = false;
if ($saleFrom) {
    $salesQuery .= ' AND s.sold_at >= ?';
    $salesParams[] = $saleFrom->format('Y-m-d') . ' 00:00:00';
    $saleRangeActive = true;
}
if ($saleTo) {
    $salesQuery .= ' AND s.sold_at <= ?';
    $salesParams[] = $saleTo->format('Y-m-d') . ' 23:59:59';
    $saleRangeActive = true;
}
$salesQuery .= ' ORDER BY s.sold_at DESC';
if (!$saleRangeActive) {
    $salesQuery .= ' LIMIT 10';
}
$salesStmt = $pdo->prepare($salesQuery);
$salesStmt->execute($salesParams);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title><?= htmlspecialchars($accountLabel) ?> — Inventar boshqaruvi</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <header class="bg-white border-b border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500">Hisob</p>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($accountLabel) ?> inventari</h1>
                <p class="text-sm text-slate-600">Kitoblarni boshqaring, sotuvlarni qayd eting va foydani kuzating.</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="debtors.php" class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                    <span>Qarzdorlar</span>
                </a>
                <a href="reports.php" class="inline-flex items-center gap-2 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                    <span>Hisobotlar</span>
                </a>
                <a href="index.php?logout=1" class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                    <span>Chiqish</span>
                </a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:space-y-8">
        <?php if ($errors): ?>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($messages): ?>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($messages as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="grid gap-4 md:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">Inventar koʼrsatkichlari</h2>
                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Kitob turlari</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= (int) ($inventorySummary['total_books'] ?? 0) ?></dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Jami nusxalar</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= (int) ($inventorySummary['total_quantity'] ?? 0) ?></dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Xarid qiymati</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= formatCurrency((float) ($inventorySummary['total_cost_value'] ?? 0)) ?> soʼm</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Potensial tushum</dt>
                        <dd class="text-xl font-semibold text-emerald-600"><?= formatCurrency((float) ($inventorySummary['potential_revenue'] ?? 0)) ?> soʼm</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Qarzga berilgan nusxalar</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= (int) ($debtSummary['debt_quantity'] ?? 0) ?></dd>
                        <p class="text-xs text-slate-500"><?= (int) ($debtSummary['debt_entries'] ?? 0) ?> ta qarzdor</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Qarz summasi</dt>
                        <dd class="text-xl font-semibold text-amber-600"><?= formatCurrency((float) ($debtSummary['debt_value'] ?? 0)) ?> soʼm</dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">Sotuv koʼrsatkichlari</h2>
                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Sotuvlar soni</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= (int) ($salesSummary['sales_count'] ?? 0) ?></dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Sotilgan nusxalar</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= (int) ($salesSummary['sold_quantity'] ?? 0) ?></dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Tushum</dt>
                        <dd class="text-xl font-semibold text-slate-900"><?= formatCurrency((float) ($salesSummary['revenue'] ?? 0)) ?> soʼm</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-slate-500">Foyda</dt>
                        <dd class="text-xl font-semibold text-emerald-600"><?= formatCurrency((float) ($salesSummary['profit'] ?? 0)) ?> soʼm</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Inventar roʼyxati</h2>
                    <p class="text-sm text-slate-600">Soʼnggi 10 kitob koʼrsatilgan. «Barchasini koʼrish» tugmasi orqali toʼliq roʼyxatni oching.</p>
                </div>
                <div class="flex flex-wrap justify-end gap-2 text-sm">
                    <div class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-600">Naqd: <?= (int) $paymentStats['cash']['quantity'] ?> ta</div>
                    <div class="rounded-full bg-sky-50 px-3 py-1 text-sky-600">Click: <?= (int) $paymentStats['click']['quantity'] ?> ta</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Kitob nomi</th>
                            <th class="px-4 py-3 text-left">Narxlar</th>
                            <th class="px-4 py-3 text-left">Miqdor</th>
                            <th class="px-4 py-3 text-right">Potensial foyda</th>
                            <th class="px-4 py-3 text-right">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$books): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Inventar boʼsh.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($books as $index => $book): ?>
                            <?php
                                $quantity = (int) ($book['quantity'] ?? 0);
                                $snapshot = isset($book['last_quantity_snapshot']) ? $book['last_quantity_snapshot'] : null;
                                $change = (int) ($book['last_quantity_change'] ?? 0);
                                $profitPotential = ((float) ($book['sell_price'] ?? 0) - (float) ($book['buy_price'] ?? 0)) * $quantity;
                                $rowClasses = 'transition hover:bg-slate-50';
                                if ($index >= 10) {
                                    $rowClasses .= ' hidden';
                                }
                            ?>
                            <tr class="<?= $rowClasses ?>" data-book-row="<?= $index ?>">
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    <?= htmlspecialchars($book['title']) ?>
                                    <p class="text-xs text-slate-500">Yangilangan: <?= htmlspecialchars(date('Y-m-d', strtotime($book['updated_at'] ?? $book['created_at'] ?? 'now'))) ?></p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    <div>Xarid: <span class="font-semibold text-slate-900"><?= formatCurrency((float) ($book['buy_price'] ?? 0)) ?></span> soʼm</div>
                                    <div>Sotuv: <span class="font-semibold text-emerald-600"><?= formatCurrency((float) ($book['sell_price'] ?? 0)) ?></span> soʼm</div>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    <div class="text-base font-semibold text-slate-900">
                                        <?php if ($change !== 0 && $snapshot !== null): ?>
                                            <?= (int) $snapshot ?>
                                            <sup class="text-xs font-semibold <?= $change > 0 ? 'text-emerald-600' : 'text-rose-500' ?>">
                                                <?= $change > 0 ? '+' : '' ?><?= $change ?>
                                            </sup>
                                        <?php else: ?>
                                            <?= $quantity ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-slate-500">Jami: <?= $quantity ?> ta</div>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600">
                                    <?= formatCurrency($profitPotential) ?> soʼm
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button type="button" data-open-sell
                                            data-book-id="<?= (int) $book['id'] ?>"
                                            data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                                            data-book-quantity="<?= $quantity ?>"
                                            data-book-sell-price="<?= htmlspecialchars((float) ($book['sell_price'] ?? 0), ENT_QUOTES) ?>"
                                            class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-200">Sotish</button>
                                        <button type="button" data-open-debt
                                            data-book-id="<?= (int) $book['id'] ?>"
                                            data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                                            data-book-quantity="<?= $quantity ?>"
                                            data-book-sell-price="<?= htmlspecialchars((float) ($book['sell_price'] ?? 0), ENT_QUOTES) ?>"
                                            class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-200">Qarzga berish</button>
                                        <button type="button" data-open-book-edit
                                            data-book-id="<?= (int) $book['id'] ?>"
                                            data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                                            data-book-buy="<?= (float) ($book['buy_price'] ?? 0) ?>"
                                            data-book-sell="<?= (float) ($book['sell_price'] ?? 0) ?>"
                                            data-book-quantity="<?= $quantity ?>"
                                            class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700 transition hover:bg-sky-200">Tahrirlash</button>
                                        <form method="post" class="inline" onsubmit="return confirm('Kitobni oʼchirilsinmi?');">
                                            <input type="hidden" name="form_type" value="delete">
                                            <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                                            <button type="submit" class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-200">Oʼchirish</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($books) > 10): ?>
                <div class="flex justify-center">
                    <button type="button" data-action="show-all-books" class="rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Barchasini koʼrish</button>
                </div>
            <?php endif; ?>
        </section>

        <section class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Sotilgan kitoblar jurnali</h2>
                    <p class="text-sm text-slate-600">Soʼnggi 10 sotuv koʼrsatiladi. Sana oraligʼini tanlab, kerakli davrni koʼring.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <div class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-600">Naqd tushum: <?= formatCurrency((float) $paymentStats['cash']['revenue']) ?> soʼm</div>
                    <div class="rounded-full bg-sky-50 px-3 py-1 text-sky-600">Click tushum: <?= formatCurrency((float) $paymentStats['click']['revenue']) ?> soʼm</div>
                </div>
            </div>

            <form method="get" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto_auto]">
                <div>
                    <label class="block text-sm font-medium text-slate-600">Boshlanish sanasi</label>
                    <input type="date" name="sale_from" value="<?= htmlspecialchars($saleFrom ? $saleFrom->format('Y-m-d') : '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Tugash sanasi</label>
                    <input type="date" name="sale_to" value="<?= htmlspecialchars($saleTo ? $saleTo->format('Y-m-d') : '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Filtrlash</button>
                </div>
                <div class="flex items-end">
                    <a href="konto.php" class="w-full rounded-xl border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 transition hover:bg-slate-100">Tozalash</a>
                </div>
            </form>

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
                            <th class="px-4 py-3 text-right">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$sales): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-slate-500">Sotuvlar topilmadi.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php $canEditSale = !empty($sale['title']); ?>
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
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <?php if ($canEditSale): ?>
                                            <button type="button" data-open-sale
                                                data-sale-id="<?= (int) $sale['id'] ?>"
                                                data-sale-quantity="<?= (int) $sale['quantity'] ?>"
                                                data-sale-payment="<?= htmlspecialchars($sale['payment_method'] ?? 'cash', ENT_QUOTES) ?>"
                                                data-sale-note="<?= htmlspecialchars($sale['note'] ?? '', ENT_QUOTES) ?>"
                                                data-sale-revenue="<?= htmlspecialchars((float) ($sale['total_revenue'] ?? 0), ENT_QUOTES) ?>"
                                                data-sale-stock="<?= (int) $sale['current_quantity'] ?>"
                                                class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700 transition hover:bg-sky-200">Tahrirlash</button>
                                        <?php else: ?>
                                            <span class="inline-flex cursor-not-allowed items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-400" title="Kitob oʼchirib tashlangan">Tahrirlash</span>
                                        <?php endif; ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Sotuv oʼchirilsinmi?');">
                                            <input type="hidden" name="form_type" value="delete_sale">
                                            <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                            <button type="submit" class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-200">Oʼchirish</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($topSold): ?>
                <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($topSold as $row): ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="truncate font-semibold text-slate-900" title="<?= htmlspecialchars($row['title'] ?? 'Kitob') ?>"><?= htmlspecialchars($row['title'] ?? 'Kitob') ?></div>
                            <div class="mt-2 text-slate-600">Sotilgan: <span class="font-semibold text-slate-900"><?= (int) ($row['sold_quantity'] ?? 0) ?></span> ta</div>
                            <div class="text-slate-600">Omborda: <span class="font-semibold text-emerald-600"><?= (int) ($row['remaining_quantity'] ?? 0) ?></span> ta</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <button type="button" data-open-book-create class="fixed bottom-24 right-4 inline-flex items-center gap-2 rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg transition hover:bg-emerald-600 focus:outline-none focus:ring-4 focus:ring-emerald-300 sm:bottom-12 sm:right-6 md:bottom-8 md:right-8">
        <span class="text-lg">＋</span>
        <span>Kitob qoʼshish</span>
    </button>

    <div id="modal-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/40 backdrop-blur"></div>

    <div id="book-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="book-modal-title" class="text-lg font-semibold text-slate-900">Kitob qoʼshish</h2>
                    <p id="book-modal-subtitle" class="text-sm text-slate-600">Inventarga yangi kitob qoʼshing.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="book-form">
                <input type="hidden" name="form_type" value="create">
                <input type="hidden" name="book_id" value="">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Kitob nomi</label>
                    <input type="text" name="title" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Xarid narxi</label>
                        <input type="number" name="buy_price" min="0" step="0.01" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Sotuv narxi</label>
                        <input type="number" name="sell_price" min="0" step="0.01" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                    <input type="number" name="quantity" min="0" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="sell-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Sotuvni qayd etish</h2>
                    <p id="sell-modal-info" class="text-sm text-slate-600">Kitobni soting va toʼlov maʼlumotlarini kiriting.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="sell-form">
                <input type="hidden" name="form_type" value="sell">
                <input type="hidden" name="book_id" value="">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Sotiladigan miqdor</label>
                    <input type="number" name="sell_quantity" min="1" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <p id="sell-quantity-helper" class="mt-1 text-xs text-slate-500"></p>
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
                    <label class="block text-sm font-medium text-slate-700">Jami tushgan summa</label>
                    <input type="number" name="paid_amount" min="0" step="0.01" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 150000">
                    <p class="mt-1 text-xs text-slate-500">Boʼsh qoldirilsa, sotuv narxi va miqdor asosida avtomatik hisoblanadi.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                    <textarea name="note" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: Click orqali toʼlandi"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" id="sell-submit" class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Sotuvni saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="debt-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Qarzga berish</h2>
                    <p id="debt-modal-info" class="text-sm text-slate-600">Qarzdor maʼlumotlarini kiriting.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="debt-form">
                <input type="hidden" name="form_type" value="create_debt">
                <input type="hidden" name="book_id" value="">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700">Qarzdor ismi</label>
                        <input type="text" name="debtor_name" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Ism familiya">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Telefon</label>
                        <input type="tel" name="debtor_phone" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="90 123 45 67">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Guruh/klassi</label>
                        <input type="text" name="debtor_group" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 7-A">
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                        <input type="number" name="debt_quantity" min="1" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <p id="debt-quantity-helper" class="mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Kitob narxi</label>
                        <input type="number" name="debt_price" min="0" step="0.01" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 120000">
                        <p class="mt-1 text-xs text-slate-500">Bitta kitob uchun sotuv narxi.</p>
                    </div>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-900">Jami summa:</span>
                    <span id="debt-total-amount">0</span> soʼm
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                    <textarea name="debt_note" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 3 kun ichida qaytaradi"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" id="debt-submit" class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600">Qarzga berish</button>
                </div>
            </form>
        </div>
    </div>

    <div id="sale-modal" class="fixed inset-0 z-50 hidden items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Sotuvni tahrirlash</h2>
                    <p class="text-sm text-slate-600">Miqдор va toʼlov maʼlumotlarini yangilang.</p>
                </div>
                <button type="button" data-close-modal class="text-slate-400 transition hover:text-slate-600">✕</button>
            </div>
            <form method="post" class="mt-4 space-y-4" id="sale-form">
                <input type="hidden" name="form_type" value="edit_sale">
                <input type="hidden" name="sale_id" value="">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Sotilgan miqdor</label>
                    <input type="number" name="sale_quantity" min="1" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <p id="sale-quantity-helper" class="mt-1 text-xs text-slate-500"></p>
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium text-slate-700">Toʼlov usuli</span>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($paymentLabels as $value => $label): ?>
                            <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm transition hover:border-emerald-400">
                                <input type="radio" name="sale_payment_method" value="<?= htmlspecialchars($value) ?>" <?= $value === 'cash' ? 'checked' : '' ?> class="text-emerald-500 focus:ring-emerald-500">
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Jami tushum</label>
                    <input type="number" name="sale_total_revenue" min="0" step="0.01" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Masalan: 200000">
                    <p class="mt-1 text-xs text-slate-500">Agar boʼsh qoldirilsa, oldingi birlik narxi asosida qayta hisoblanadi.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Izoh</label>
                    <textarea name="sale_note" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-close-modal class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" id="sale-submit" class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600">Oʼzgarishni saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const body = document.body;
        const backdrop = document.getElementById('modal-backdrop');
        const bookModal = document.getElementById('book-modal');
        const sellModal = document.getElementById('sell-modal');
        const debtModal = document.getElementById('debt-modal');
        const saleModal = document.getElementById('sale-modal');
        const modals = [bookModal, sellModal, debtModal, saleModal];

        function openModal(modal) {
            if (!modal) return;
            if (backdrop) {
                backdrop.classList.remove('hidden');
            }
            modals.forEach((el) => {
                if (el && el !== modal) {
                    el.classList.add('hidden');
                    el.classList.remove('flex');
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

        const bookForm = document.getElementById('book-form');
        const bookModalTitle = document.getElementById('book-modal-title');
        const bookModalSubtitle = document.getElementById('book-modal-subtitle');
        const createButton = document.querySelector('[data-open-book-create]');
        if (createButton) {
            createButton.addEventListener('click', () => {
                bookForm.reset();
                bookForm.form_type.value = 'create';
                bookForm.book_id.value = '';
                bookModalTitle.textContent = 'Kitob qoʼshish';
                bookModalSubtitle.textContent = 'Inventarga yangi kitob qoʼshing.';
                openModal(bookModal);
            });
        }
        document.querySelectorAll('[data-open-book-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-book-id');
                const title = button.getAttribute('data-book-title') || '';
                const buy = button.getAttribute('data-book-buy') || '0';
                const sellPrice = button.getAttribute('data-book-sell') || '0';
                const quantity = button.getAttribute('data-book-quantity') || '0';
                bookForm.form_type.value = 'update';
                bookForm.book_id.value = id;
                bookForm.title.value = title;
                bookForm.buy_price.value = buy;
                bookForm.sell_price.value = sellPrice;
                bookForm.quantity.value = quantity;
                bookModalTitle.textContent = 'Kitobni tahrirlash';
                bookModalSubtitle.textContent = 'Kitob maʼlumotlarini yangilang.';
                openModal(bookModal);
            });
        });

        const debtForm = document.getElementById('debt-form');
        const debtInfo = document.getElementById('debt-modal-info');
        const debtHelper = document.getElementById('debt-quantity-helper');
        const debtTotal = document.getElementById('debt-total-amount');
        const debtSubmit = document.getElementById('debt-submit');
        const debtPriceInput = debtForm ? debtForm.querySelector('input[name="debt_price"]') : null;
        const debtQuantityInput = debtForm ? debtForm.querySelector('input[name="debt_quantity"]') : null;
        const uzFormatter = new Intl.NumberFormat('uz-UZ');

        function updateDebtTotal() {
            if (!debtForm || !debtTotal) {
                return;
            }
            const qty = debtQuantityInput ? parseInt(debtQuantityInput.value || '0', 10) : 0;
            const price = debtPriceInput ? parseFloat(debtPriceInput.value || '0') : 0;
            const total = qty > 0 && price > 0 ? qty * price : 0;
            debtTotal.textContent = uzFormatter.format(Math.max(total, 0));
        }

        if (debtQuantityInput) {
            debtQuantityInput.addEventListener('input', () => {
                updateDebtTotal();
            });
        }

        if (debtPriceInput) {
            debtPriceInput.addEventListener('input', () => {
                updateDebtTotal();
            });
        }

        document.querySelectorAll('[data-open-debt]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!debtForm) {
                    return;
                }
                const id = button.getAttribute('data-book-id');
                const title = button.getAttribute('data-book-title') || '';
                const quantity = parseInt(button.getAttribute('data-book-quantity') || '0', 10);
                const sellPrice = parseFloat(button.getAttribute('data-book-sell-price') || '0');

                debtForm.reset();
                debtForm.book_id.value = id || '';

                if (debtQuantityInput) {
                    debtQuantityInput.disabled = quantity <= 0;
                    debtQuantityInput.max = Math.max(quantity, 0);
                    debtQuantityInput.value = quantity > 0 ? 1 : '';
                }

                if (debtPriceInput) {
                    debtPriceInput.disabled = quantity <= 0;
                    debtPriceInput.value = Number.isFinite(sellPrice) && sellPrice > 0 ? sellPrice.toString() : '';
                }

                if (debtSubmit) {
                    const disabled = quantity <= 0;
                    debtSubmit.disabled = disabled;
                    debtSubmit.classList.toggle('opacity-50', disabled);
                    debtSubmit.classList.toggle('cursor-not-allowed', disabled);
                }

                if (debtHelper) {
                    debtHelper.textContent = quantity > 0
                        ? `Omborda: ${quantity} ta. Maksimal qarz miqdori ${quantity} ta.`
                        : 'Omborda mavjud emas.';
                }

                if (debtInfo) {
                    debtInfo.textContent = `${title} — omborda ${quantity} ta.`;
                }

                updateDebtTotal();
                openModal(debtModal);
            });
        });

        const sellForm = document.getElementById('sell-form');
        const sellHelper = document.getElementById('sell-quantity-helper');
        const sellInfo = document.getElementById('sell-modal-info');
        const sellSubmit = document.getElementById('sell-submit');
        const sellPaidInput = sellForm ? sellForm.querySelector('input[name="paid_amount"]') : null;
        let sellPaidTouched = false;

        function updateSellRevenueSuggestion() {
            if (!sellForm || !sellPaidInput || sellPaidTouched) {
                return;
            }
            const basePrice = parseFloat(sellForm.dataset.sellPrice || '0');
            const qty = parseInt(sellForm.sell_quantity.value || '0', 10);
            if (basePrice > 0 && qty > 0) {
                sellPaidInput.value = (basePrice * qty).toFixed(2);
            } else if (qty > 0) {
                sellPaidInput.value = '';
            } else {
                sellPaidInput.value = '';
            }
        }

        if (sellPaidInput) {
            sellPaidInput.addEventListener('input', () => {
                sellPaidTouched = sellPaidInput.value.trim() !== '';
            });
        }

        if (sellForm && sellForm.sell_quantity) {
            sellForm.sell_quantity.addEventListener('input', () => {
                updateSellRevenueSuggestion();
            });
        }

        document.querySelectorAll('[data-open-sell]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-book-id');
                const title = button.getAttribute('data-book-title') || '';
                const quantity = parseInt(button.getAttribute('data-book-quantity') || '0', 10);
                const sellPrice = parseFloat(button.getAttribute('data-book-sell-price') || '0');
                sellPaidTouched = false;
                sellForm.reset();
                sellForm.book_id.value = id;
                sellForm.dataset.sellPrice = Number.isFinite(sellPrice) ? sellPrice.toString() : '0';
                sellForm.sell_quantity.disabled = quantity <= 0;
                if (sellPaidInput) {
                    sellPaidInput.disabled = quantity <= 0;
                }
                if (sellSubmit) {
                    sellSubmit.disabled = quantity <= 0;
                    sellSubmit.classList.toggle('opacity-50', quantity <= 0);
                    sellSubmit.classList.toggle('cursor-not-allowed', quantity <= 0);
                }
                sellForm.sell_quantity.max = Math.max(quantity, 0);
                sellForm.sell_quantity.value = quantity > 0 ? 1 : '';
                updateSellRevenueSuggestion();
                sellHelper.textContent = quantity > 0 ? `Omborda: ${quantity} ta. Maksimal sotish miqdori ${quantity} ta.` : 'Omborda mavjud emas.';
                sellInfo.textContent = `${title} — omborda ${quantity} ta.`;
                openModal(sellModal);
            });
        });

        const saleForm = document.getElementById('sale-form');
        const saleHelper = document.getElementById('sale-quantity-helper');
        const saleRevenueInput = saleForm ? saleForm.querySelector('input[name="sale_total_revenue"]') : null;
        let saleRevenueTouched = false;

        function updateSaleRevenueSuggestion() {
            if (!saleForm || !saleRevenueInput || saleRevenueTouched) {
                return;
            }
            const unitRevenue = parseFloat(saleForm.dataset.unitRevenue || '0');
            const qty = parseInt(saleForm.sale_quantity.value || '0', 10);
            if (qty > 0 && !Number.isNaN(unitRevenue)) {
                saleRevenueInput.value = (unitRevenue * qty).toFixed(2);
            } else {
                saleRevenueInput.value = '';
            }
        }

        if (saleRevenueInput) {
            saleRevenueInput.addEventListener('input', () => {
                const raw = saleRevenueInput.value.trim();
                saleRevenueTouched = raw !== '';
                if (!saleForm) {
                    return;
                }
                const qty = parseInt(saleForm.sale_quantity.value || '0', 10);
                const normalized = raw.replace(/\s+/g, '').replace(',', '.');
                const numeric = parseFloat(normalized);
                if (qty > 0 && !Number.isNaN(numeric)) {
                    saleForm.dataset.unitRevenue = (numeric / qty).toString();
                }
            });
        }

        if (saleForm && saleForm.sale_quantity) {
            saleForm.sale_quantity.addEventListener('input', () => {
                updateSaleRevenueSuggestion();
            });
        }

        document.querySelectorAll('[data-open-sale]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-sale-id');
                const quantity = parseInt(button.getAttribute('data-sale-quantity') || '0', 10);
                const stock = parseInt(button.getAttribute('data-sale-stock') || '0', 10);
                const payment = button.getAttribute('data-sale-payment') || 'cash';
                const note = button.getAttribute('data-sale-note') || '';
                const revenue = parseFloat(button.getAttribute('data-sale-revenue') || '0');
                saleRevenueTouched = false;
                saleForm.reset();
                saleForm.dataset.unitRevenue = quantity > 0 && !Number.isNaN(revenue)
                    ? (revenue / quantity).toString()
                    : '0';
                saleForm.form_type.value = 'edit_sale';
                saleForm.sale_id.value = id;
                saleForm.sale_quantity.value = quantity;
                saleForm.sale_quantity.min = 1;
                saleForm.sale_quantity.max = quantity + Math.max(stock, 0);
                saleHelper.textContent = `Hozir omborda ${stock} ta qolgan.`;
                saleForm.querySelectorAll('input[name="sale_payment_method"]').forEach((radio) => {
                    radio.checked = radio.value === payment;
                });
                if (saleRevenueInput) {
                    saleRevenueInput.value = !Number.isNaN(revenue) ? revenue.toFixed(2) : '';
                }
                saleForm.sale_note.value = note;
                updateSaleRevenueSuggestion();
                openModal(saleModal);
            });
        });

        const showAllButton = document.querySelector('[data-action="show-all-books"]');
        if (showAllButton) {
            showAllButton.addEventListener('click', () => {
                document.querySelectorAll('[data-book-row]').forEach((row) => row.classList.remove('hidden'));
                showAllButton.classList.add('hidden');
            });
        }
    });
    </script>
</body>
</html>

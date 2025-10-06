<?php
require __DIR__ . '/db.php';

$accounts = ['KIDS', 'DAPA'];
$account = $_GET['account'] ?? 'KIDS';
if (!in_array($account, $accounts, true)) {
    header('Location: index.php');
    exit;
}

$errors = [];
$messages = [];

function tofloat($value)
{
    $normalized = str_replace([' ', ','], ['', '.'], (string)$value);
    return (float)$normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    try {
        if ($formType === 'create' || $formType === 'update') {
            $title = trim($_POST['title'] ?? '');
            $buyPrice = tofloat($_POST['buy_price'] ?? '0');
            $sellPrice = tofloat($_POST['sell_price'] ?? '0');
            $quantity = (int)($_POST['quantity'] ?? 0);

            if ($title === '' || $buyPrice <= 0 || $sellPrice <= 0 || $quantity < 0) {
                $errors[] = 'Maʼlumotlarni toʼgʼri kiriting. Narxlar 0 dan katta, miqdor esa manfiy boʼlmasligi kerak.';
            } else {
                if ($formType === 'create') {
                    $stmt = $pdo->prepare('INSERT INTO books (account, title, author, category, buy_price, sell_price, quantity) VALUES (?, ?, "", "", ?, ?, ?)');
                    $stmt->execute([$account, $title, $buyPrice, $sellPrice, $quantity]);
                    $messages[] = 'Kitob muvaffaqiyatli qoʼshildi!';
                } else {
                    $bookId = (int)($_POST['book_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT id FROM books WHERE id = ? AND account = ?');
                    $stmt->execute([$bookId, $account]);
                    if ($stmt->fetchColumn() === false) {
                        $errors[] = 'Kitob topilmadi.';
                    } else {
                        $update = $pdo->prepare('UPDATE books SET title = ?, buy_price = ?, sell_price = ?, quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account = ?');
                        $update->execute([$title, $buyPrice, $sellPrice, $quantity, $bookId, $account]);
                        $messages[] = 'Kitob maʼlumotlari yangilandi.';
                    }
                }
            }
        }

        if ($formType === 'delete') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM books WHERE id = ? AND account = ?');
            $stmt->execute([$bookId, $account]);
            if ($stmt->rowCount() > 0) {
                $messages[] = 'Kitob oʼchirildi.';
            } else {
                $errors[] = 'Kitob topilmadi yoki allaqachon oʼchirilgan.';
            }
        }

        if ($formType === 'sell') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            $sellQuantity = (int)($_POST['sell_quantity'] ?? 0);
            if ($sellQuantity <= 0) {
                $errors[] = 'Sotiladigan miqdor 1 dan kam boʼlmasligi kerak.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
                $stmt->execute([$bookId, $account]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    $errors[] = 'Kitob topilmadi.';
                } elseif ((int)$book['quantity'] < $sellQuantity) {
                    $errors[] = 'Yetarli miqdor mavjud emas.';
                } else {
                    $totalCost = (float)$book['buy_price'] * $sellQuantity;
                    $totalRevenue = (float)$book['sell_price'] * $sellQuantity;
                    $profit = $totalRevenue - $totalCost;

                    $pdo->beginTransaction();
                    try {
                        $insertSale = $pdo->prepare('INSERT INTO sales (account, book_id, quantity, total_cost, total_revenue, profit) VALUES (?, ?, ?, ?, ?, ?)');
                        $insertSale->execute([$account, $bookId, $sellQuantity, $totalCost, $totalRevenue, $profit]);

                        $updateBook = $pdo->prepare('UPDATE books SET quantity = quantity - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                        $updateBook->execute([$sellQuantity, $bookId]);

                        $pdo->commit();
                        $messages[] = 'Sotuv muvaffaqiyatli qayd etildi.';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Sotuvni saqlashda xatolik: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($formType === 'edit_sale') {
            $saleId = (int)($_POST['sale_id'] ?? 0);
            $newQuantity = (int)($_POST['sale_quantity'] ?? 0);
            if ($newQuantity <= 0) {
                $errors[] = 'Sotuv miqdori 1 dan kam boʼlmasligi kerak.';
            } else {
                $stmt = $pdo->prepare('SELECT s.*, b.quantity AS book_quantity, b.buy_price, b.sell_price FROM sales s JOIN books b ON b.id = s.book_id WHERE s.id = ? AND s.account = ?');
                $stmt->execute([$saleId, $account]);
                $sale = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sale) {
                    $errors[] = 'Sotuv topilmadi yoki tegishli kitob oʼchirilgan.';
                } else {
                    $oldQuantity = (int)$sale['quantity'];
                    $difference = $newQuantity - $oldQuantity;
                    if ($difference > 0 && (int)$sale['book_quantity'] < $difference) {
                        $errors[] = 'Kitob omborida yetarli miqdor mavjud emas.';
                    } else {
                        $unitCost = $oldQuantity > 0 ? ((float)$sale['total_cost'] / $oldQuantity) : (float)$sale['buy_price'];
                        $unitRevenue = $oldQuantity > 0 ? ((float)$sale['total_revenue'] / $oldQuantity) : (float)$sale['sell_price'];
                        $newTotalCost = $unitCost * $newQuantity;
                        $newTotalRevenue = $unitRevenue * $newQuantity;
                        $newProfit = $newTotalRevenue - $newTotalCost;

                        $pdo->beginTransaction();
                        try {
                            $updateBook = $pdo->prepare('UPDATE books SET quantity = quantity - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                            $updateBook->execute([$difference, $sale['book_id']]);

                            $updateSale = $pdo->prepare('UPDATE sales SET quantity = ?, total_cost = ?, total_revenue = ?, profit = ? WHERE id = ? AND account = ?');
                            $updateSale->execute([$newQuantity, $newTotalCost, $newTotalRevenue, $newProfit, $saleId, $account]);

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

        if ($formType === 'delete_sale') {
            $saleId = (int)($_POST['sale_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT s.*, b.id AS book_exists FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.id = ? AND s.account = ?');
            $stmt->execute([$saleId, $account]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                $errors[] = 'Sotuv topilmadi.';
            } elseif (!$sale['book_exists']) {
                $errors[] = 'Bu sotuvni oʼchirib boʼlmaydi, chunki tegishli kitob mavjud emas.';
            } else {
                $pdo->beginTransaction();
                try {
                    $restoreBook = $pdo->prepare('UPDATE books SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $restoreBook->execute([(int)$sale['quantity'], $sale['book_id']]);

                    $deleteSale = $pdo->prepare('DELETE FROM sales WHERE id = ? AND account = ?');
                    $deleteSale->execute([$saleId, $account]);

                    $pdo->commit();
                    $messages[] = 'Sotuv oʼchirildi va kitob miqdori qayta tiklandi.';
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

$booksStmt = $pdo->prepare('SELECT * FROM books WHERE account = ? ORDER BY title');
$booksStmt->execute([$account]);
$books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

$inventorySummaryStmt = $pdo->prepare('SELECT COUNT(*) AS total_books, SUM(quantity) AS total_quantity, SUM(buy_price * quantity) AS total_cost_value, SUM(sell_price * quantity) AS potential_revenue FROM books WHERE account = ?');
$inventorySummaryStmt->execute([$account]);
$inventorySummary = $inventorySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_books' => 0, 'total_quantity' => 0, 'total_cost_value' => 0, 'potential_revenue' => 0];

$salesSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, SUM(quantity) AS sold_quantity, SUM(total_revenue) AS revenue, SUM(profit) AS profit FROM sales WHERE account = ?');
$salesSummaryStmt->execute([$account]);
$salesSummary = $salesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'sold_quantity' => 0, 'revenue' => 0, 'profit' => 0];

$salesStmt = $pdo->prepare('SELECT s.*, b.title FROM sales s LEFT JOIN books b ON b.id = s.book_id WHERE s.account = ? ORDER BY s.sold_at DESC');
$salesStmt->execute([$account]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($account) ?> hisobi &mdash; Inventar boshqaruvi</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen p-6 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4 bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($account) ?> hisobidagi kitoblar</h1>
                <p class="text-slate-600">Inventar, sotuvlar va foyda koʼrsatkichlarini boshqaring.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="reports.php?account=<?= urlencode($account) ?>" class="px-4 py-2 rounded-xl bg-indigo-500 text-white hover:bg-indigo-600 transition">Hisobotlar</a>
                <a href="index.php" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 transition">&larr; Bosh sahifaga qaytish</a>
            </div>
        </header>

        <?php if ($errors): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-xl p-4">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($messages): ?>
            <div class="bg-emerald-100 border border-emerald-300 text-emerald-800 rounded-xl p-4">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($messages as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="grid lg:grid-cols-2 gap-6">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-900">Yangi kitob qoʼshish</h2>
                        <p class="text-slate-600 text-sm">Kitob nomi, xarid va sotish narxlarini kiriting.</p>
                    </div>
                </div>
                <form method="post" class="grid grid-cols-1 gap-4">
                    <input type="hidden" name="form_type" value="create">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Kitob nomi</label>
                        <input type="text" name="title" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sotib olish narxi (soʼm)</label>
                            <input type="number" step="0.01" min="0" name="buy_price" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sotish narxi (soʼm)</label>
                            <input type="number" step="0.01" min="0" name="sell_price" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                            <input type="number" min="0" name="quantity" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-sky-500 text-white hover:bg-sky-600 transition">Saqlash</button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">Inventar va sotuv statistikasi</h2>
                    <p class="text-slate-600 text-sm">Hisobingizdagi umumiy koʼrsatkichlar.</p>
                </div>
                <dl class="grid sm:grid-cols-2 gap-4">
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Kitob turlari soni</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= (int)$inventorySummary['total_books'] ?></dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Jami nusxalar</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= (int)$inventorySummary['total_quantity'] ?></dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Inventar xarid qiymati</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$inventorySummary['total_cost_value'], 2, '.', ' ') ?> soʼm</dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Potensial tushum</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$inventorySummary['potential_revenue'], 2, '.', ' ') ?> soʼm</dd>
                    </div>
                </dl>
                <div class="border-t border-slate-200 pt-4">
                    <dl class="grid sm:grid-cols-2 gap-4">
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Sotuvlar soni</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= (int)$salesSummary['sales_count'] ?></dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Sotilgan nusxalar</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= (int)$salesSummary['sold_quantity'] ?></dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Umumiy tushum</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= number_format((float)$salesSummary['revenue'], 2, '.', ' ') ?> soʼm</dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Umumiy foyda</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= number_format((float)$salesSummary['profit'], 2, '.', ' ') ?> soʼm</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">Inventar roʼyxati</h2>
                    <p class="text-sm text-slate-600">Kitoblarni yangilang, sotuvni boshlang yoki oʼchiring.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kitob nomi</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Sotib olish narxi</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Sotish narxi</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Miqdor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$books): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Hozircha kitoblar mavjud emas.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($books as $book): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($book['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$book['buy_price'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$book['sell_price'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$book['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" data-action="edit-book" data-book-id="<?= (int)$book['id'] ?>" data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>" data-book-buy-price="<?= htmlspecialchars($book['buy_price'], ENT_QUOTES) ?>" data-book-sell-price="<?= htmlspecialchars($book['sell_price'], ENT_QUOTES) ?>" data-book-quantity="<?= (int)$book['quantity'] ?>" class="px-3 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100">Tahrirlash</button>
                                        <button type="button" data-action="sell-book" data-book-id="<?= (int)$book['id'] ?>" data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>" data-book-max="<?= (int)$book['quantity'] ?>" class="px-3 py-1 rounded-lg bg-emerald-500 text-white hover:bg-emerald-600">Sotish</button>
                                        <form method="post" class="inline" onsubmit="return confirm('Rostdan ham oʼchirmoqchimisiz?');">
                                            <input type="hidden" name="form_type" value="delete">
                                            <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
                                            <button type="submit" class="px-3 py-1 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Oʼchirish</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">Sotilgan kitoblar jurnali</h2>
                    <p class="text-sm text-slate-600">Har bir sotuv uchun maʼlumotlarni koʼrib chiqing, tahrirlang yoki oʼchiring.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Sana</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kitob</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Miqdor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Tushum</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Foyda</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$sales): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-slate-500">Hozircha sotuvlar qayd etilmagan.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($sales as $sale): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm text-slate-600"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($sale['sold_at']))) ?></td>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($sale['title'] ?? 'Kitob oʼchirib tashlangan') ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$sale['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$sale['total_revenue'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-600 font-semibold"><?= number_format((float)$sale['profit'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" data-action="edit-sale" data-sale-id="<?= (int)$sale['id'] ?>" data-sale-quantity="<?= (int)$sale['quantity'] ?>" data-sale-title="<?= htmlspecialchars($sale['title'] ?? 'Kitob oʼchirib tashlangan', ENT_QUOTES) ?>" class="px-3 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100" <?= $sale['title'] ? '' : 'disabled' ?>>Tahrirlash</button>
                                        <form method="post" class="inline" onsubmit="return confirm('Sotuvni oʼchirishni tasdiqlaysizmi?');">
                                            <input type="hidden" name="form_type" value="delete_sale">
                                            <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
                                            <button type="submit" class="px-3 py-1 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" <?= $sale['title'] ? '' : 'disabled' ?>>Oʼchirish</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div id="editBookModal" class="fixed inset-0 hidden items-center justify-center px-4 z-50">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-6 space-y-4">
            <div class="flex items-start justify-between">
                <h3 class="text-xl font-semibold text-slate-900">Kitobni tahrirlash</h3>
                <button type="button" class="text-slate-500 hover:text-slate-700" data-modal-close>&times;</button>
            </div>
            <form method="post" id="editBookForm" class="grid grid-cols-1 gap-4">
                <input type="hidden" name="form_type" value="update">
                <input type="hidden" name="book_id" id="editBookId">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Kitob nomi</label>
                    <input type="text" name="title" id="editBookTitle" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                </div>
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Sotib olish narxi (soʼm)</label>
                        <input type="number" step="0.01" min="0" name="buy_price" id="editBookBuyPrice" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Sotish narxi (soʼm)</label>
                        <input type="number" step="0.01" min="0" name="sell_price" id="editBookSellPrice" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                        <input type="number" min="0" name="quantity" id="editBookQuantity" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100" data-modal-close>Bekor qilish</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-sky-500 text-white hover:bg-sky-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="sellBookModal" class="fixed inset-0 hidden items-center justify-center px-4 z-50">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 space-y-4">
            <div class="flex items-start justify-between">
                <h3 class="text-xl font-semibold text-slate-900" id="sellModalTitle">Kitobni sotish</h3>
                <button type="button" class="text-slate-500 hover:text-slate-700" data-modal-close>&times;</button>
            </div>
            <form method="post" id="sellBookForm" class="space-y-4">
                <input type="hidden" name="form_type" value="sell">
                <input type="hidden" name="book_id" id="sellBookId">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Sotiladigan miqdor</label>
                    <input type="number" min="1" name="sell_quantity" id="sellBookQuantity" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    <p class="text-xs text-slate-500 mt-1" id="sellBookHint"></p>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100" data-modal-close>Bekor qilish</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-500 text-white hover:bg-emerald-600">Tasdiqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editSaleModal" class="fixed inset-0 hidden items-center justify-center px-4 z-50">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 space-y-4">
            <div class="flex items-start justify-between">
                <h3 class="text-xl font-semibold text-slate-900" id="saleModalTitle">Sotuvni tahrirlash</h3>
                <button type="button" class="text-slate-500 hover:text-slate-700" data-modal-close>&times;</button>
            </div>
            <form method="post" id="editSaleForm" class="space-y-4">
                <input type="hidden" name="form_type" value="edit_sale">
                <input type="hidden" name="sale_id" id="editSaleId">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Sotilgan miqdor</label>
                    <input type="number" min="1" name="sale_quantity" id="editSaleQuantity" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100" data-modal-close>Bekor qilish</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-500 text-white hover:bg-indigo-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const body = document.body;
        const editBookModal = document.getElementById('editBookModal');
        const sellBookModal = document.getElementById('sellBookModal');
        const editSaleModal = document.getElementById('editSaleModal');

        function openModal(modal) {
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            body.classList.add('overflow-hidden');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('flex');
            modal.classList.add('hidden');
            if (!document.querySelector('.fixed.inset-0.flex:not(.hidden)')) {
                body.classList.remove('overflow-hidden');
            }
        }

        document.querySelectorAll('[data-modal-close]').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = btn.closest('.fixed');
                closeModal(modal);
            });
        });

        document.querySelectorAll('[data-action="edit-book"]').forEach(button => {
            button.addEventListener('click', () => {
                document.getElementById('editBookId').value = button.dataset.bookId;
                document.getElementById('editBookTitle').value = button.dataset.bookTitle;
                document.getElementById('editBookBuyPrice').value = button.dataset.bookBuyPrice;
                document.getElementById('editBookSellPrice').value = button.dataset.bookSellPrice;
                document.getElementById('editBookQuantity').value = button.dataset.bookQuantity;
                openModal(editBookModal);
            });
        });

        const sellSubmitButton = document.querySelector('#sellBookForm button[type="submit"]');

        document.querySelectorAll('[data-action="sell-book"]').forEach(button => {
            button.addEventListener('click', () => {
                const max = parseInt(button.dataset.bookMax, 10) || 0;
                document.getElementById('sellBookId').value = button.dataset.bookId;
                document.getElementById('sellBookQuantity').value = max > 0 ? 1 : 0;
                document.getElementById('sellBookQuantity').max = max;
                document.getElementById('sellBookHint').textContent = max > 0 ? `Omborda mavjud: ${max} ta.` : 'Bu kitob omborda qolmagan.';
                document.getElementById('sellBookQuantity').readOnly = max === 0;
                document.getElementById('sellModalTitle').textContent = `"${button.dataset.bookTitle}" kitobini sotish`;
                if (sellSubmitButton) {
                    sellSubmitButton.disabled = max === 0;
                    sellSubmitButton.classList.toggle('opacity-50', max === 0);
                    sellSubmitButton.classList.toggle('cursor-not-allowed', max === 0);
                }
                openModal(sellBookModal);
            });
        });

        document.querySelectorAll('[data-action="edit-sale"]').forEach(button => {
            button.addEventListener('click', () => {
                if (button.hasAttribute('disabled')) {
                    return;
                }
                document.getElementById('editSaleId').value = button.dataset.saleId;
                document.getElementById('editSaleQuantity').value = button.dataset.saleQuantity;
                document.getElementById('saleModalTitle').textContent = `"${button.dataset.saleTitle}" sotuvini tahrirlash`;
                openModal(editSaleModal);
            });
        });

        [editBookModal, sellBookModal, editSaleModal].forEach(modal => {
            if (!modal) return;
            modal.addEventListener('click', event => {
                if (event.target.dataset.modalClose !== undefined) {
                    closeModal(modal);
                }
            });
        });
    </script>
</body>
</html>

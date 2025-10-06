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
    $normalized = str_replace([' ', ','], ['', '.'], $value);
    return (float)$normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    try {
        if ($formType === 'create' || $formType === 'update') {
            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $buyPrice = tofloat($_POST['buy_price'] ?? '0');
            $sellPrice = tofloat($_POST['sell_price'] ?? '0');
            $quantity = (int)($_POST['quantity'] ?? 0);

            if ($title === '' || $buyPrice <= 0 || $sellPrice <= 0 || $quantity < 0) {
                $errors[] = 'Maʼlumotlarni toʼgʼri kiriting. Narxlar 0 dan katta boʼlishi kerak.';
            } else {
                if ($formType === 'create') {
                    $stmt = $pdo->prepare('INSERT INTO books (account, title, author, category, buy_price, sell_price, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$account, $title, $author, $category, $buyPrice, $sellPrice, $quantity]);
                    $messages[] = 'Kitob muvaffaqiyatli qoʼshildi!';
                } else {
                    $bookId = (int)($_POST['book_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT id FROM books WHERE id = ? AND account = ?');
                    $stmt->execute([$bookId, $account]);
                    if ($stmt->fetchColumn() === false) {
                        $errors[] = 'Kitob topilmadi.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE books SET title = ?, author = ?, category = ?, buy_price = ?, sell_price = ?, quantity = ? WHERE id = ?');
                        $stmt->execute([$title, $author, $category, $buyPrice, $sellPrice, $quantity, $bookId]);
                        $messages[] = 'Kitob yangilandi.';
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
                } elseif ($book['quantity'] < $sellQuantity) {
                    $errors[] = 'Yetarli miqdor mavjud emas.';
                } else {
                    $totalCost = $book['buy_price'] * $sellQuantity;
                    $totalRevenue = $book['sell_price'] * $sellQuantity;
                    $profit = $totalRevenue - $totalCost;

                    $pdo->beginTransaction();
                    try {
                        $insertSale = $pdo->prepare('INSERT INTO sales (account, book_id, quantity, total_cost, total_revenue, profit) VALUES (?, ?, ?, ?, ?, ?)');
                        $insertSale->execute([$account, $bookId, $sellQuantity, $totalCost, $totalRevenue, $profit]);

                        $updateBook = $pdo->prepare('UPDATE books SET quantity = quantity - ? WHERE id = ?');
                        $updateBook->execute([$sellQuantity, $bookId]);

                        $pdo->commit();
                        $messages[] = 'Sotuv qayd etildi.';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Sotuvni saqlashda xatolik: ' . $e->getMessage();
                    }
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

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editBook = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ? AND account = ?');
    $stmt->execute([$editId, $account]);
    $editBook = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editBook) {
        $editBook = null;
        $errors[] = 'Tahrirlash uchun kitob topilmadi.';
    }
}

$inventorySummaryStmt = $pdo->prepare('SELECT COUNT(*) AS total_books, SUM(quantity) AS total_quantity, SUM(buy_price * quantity) AS total_cost_value, SUM(sell_price * quantity) AS potential_revenue FROM books WHERE account = ?');
$inventorySummaryStmt->execute([$account]);
$inventorySummary = $inventorySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_books' => 0, 'total_quantity' => 0, 'total_cost_value' => 0, 'potential_revenue' => 0];

$salesSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, SUM(quantity) AS sold_quantity, SUM(total_revenue) AS revenue, SUM(profit) AS profit FROM sales WHERE account = ?');
$salesSummaryStmt->execute([$account]);
$salesSummary = $salesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'sold_quantity' => 0, 'revenue' => 0, 'profit' => 0];

$recentSalesStmt = $pdo->prepare('SELECT s.*, b.title FROM sales s JOIN books b ON b.id = s.book_id WHERE s.account = ? ORDER BY s.sold_at DESC LIMIT 10');
$recentSalesStmt->execute([$account]);
$recentSales = $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($account) ?> hisobidagi kitoblar</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen p-6 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($account) ?> hisobidagi kitoblar</h1>
                <p class="text-slate-600">Inventar, sotuvlar va foyda hisobotlari.</p>
            </div>
            <a href="index.php" class="text-sm text-slate-600 hover:text-slate-900 transition">&larr; Bosh sahifaga qaytish</a>
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
                <div>
                    <h2 class="text-xl font-semibold text-slate-900"><?= $editBook ? 'Kitobni tahrirlash' : 'Yangi kitob qoʼshish' ?></h2>
                    <p class="text-slate-600 text-sm">Xarid va sotish narxlarini kiritib, foydani kuzatib boring.</p>
                </div>
                <form method="post" class="grid grid-cols-1 gap-4">
                    <input type="hidden" name="form_type" value="<?= $editBook ? 'update' : 'create' ?>">
                    <?php if ($editBook): ?>
                        <input type="hidden" name="book_id" value="<?= (int)$editBook['id'] ?>">
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Kitob nomi</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($editBook['title'] ?? '') ?>" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Muallif</label>
                            <input type="text" name="author" value="<?= htmlspecialchars($editBook['author'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Janr / toifa</label>
                            <input type="text" name="category" value="<?= htmlspecialchars($editBook['category'] ?? '') ?>" class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sotib olish narxi (soʻm)</label>
                            <input type="number" step="0.01" min="0" name="buy_price" value="<?= htmlspecialchars($editBook['buy_price'] ?? '') ?>" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sotish narxi (soʻm)</label>
                            <input type="number" step="0.01" min="0" name="sell_price" value="<?= htmlspecialchars($editBook['sell_price'] ?? '') ?>" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                        <input type="number" min="0" name="quantity" value="<?= htmlspecialchars($editBook['quantity'] ?? '') ?>" required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        <?php if ($editBook): ?>
                            <a href="?account=<?= urlencode($account) ?>" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-600 hover:bg-slate-100">Bekor qilish</a>
                        <?php endif; ?>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-sky-500 text-white hover:bg-sky-600 transition">Saqlash</button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">Inventar hisobotlari</h2>
                    <p class="text-slate-600 text-sm">Hisobingizdagi umumiy koʼrsatkichlar.</p>
                </div>
                <dl class="grid sm:grid-cols-2 gap-4">
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Jami kitob turlari</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= (int)$inventorySummary['total_books'] ?></dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Jami nusxalar soni</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= (int)$inventorySummary['total_quantity'] ?></dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Inventarning xarid qiymati</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$inventorySummary['total_cost_value'], 2, '.', ' ') ?> soʼm</dd>
                    </div>
                    <div class="p-4 bg-slate-100 rounded-2xl">
                        <dt class="text-sm text-slate-600">Potensial tushum</dt>
                        <dd class="text-2xl font-semibold text-slate-900"><?= number_format((float)$inventorySummary['potential_revenue'], 2, '.', ' ') ?> soʼm</dd>
                    </div>
                </dl>

                <div class="border-t border-slate-200 pt-4">
                    <h3 class="text-lg font-semibold text-slate-900">Sotuvlar hisobotlari</h3>
                    <dl class="grid sm:grid-cols-2 gap-4 mt-2">
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Sotuvlar soni</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= (int)$salesSummary['sales_count'] ?></dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Sotilgan nusxalar</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= (int)$salesSummary['sold_quantity'] ?></dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Tushum</dt>
                            <dd class="text-2xl font-semibold text-emerald-700"><?= number_format((float)$salesSummary['revenue'], 2, '.', ' ') ?> soʼm</dd>
                        </div>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100">
                            <dt class="text-sm text-slate-600">Foyda</dt>
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
                    <p class="text-sm text-slate-600">Har bir kitob uchun CRUD amallarini bajaring.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Kitob</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Muallif</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Janr</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Sotib olish narxi</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Sotish narxi</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Miqdor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$books): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-slate-500">Hozircha kitoblar mavjud emas.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($books as $book): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($book['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-slate-600"><?= htmlspecialchars($book['author']) ?></td>
                                <td class="px-4 py-3 text-sm text-slate-600"><?= htmlspecialchars($book['category']) ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$book['buy_price'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$book['sell_price'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$book['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="?account=<?= urlencode($account) ?>&edit=<?= (int)$book['id'] ?>" class="px-3 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100">Tahrirlash</a>
                                        <form method="post" class="inline" onsubmit="return confirm('Rostdan ham oʼchirmoqchimisiz?');">
                                            <input type="hidden" name="form_type" value="delete">
                                            <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
                                            <button type="submit" class="px-3 py-1 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Oʼchirish</button>
                                        </form>
                                        <form method="post" class="inline-flex items-center gap-2">
                                            <input type="hidden" name="form_type" value="sell">
                                            <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
                                            <input type="number" name="sell_quantity" min="1" max="<?= (int)$book['quantity'] ?>" class="w-20 rounded-lg border border-slate-300 bg-slate-50 px-2 py-1 text-sm" placeholder="Miqdor">
                                            <button type="submit" class="px-3 py-1 rounded-lg bg-emerald-500 text-white hover:bg-emerald-600">Sotish</button>
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
                    <h2 class="text-xl font-semibold text-slate-900">Soʼnggi sotuvlar</h2>
                    <p class="text-sm text-slate-600">Oxirgi 10 ta operatsiya.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Sana</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Kitob</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Miqdor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Tushum</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Foyda</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php if (!$recentSales): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Hozircha sotuvlar qayd etilmagan.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($recentSales as $sale): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-sm text-slate-600"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($sale['sold_at']))) ?></td>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><?= htmlspecialchars($sale['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= (int)$sale['quantity'] ?></td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600"><?= number_format((float)$sale['total_revenue'], 2, '.', ' ') ?> soʼm</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-600 font-semibold"><?= number_format((float)$sale['profit'], 2, '.', ' ') ?> soʼm</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>

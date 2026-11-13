<?php
session_start();

// Načtení .env souboru z root adresáře (o úroveň výš než src/)
$envPath = dirname(__DIR__) . '/.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignorování komentářů a řádků bez '='
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'"); // Ořízne mezery i případné uvozovky

        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

$dbHost = getenv('host') ?: getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('port') ?: getenv('DB_PORT') ?: '5432';
$dbUser = getenv('user') ?: getenv('DB_USER') ?: 'username';
$dbPass = getenv('pass') ?: getenv('DB_PASS') ?: 'pass';
$dbName = getenv('db')   ?: getenv('DB_NAME') ?: 'ramlmao';

try {
    $pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die("Nelze se spojit s DB: " . htmlspecialchars($e->getMessage()));
}

/* ==========================================================================
   BACKEND PROPOJENÍ NA METODY Z init.sql:
   - Dny (spojový seznam):
       InitializeLinkedList('days', firstValue)
       LLAddNode(value, 'days')
       LLGetAll('days')
       LLDeleteNode('days', target_val)
   - Záznamy dne (seznam nad polem):
       InitializeArray(array_name, first_val)
       AddToArray(value, array_name)
       GetAllValues(array_name)
       GetValueAtIndex(index, array_name)
       RemoveAtIndex(index, array_name)
       Fixed_Array_Headers tabulka
========================================================================== */

function getDaysList(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT val FROM LLGetAll('days')");
        $days = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($days) ? $days : [];
    } catch (Exception $e) {
        return [];
    }
}

function getEntriesForDay(PDO $pdo, string $date): array {
    $arrName = 'day_' . $date;
    try {
        $stmt = $pdo->prepare("SELECT val FROM GetAllValues(:name)");
        $stmt->execute(['name' => $arrName]);
        $res = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($res) ? $res : [];
    } catch (Exception $e) {
        // Fallback pro bezpečné čtení po indexech přes GetValueAtIndex
        try {
            $stmtHeader = $pdo->prepare("SELECT size FROM Fixed_Array_Headers WHERE name = :name");
            $stmtHeader->execute(['name' => $arrName]);
            $size = (int)$stmtHeader->fetchColumn();
            $items = [];
            for ($i = 0; $i < $size; $i++) {
                $stmtVal = $pdo->prepare("SELECT GetValueAtIndex(:idx, :name)");
                $stmtVal->execute(['idx' => $i, 'name' => $arrName]);
                $val = $stmtVal->fetchColumn();
                if ($val !== null && $val !== false) {
                    $items[] = $val;
                }
            }
            return $items;
        } catch (Exception $e2) {
            return [];
        }
    }
}

function parseCzechDate(string $d): ?DateTimeImmutable {
    $d = trim($d);
    $dt = DateTimeImmutable::createFromFormat('!d.m.Y', $d);
    return ($dt && $dt->format('d.m.Y') === $d) ? $dt : null;
}

// Obsluha formulářových akcí
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'novy') {
            $rawDate = trim($_POST['date'] ?? '');
            $text = trim($_POST['text'] ?? '');

            $dt = parseCzechDate($rawDate);
            if (!$dt) {
                $_SESSION['flash'] = "Chybný formát data. Zadejte datum ve formátu dd.MM.yyyy (např. 12.11.2026).";
                $_SESSION['flashType'] = 'error';
                header("Location: ?modal=novy&date=" . urlencode($rawDate) . "&text=" . urlencode($text));
                exit;
            }

            if ($text === '') {
                $_SESSION['flash'] = "Záznam nesmí být prázdný.";
                $_SESSION['flashType'] = 'error';
                header("Location: ?modal=novy&date=" . urlencode($rawDate));
                exit;
            }

            $dateFormatted = $dt->format('d.m.Y');
            $days = getDaysList($pdo);
            $currIndex = isset($_GET['day_idx']) ? (int)$_GET['day_idx'] : 0;
            if ($currIndex < 0 || $currIndex >= count($days)) {
                $currIndex = 0;
            }
            $currentDayStr = $days[$currIndex] ?? null;

            // 1. Prázdný deník -> vytvoření prvního dne a prvního pole
            if (empty($days)) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT InitializeLinkedList('days', :date)");
                $stmt->execute(['date' => $dateFormatted]);

                $stmtArr = $pdo->prepare("SELECT InitializeArray(:name, :firstVal)");
                $stmtArr->execute(['name' => 'day_' . $dateFormatted, 'firstVal' => $text]);
                $pdo->commit();

                $_SESSION['flash'] = "Deník založen: vytvořen první den '$dateFormatted' s novým záznamem.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?day_idx=0");
                exit;
            }

            $currentDt = parseCzechDate($currentDayStr);
            $nextDayStr = $days[$currIndex + 1] ?? null;
            $nextDt = $nextDayStr ? parseCzechDate($nextDayStr) : null;

            if ($dateFormatted === $currentDayStr) {
                // A) Datum = aktuální den -> přidat záznam na konec pole daného dne
                $arrName = 'day_' . $dateFormatted;
                $stmtCheck = $pdo->prepare("SELECT first_pointer FROM Fixed_Array_Headers WHERE name = :name");
                $stmtCheck->execute(['name' => $arrName]);
                $exists = $stmtCheck->fetchColumn();

                if ($exists !== false && $exists !== null) {
                    $stmtAdd = $pdo->prepare("SELECT AddToArray(:val, :name)");
                    $stmtAdd->execute(['val' => $text, 'name' => $arrName]);
                } else {
                    $stmtInit = $pdo->prepare("SELECT InitializeArray(:name, :val)");
                    $stmtInit->execute(['name' => $arrName, 'val' => $text]);
                }

                $_SESSION['flash'] = "Záznam byl přidán do dne $dateFormatted.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?day_idx=$currIndex");
                exit;

            } elseif (!in_array($dateFormatted, $days, true)) {
                // B) Nový den -> vkládá se čistě na konec spojového seznamu přes LLAddNode
                $pdo->beginTransaction();
                $stmtAdd = $pdo->prepare("SELECT LLAddNode(:val, 'days')");
                $stmtAdd->execute(['val' => $dateFormatted]);

                $stmtArr = $pdo->prepare("SELECT InitializeArray(:name, :firstVal)");
                $stmtArr->execute(['name' => 'day_' . $dateFormatted, 'firstVal' => $text]);
                $pdo->commit();

                $newIndex = count($days);
                $_SESSION['flash'] = "Nový den '$dateFormatted' byl vložen na konec spojového seznamu (LLAddNode).";
                $_SESSION['flashType'] = 'success';
                header("Location: ?day_idx=$newIndex");
                exit;

            } else {
                // C) Den už v seznamu existuje, ale není aktuálně otevřený
                $targetIdx = array_search($dateFormatted, $days, true);
                $_SESSION['flash'] = "Den $dateFormatted již v seznamu existuje. Přejděte na něj pro přidání záznamu.";
                $_SESSION['flashType'] = 'info';
                header("Location: ?day_idx=$targetIdx");
                exit;
            }

        } elseif ($action === 'smaz_potvrzeno') {
            // Smazání záznamu dne přes RemoveAtIndex
            $currIndex = (int)($_POST['day_idx'] ?? 0);
            $entryIdx = (int)($_POST['entry_idx'] ?? -1);
            $days = getDaysList($pdo);
            $currentDayStr = $days[$currIndex] ?? null;

            if ($currentDayStr && $entryIdx >= 0) {
                $arrName = 'day_' . $currentDayStr;

                $stmt = $pdo->prepare("SELECT RemoveAtIndex(:idx, :name)");
                $stmt->execute(['idx' => $entryIdx, 'name' => $arrName]);

                // Zbývají v daném dni ještě nějaké záznamy?
                $stmtHeader = $pdo->prepare("SELECT size FROM Fixed_Array_Headers WHERE name = :name");
                $stmtHeader->execute(['name' => $arrName]);
                $size = (int)$stmtHeader->fetchColumn();

                if ($size <= 0) {
                    // "Den bez záznamů zmizí. Pak se zobrazí následující den, když není, tak předchozí."
                    $pdo->prepare("DELETE FROM Fixed_Array_Headers WHERE name = :name")->execute(['name' => $arrName]);
                    $pdo->prepare("SELECT LLDeleteNode('days', :date)")->execute(['date' => $currentDayStr]);

                    $remainingDays = getDaysList($pdo);
                    if (empty($remainingDays)) {
                        $_SESSION['flash'] = "Záznam byl smazán. Den '$currentDayStr' byl bez záznamů odstraněn. Deník je prázdný.";
                        $_SESSION['flashType'] = 'success';
                        header("Location: ?");
                        exit;
                    }

                    if ($currIndex >= count($remainingDays)) {
                        $currIndex = max(0, count($remainingDays) - 1);
                    }
                    $_SESSION['flash'] = "Záznam smazán. Den '$currentDayStr' byl bez záznamů odstraněn.";
                    $_SESSION['flashType'] = 'success';
                    header("Location: ?day_idx=$currIndex");
                    exit;
                }

                $_SESSION['flash'] = "Záznam byl úspěšně smazán.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?day_idx=$currIndex");
                exit;
            }

        } elseif ($action === 'uloz') {
            // Uložení celého deníku do JSON souboru ke stažení
            $days = getDaysList($pdo);
            $exportData = [];
            foreach ($days as $d) {
                $exportData[] = [
                    'datum' => $d,
                    'zaznamy' => getEntriesForDay($pdo, $d)
                ];
            }
            $json = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="denik_zaloha_' . date('Y-m-d_H-i') . '.json"');
            echo $json;
            exit;

        } elseif ($action === 'nacti') {
            // Načtení deníku ze souboru a obnova v RAM
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $raw = file_get_contents($_FILES['file']['tmp_name']);
                $data = json_decode($raw, true);

                if (!is_array($data)) {
                    $_SESSION['flash'] = "Chybný formát souboru (očekáván platný JSON export).";
                    $_SESSION['flashType'] = 'error';
                    header("Location: ?");
                    exit;
                }

                $pdo->beginTransaction();
                $pdo->exec("DELETE FROM Linked_List_Headers");
                $pdo->exec("DELETE FROM Fixed_Array_Headers");
                $pdo->exec("UPDATE motherboard SET ram_slot = decode(repeat('00', 4096), 'hex') WHERE id = 1");

                $daysToCreate = [];
                foreach ($data as $item) {
                    $d = $item['datum'] ?? null;
                    $entries = $item['zaznamy'] ?? [];
                    if ($d && !empty($entries)) {
                        $daysToCreate[] = $d;
                        $arrName = 'day_' . $d;
                        $first = array_shift($entries);
                        $stmt = $pdo->prepare("SELECT InitializeArray(:name, :val)");
                        $stmt->execute(['name' => $arrName, 'val' => $first]);

                        $stmtAdd = $pdo->prepare("SELECT AddToArray(:val, :name)");
                        foreach ($entries as $e) {
                            $stmtAdd->execute(['val' => $e, 'name' => $arrName]);
                        }
                    }
                }

                if (!empty($daysToCreate)) {
                    $firstDay = array_shift($daysToCreate);
                    $stmtInit = $pdo->prepare("SELECT InitializeLinkedList('days', :val)");
                    $stmtInit->execute(['val' => $firstDay]);

                    $stmtAddDay = $pdo->prepare("SELECT LLAddNode(:val, 'days')");
                    foreach ($daysToCreate as $d) {
                        $stmtAddDay->execute(['val' => $d]);
                    }
                }

                $pdo->commit();
                $_SESSION['flash'] = "Deník byl úspěšně obnoven ze souboru.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?day_idx=0");
                exit;
            } else {
                $_SESSION['flash'] = "Chyba při nahrávání souboru.";
                $_SESSION['flashType'] = 'error';
                header("Location: ?");
                exit;
            }

        } elseif ($action === 'zavri') {
            session_destroy();
            header("Location: ?");
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = "Chyba: " . $e->getMessage();
        $_SESSION['flashType'] = 'error';
        header("Location: ?");
        exit;
    }
}

// Flash
$flash = $_SESSION['flash'] ?? null;
$flashType = $_SESSION['flashType'] ?? 'info';
unset($_SESSION['flash'], $_SESSION['flashType']);

// Data dnů ze spojového seznamu
$days = getDaysList($pdo);
$totalDays = count($days);

$currIndex = isset($_GET['day_idx']) ? (int)$_GET['day_idx'] : 0;
if ($currIndex < 0) $currIndex = 0;
if ($totalDays > 0 && $currIndex >= $totalDays) $currIndex = $totalDays - 1;

$currentDayStr = $totalDays > 0 ? ($days[$currIndex] ?? null) : null;
$entries = $currentDayStr ? getEntriesForDay($pdo, $currentDayStr) : [];
$totalEntries = count($entries);

// Potvrzení smazání
$deleteConfirmIdx = isset($_GET['smaz_idx']) ? (int)$_GET['smaz_idx'] : null;
$deleteConfirmText = ($deleteConfirmIdx !== null && isset($entries[$deleteConfirmIdx])) ? $entries[$deleteConfirmIdx] : null;

// Informace o poli a paměti RAM pro aktuální den
$arrayInfo = null;
if ($currentDayStr) {
    try {
        $stmt = $pdo->prepare("SELECT capacity, size, first_pointer FROM Fixed_Array_Headers WHERE name = :name");
        $stmt->execute(['name' => 'day_' . $currentDayStr]);
        $arrayInfo = $stmt->fetch();
    } catch (Exception $e) {}
}

$ramUsed = 0;
try {
    $stmt = $pdo->query("SELECT encode(ram_slot, 'hex') AS hex FROM motherboard WHERE id = 1");
    $hex = $stmt->fetchColumn();
    if ($hex) {
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i += 2) {
            if ($hex[$i] !== '0' || $hex[$i+1] !== '0') $ramUsed++;
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projekt ALM – Deník</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --surface-sub: #f1f5f9;
            --text-main: #0f172a;
            --text-sub: #64748b;
            --border: #e2e8f0;
            --border-strong: #cbd5e1;
            --primary: #0f172a;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --mono: 'Fira Code', ui-monospace, Menlo, Consolas, monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg);
            color: var(--text-main);
            font-family: var(--sans);
            font-size: 14px;
            line-height: 1.5;
            padding: 24px 16px;
        }

        .app-window {
            max-width: 880px;
            margin: 0 auto;
            background: var(--surface);
            border: 1px solid var(--border-strong);
            border-radius: 4px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }

        /* Top Header */
        .window-header {
            padding: 16px 20px;
            border-bottom: 2px solid var(--text-main);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .window-header h1 {
            font-size: 18px;
            font-weight: 700;
        }
        .meta-status {
            font-family: var(--mono);
            font-size: 12px;
            display: flex;
            gap: 16px;
            color: var(--text-sub);
        }
        .meta-status strong {
            color: var(--text-main);
        }

        /* Toolbar dle zadání */
        .toolbar {
            background: var(--surface-sub);
            border-bottom: 1px solid var(--border);
            padding: 10px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .btn-group {
            display: inline-flex;
            gap: 4px;
        }
        .btn {
            font-family: var(--sans);
            font-size: 13px;
            font-weight: 600;
            padding: 6px 12px;
            background: #ffffff;
            border: 1px solid var(--border-strong);
            color: var(--text-main);
            border-radius: 3px;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn:hover:not(.disabled) {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .btn.disabled {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }
        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .btn-primary:hover:not(.disabled) {
            background: #334155;
        }
        .btn-danger {
            color: var(--danger);
            border-color: #fca5a5;
        }
        .btn-danger:hover {
            background: var(--danger-bg);
        }

        /* Flash */
        .flash {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            font-weight: 500;
        }
        .flash-success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .flash-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        /* Window Body */
        .window-body {
            padding: 24px 20px;
            min-height: 380px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .day-title-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }
        .day-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .day-meta {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--text-sub);
            display: flex;
            gap: 12px;
        }

        /* Entries List (záznamy dne nad polem) */
        .entries-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .entry-card {
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 14px 16px;
            background: #ffffff;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .entry-card:hover {
            border-color: var(--border-strong);
        }
        .entry-num {
            font-family: var(--mono);
            font-size: 13px;
            font-weight: 700;
            color: var(--text-sub);
            width: 28px;
            flex-shrink: 0;
            padding-top: 1px;
        }
        .entry-content {
            flex-grow: 1;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 14px;
        }

        /* Empty state */
        .empty-state {
            margin: auto 0;
            text-align: center;
            padding: 48px 20px;
            color: var(--text-sub);
        }
        .empty-state h2 {
            font-size: 18px;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 50;
        }
        .modal-box {
            background: #ffffff;
            border: 1px solid var(--border-strong);
            border-radius: 4px;
            width: 100%;
            max-width: 480px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .modal-box h3 {
            font-size: 16px;
            font-weight: 700;
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-sub);
        }
        input[type="text"], textarea {
            font-family: var(--sans);
            font-size: 13px;
            padding: 8px 10px;
            border: 1px solid var(--border-strong);
            border-radius: 3px;
            outline: none;
        }
        input[type="text"]:focus, textarea:focus {
            border-color: var(--primary);
        }
        textarea {
            resize: vertical;
            min-height: 90px;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            border-top: 1px solid var(--border);
            padding-top: 12px;
        }
    </style>
</head>
<body>

<div class="app-window">
    <!-- Stavový řádek dle zadání -->
    <div class="window-header">
        <div>
            <h1>Deník (ALM)</h1>
        </div>
        <div class="meta-status">
            <div>Dny: <strong><?= $totalDays ?></strong></div>
            <div>Záznamů dnes: <strong><?= $totalEntries ?></strong></div>
            <?php if ($currentDayStr): ?>
                <div>Aktuální den: <strong><?= htmlspecialchars($currentDayStr) ?></strong></div>
            <?php endif; ?>
            <div>RAM: <strong><?= $ramUsed ?> / 4096 B</strong></div>
        </div>
    </div>

    <!-- Toolbar se všemi operacemi ze zadání -->
    <div class="toolbar">
        <div class="btn-group">
            <a href="?day_idx=0" class="btn <?= ($totalDays === 0 || $currIndex === 0) ? 'disabled' : '' ?>" title="zacatek">
                &laquo; začátek
            </a>
            <a href="?day_idx=<?= max(0, $currIndex - 1) ?>" class="btn <?= ($totalDays === 0 || $currIndex === 0) ? 'disabled' : '' ?>" title="predchozi">
                &lsaquo; předchozí
            </a>
            <a href="?day_idx=<?= min($totalDays - 1, $currIndex + 1) ?>" class="btn <?= ($totalDays === 0 || $currIndex >= $totalDays - 1) ? 'disabled' : '' ?>" title="dalsi">
                následující &rsaquo;
            </a>
            <a href="?day_idx=<?= max(0, $totalDays - 1) ?>" class="btn <?= ($totalDays === 0 || $currIndex >= $totalDays - 1) ? 'disabled' : '' ?>" title="konec">
                konec &raquo;
            </a>
        </div>

        <div style="width: 1px; height: 24px; background: var(--border-strong); margin: 0 4px;"></div>

        <!-- Operace: novy -->
        <a href="?day_idx=<?= $currIndex ?>&modal=novy" class="btn btn-primary" title="novy">
            + nový záznam
        </a>

        <!-- Operace: uloz -->
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="uloz">
            <button type="submit" class="btn" <?= $totalDays === 0 ? 'disabled' : '' ?> title="uloz">
                ulož do souboru
            </button>
        </form>

        <!-- Operace: nacti -->
        <label class="btn" style="cursor: pointer;" title="nacti">
            načti ze souboru
            <input type="file" id="filePicker" accept=".json" style="display: none;" onchange="document.getElementById('importSubmit').submit();">
        </label>
        <form id="importSubmit" method="POST" enctype="multipart/form-data" style="display: none;">
            <input type="hidden" name="action" value="nacti">
            <input type="file" id="fileHidden" name="file" onchange="this.form.submit();">
        </form>
        <script>
            document.getElementById('filePicker').addEventListener('change', function() {
                var h = document.getElementById('fileHidden');
                h.files = this.files;
                document.getElementById('importSubmit').submit();
            });
        </script>

        <!-- Operace: zavri -->
        <form method="POST" style="margin-left: auto;" onsubmit="return confirm('Opravdu ukončit relaci deníku?');">
            <input type="hidden" name="action" value="zavri">
            <button type="submit" class="btn" title="zavri">zavři</button>
        </form>
    </div>

    <!-- Flash hláška -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= $flashType ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Tělo: aktuální den a očíslované záznamy dne -->
    <div class="window-body">
        <?php if ($totalDays === 0): ?>
            <div class="empty-state">
                <h2>Deník je prázdný</h2>
                <p style="margin-top: 6px;">Po spuštění je deník prázdný. Klikněte na <strong>+ nový záznam</strong> pro zadání data a prvního textu.</p>
                <div style="margin-top: 16px;">
                    <a href="?modal=novy" class="btn btn-primary">+ Vytvořit první záznam</a>
                </div>
            </div>
        <?php else: ?>
            <div class="day-title-row">
                <div class="day-title"><?= htmlspecialchars($currentDayStr) ?></div>
                <div class="day-meta">
                    <span>Den <?= $currIndex + 1 ?> z <?= $totalDays ?></span>
                    <?php if ($arrayInfo): ?>
                        <span>| Pole: <?= $arrayInfo['size'] ?> / <?= $arrayInfo['capacity'] ?> položek</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="entries-list">
                <?php foreach ($entries as $idx => $content): ?>
                    <div class="entry-card">
                        <div class="entry-num"><?= $idx + 1 ?>.</div>
                        <div class="entry-content"><?= nl2br(htmlspecialchars($content)) ?></div>
                        <div>
                            <a href="?day_idx=<?= $currIndex ?>&smaz_idx=<?= $idx ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;" title="smaz">
                                smazat
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================
     MODÁL: novy
     Pravidla dle zadání:
     - Datum = aktuální den -> přidá se na konec jeho seznamu (AddToArray)
     - Datum mezi aktuálním a následujícím -> vznikne nový den za aktuálním
     - Jinak se zápis odmítne
======================================================== -->
<?php if (isset($_GET['modal']) && $_GET['modal'] === 'novy'): ?>
    <?php
        $prefillDate = $_GET['date'] ?? ($currentDayStr ?: date('d.m.Y'));
        $prefillText = $_GET['text'] ?? '';
    ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Nový záznam</h3>
            <form method="POST">
                <input type="hidden" name="action" value="novy">
                <input type="hidden" name="day_idx" value="<?= $currIndex ?>">

                <div class="form-group">
                    <label>Datum (dd.MM.yyyy):</label>
                    <input type="text" name="date" value="<?= htmlspecialchars($prefillDate) ?>" placeholder="např. 12.11.2026" required autofocus>
                    <?php if ($currentDayStr): ?>
                        <div style="font-size: 11px; color: var(--text-sub); margin-top: 3px;">
                            Aktuální den: <strong><?= htmlspecialchars($currentDayStr) ?></strong>
                            <?php if (isset($days[$currIndex + 1])): ?>
                                | Následující den: <strong><?= htmlspecialchars($days[$currIndex + 1]) ?></strong>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 8px;">
                    <label>Text záznamu (víceřádkový):</label>
                    <textarea name="text" placeholder="Zadejte text nového záznamu..." required><?= htmlspecialchars($prefillText) ?></textarea>
                </div>

                <div class="modal-footer">
                    <a href="?day_idx=<?= $currIndex ?>" class="btn">Zrušit</a>
                    <button type="submit" class="btn btn-primary">Uložit záznam</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================
     MODÁL: smaz (potvrzení mazání vybraného záznamu)
     "uživatel vybere záznam, program ho zobrazí a vyžádá potvrzení"
======================================================== -->
<?php if ($deleteConfirmIdx !== null && $deleteConfirmText !== null): ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Potvrzení smazání záznamu</h3>
            <p style="font-size: 13px; color: var(--text-sub);">
                Opravdu chcete smazat záznam č. <strong><?= $deleteConfirmIdx + 1 ?></strong> ze dne <strong><?= htmlspecialchars($currentDayStr) ?></strong>?
            </p>
            <div style="background: #f8fafc; border: 1px solid var(--border); padding: 12px; border-radius: 4px; font-size: 13px; white-space: pre-wrap;">
                <?= htmlspecialchars($deleteConfirmText) ?>
            </div>
            <p style="font-size: 12px; color: #b91c1c;">
                <em>Pokud po smazání v daném dni nezbude žádný záznam, den automaticky zanikne.</em>
            </p>
            <div class="modal-footer">
                <a href="?day_idx=<?= $currIndex ?>" class="btn">Zpět</a>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="smaz_potvrzeno">
                    <input type="hidden" name="day_idx" value="<?= $currIndex ?>">
                    <input type="hidden" name="entry_idx" value="<?= $deleteConfirmIdx ?>">
                    <button type="submit" class="btn btn-danger">Ano, smazat záznam</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>

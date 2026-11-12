<?php
session_start();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbUser = getenv('DB_USER') ?: 'username';
$dbPass = getenv('DB_PASS') ?: 'pass';
$dbName = getenv('DB_NAME') ?: 'ramlmao';

try {
    $pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die("Nelze se připojit k PostgreSQL: " . htmlspecialchars($e->getMessage()));
}

/* ==========================================================
   DNY ULOŽENY ČISTĚ V JEDNOM SPOJOVÉM SEZNAMU 'days'
   Používají se přímo SQL metody z init.sql:
   - InitializeLinkedList('days', firstValue)
   - LLAddNode(value, 'days')
   - LLGetAll('days')
   - LLDeleteNode('days', target_val)
========================================================== */

function getDays(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT val FROM LLGetAll('days')");
        $res = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($res) ? $res : [];
    } catch (Exception $e) {
        return [];
    }
}

function parseCzechDate(string $d): ?DateTimeImmutable {
    $d = trim($d);
    $dt = DateTimeImmutable::createFromFormat('!d.m.Y', $d);
    return ($dt && $dt->format('d.m.Y') === $d) ? $dt : null;
}

// Obsluha akcí
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'novy_den') {
            $rawDate = trim($_POST['date'] ?? '');
            $dt = parseCzechDate($rawDate);

            if (!$dt) {
                $_SESSION['flash'] = "Chybný formát data. Zadejte datum ve formátu dd.MM.yyyy (např. 12.11.2026).";
                $_SESSION['flashType'] = 'error';
                header("Location: ?modal=novy&date=" . urlencode($rawDate));
                exit;
            }

            $dateFormatted = $dt->format('d.m.Y');
            $days = getDays($pdo);
            $currIdx = isset($_GET['idx']) ? (int)$_GET['idx'] : 0;
            if ($currIdx < 0 || $currIdx >= count($days)) {
                $currIdx = 0;
            }
            $currentDayStr = $days[$currIdx] ?? null;

            if (empty($days)) {
                // Deník je prázdný -> vytvoříme první uzel v 'days'
                $stmt = $pdo->prepare("SELECT InitializeLinkedList('days', :date)");
                $stmt->execute(['date' => $dateFormatted]);
                $_SESSION['flash'] = "Vytvořen první den: $dateFormatted.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?idx=0");
                exit;
            }

            // Již existuje tento den v seznamu?
            if (in_array($dateFormatted, $days, true)) {
                $foundIdx = array_search($dateFormatted, $days, true);
                $_SESSION['flash'] = "Den $dateFormatted již v seznamu existuje.";
                $_SESSION['flashType'] = 'info';
                header("Location: ?idx=$foundIdx");
                exit;
            }

            $currentDt = parseCzechDate($currentDayStr);
            $nextDayStr = $days[$currIdx + 1] ?? null;
            $nextDt = $nextDayStr ? parseCzechDate($nextDayStr) : null;

            // Kontrola pravidla: Datum mezi aktuálním a následujícím dnem
            if ($nextDt && ($dt > $currentDt && $dt < $nextDt)) {
                // Vložit nový den za aktuální
                $newDays = [];
                for ($i = 0; $i <= $currIdx; $i++) {
                    $newDays[] = $days[$i];
                }
                $newDays[] = $dateFormatted;
                for ($i = $currIdx + 1; $i < count($days); $i++) {
                    $newDays[] = $days[$i];
                }

                $pdo->beginTransaction();
                $pdo->exec("DELETE FROM Linked_List_Headers WHERE name = 'days'");
                $stmt = $pdo->prepare("SELECT InitializeLinkedList('days', :val)");
                $stmt->execute(['val' => $newDays[0]]);

                $stmtAdd = $pdo->prepare("SELECT LLAddNode(:val, 'days')");
                for ($i = 1; $i < count($newDays); $i++) {
                    $stmtAdd->execute(['val' => $newDays[$i]]);
                }
                $pdo->commit();

                $newIdx = $currIdx + 1;
                $_SESSION['flash'] = "Den $dateFormatted byl vložen za aktuální den.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?idx=$newIdx");
                exit;

            } elseif (!$nextDt && ($dt > $currentDt)) {
                // Jsme na konci a datum je vyšší -> přidat na konec přes LLAddNode
                $stmt = $pdo->prepare("SELECT LLAddNode(:val, 'days')");
                $stmt->execute(['val' => $dateFormatted]);

                $newIdx = count($days);
                $_SESSION['flash'] = "Den $dateFormatted byl přidán na konec seznamu.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?idx=$newIdx");
                exit;

            } else {
                // Zápis odmítnut
                $range = $currentDayStr;
                if ($nextDayStr) {
                    $range .= " a $nextDayStr";
                } else {
                    $range .= " a novější";
                }
                $_SESSION['flash'] = "Zápis odmítnut! Datum musí ležet mezi $range.";
                $_SESSION['flashType'] = 'error';
                header("Location: ?idx=$currIdx");
                exit;
            }

        } elseif ($action === 'smaz_den') {
            $currIdx = (int)($_POST['idx'] ?? 0);
            $targetDate = $_POST['date'] ?? '';

            if ($targetDate !== '') {
                $stmt = $pdo->prepare("SELECT LLDeleteNode('days', :date)");
                $stmt->execute(['date' => $targetDate]);

                $days = getDays($pdo);
                if (empty($days)) {
                    $_SESSION['flash'] = "Den $targetDate byl smazán. Seznam dnů je nyní prázdný.";
                    $_SESSION['flashType'] = 'success';
                    header("Location: ?");
                    exit;
                }

                if ($currIdx >= count($days)) {
                    $currIdx = count($days) - 1;
                }
                $_SESSION['flash'] = "Den $targetDate byl úspěšně smazán ze spojového seznamu.";
                $_SESSION['flashType'] = 'success';
                header("Location: ?idx=$currIdx");
                exit;
            }

        } elseif ($action === 'uloz') {
            $days = getDays($pdo);
            $export = [
                'linked_list' => 'days',
                'pocet_dnu' => count($days),
                'dny' => $days
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="dny_spojovy_seznam.json"');
            echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        } elseif ($action === 'nacti') {
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $data = json_decode(file_get_contents($_FILES['file']['tmp_name']), true);
                $importDays = $data['dny'] ?? (is_array($data) ? $data : []);

                if (!empty($importDays) && is_array($importDays)) {
                    $pdo->beginTransaction();
                    $pdo->exec("DELETE FROM Linked_List_Headers WHERE name = 'days'");
                    $first = array_shift($importDays);
                    $stmt = $pdo->prepare("SELECT InitializeLinkedList('days', :val)");
                    $stmt->execute(['val' => $first]);

                    $stmtAdd = $pdo->prepare("SELECT LLAddNode(:val, 'days')");
                    foreach ($importDays as $d) {
                        if (is_string($d) && trim($d) !== '') {
                            $stmtAdd->execute(['val' => trim($d)]);
                        }
                    }
                    $pdo->commit();

                    $_SESSION['flash'] = "Spojový seznam dnů byl úspěšně načten ze souboru.";
                    $_SESSION['flashType'] = 'success';
                    header("Location: ?idx=0");
                    exit;
                } else {
                    $_SESSION['flash'] = "Soubor neobsahuje platná data dnů.";
                    $_SESSION['flashType'] = 'error';
                    header("Location: ?");
                    exit;
                }
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

// Data dnů
$days = getDays($pdo);
$totalDays = count($days);

$currIdx = isset($_GET['idx']) ? (int)$_GET['idx'] : 0;
if ($currIdx < 0) $currIdx = 0;
if ($totalDays > 0 && $currIdx >= $totalDays) $currIdx = $totalDays - 1;

$currentDay = $totalDays > 0 ? ($days[$currIdx] ?? null) : null;
$deleteConfirm = isset($_GET['smaz_potvrdit']) ? $_GET['smaz_potvrdit'] : null;

// RAM info
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
    <title>ALM Deník – Spojový seznam dnů</title>
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
            --accent: #2563eb;
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
            padding: 28px 16px;
        }

        .window {
            max-width: 780px;
            margin: 0 auto;
            background: var(--surface);
            border: 1px solid var(--border-strong);
            border-radius: 4px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }

        /* Top Header */
        header {
            padding: 16px 20px;
            border-bottom: 2px solid var(--text-main);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-title h1 {
            font-size: 17px;
            font-weight: 700;
        }
        .header-title p {
            font-size: 12px;
            color: var(--text-sub);
        }
        .meta-stats {
            display: flex;
            gap: 16px;
            font-family: var(--mono);
            font-size: 12px;
            color: var(--text-sub);
        }
        .meta-stats strong {
            color: var(--text-main);
        }

        /* Toolbar: Operace zadání */
        .toolbar {
            background: var(--surface-sub);
            border-bottom: 1px solid var(--border);
            padding: 10px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .nav-group {
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
            background: var(--text-main);
            color: #ffffff;
            border-color: var(--text-main);
        }
        .btn-primary:hover {
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
            font-size: 13px;
            font-weight: 500;
            border-bottom: 1px solid var(--border);
        }
        .flash-success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .flash-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .flash-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }

        /* Window Body */
        .content {
            padding: 32px 24px;
            min-height: 280px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* Den Detail */
        .day-display {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .day-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-sub);
            font-weight: 600;
        }
        .day-value {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.02em;
            font-family: var(--sans);
        }
        .node-pointer-info {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--text-sub);
            background: var(--surface-sub);
            padding: 4px 10px;
            border-radius: 3px;
            border: 1px solid var(--border);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 32px 0;
            color: var(--text-sub);
        }
        .empty-state h2 {
            font-size: 18px;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        /* Seznam uzlů linkovaného seznamu dole */
        .nodes-strip {
            border-top: 1px solid var(--border);
            padding: 14px 20px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            font-family: var(--mono);
            font-size: 12px;
        }
        .node-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 3px;
            text-decoration: none;
            color: var(--text-main);
            white-space: nowrap;
        }
        .node-item.active {
            border-color: var(--accent);
            background: #eff6ff;
            font-weight: 600;
            color: var(--accent);
        }
        .arrow {
            color: var(--text-sub);
            user-select: none;
        }

        /* Modal */
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
            max-width: 440px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
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
        input[type="text"] {
            font-family: var(--sans);
            font-size: 13px;
            padding: 8px 10px;
            border: 1px solid var(--border-strong);
            border-radius: 3px;
            outline: none;
        }
        input[type="text"]:focus {
            border-color: var(--accent);
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

<div class="window">
    <!-- Horní stavový pruh -->
    <header>
        <div class="header-title">
            <h1>Deník &ndash; Spojový seznam dnů (ALM)</h1>
            <p>Implementováno čistě nad spojovým seznamem <code>days</code> v PostgreSQL RAM slotu</p>
        </div>
        <div class="meta-stats">
            <div>Dny: <strong><?= $totalDays ?></strong></div>
            <div>Pozice: <strong><?= $totalDays > 0 ? ($currIdx + 1) . " / $totalDays" : "0 / 0" ?></strong></div>
            <div>RAM: <strong><?= $ramUsed ?> / 4096 B</strong></div>
        </div>
    </header>

    <!-- Toolbar: Přesné operace ze zadání -->
    <div class="toolbar">
        <!-- Listování spojovým seznamem -->
        <div class="nav-group">
            <a href="?idx=0" class="btn <?= ($totalDays === 0 || $currIdx === 0) ? 'disabled' : '' ?>" title="zacatek">
                &laquo; začátek
            </a>
            <a href="?idx=<?= max(0, $currIdx - 1) ?>" class="btn <?= ($totalDays === 0 || $currIdx === 0) ? 'disabled' : '' ?>" title="predchozi">
                &lsaquo; předchozí
            </a>
            <a href="?idx=<?= min($totalDays - 1, $currIdx + 1) ?>" class="btn <?= ($totalDays === 0 || $currIdx >= $totalDays - 1) ? 'disabled' : '' ?>" title="dalsi">
                následující &rsaquo;
            </a>
            <a href="?idx=<?= max(0, $totalDays - 1) ?>" class="btn <?= ($totalDays === 0 || $currIdx >= $totalDays - 1) ? 'disabled' : '' ?>" title="konec">
                konec &raquo;
            </a>
        </div>

        <div style="width: 1px; height: 24px; background: var(--border-strong); margin: 0 4px;"></div>

        <!-- Operace: novy (nový den do linked listu) -->
        <a href="?idx=<?= $currIdx ?>&modal=novy" class="btn btn-primary" title="novy">
            + nový den
        </a>

        <!-- Operace: smaz (smaže vybraný aktuální den) -->
        <?php if ($currentDay): ?>
            <a href="?idx=<?= $currIdx ?>&smaz_potvrdit=<?= urlencode($currentDay) ?>" class="btn btn-danger" title="smaz">
                smazat den
            </a>
        <?php else: ?>
            <button class="btn btn-danger disabled" disabled>smazat den</button>
        <?php endif; ?>

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
            <input type="file" id="fileIn" accept=".json" style="display: none;" onchange="document.getElementById('proxyUpload').submit();">
        </label>
        <form id="proxyUpload" method="POST" enctype="multipart/form-data" style="display: none;">
            <input type="hidden" name="action" value="nacti">
            <input type="file" id="realFile" name="file" onchange="this.form.submit()">
        </form>
        <script>
            document.getElementById('fileIn').addEventListener('change', function() {
                var real = document.getElementById('realFile');
                real.files = this.files;
                document.getElementById('proxyUpload').submit();
            });
        </script>

        <!-- Operace: zavri -->
        <form method="POST" style="margin-left: auto;" onsubmit="return confirm('Opravdu ukončit relaci?');">
            <input type="hidden" name="action" value="zavri">
            <button type="submit" class="btn" title="zavri">zavři</button>
        </form>
    </div>

    <!-- Flash zprávy -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= $flashType ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Tělo: Zobrazení aktuálního dne -->
    <div class="content">
        <?php if ($totalDays === 0): ?>
            <div class="empty-state">
                <h2>Spojový seznam je prázdný</h2>
                <p>Deník zatím neobsahuje žádné dny. Vytvořte první den kliknutím na tlačítko <strong>+ nový den</strong>.</p>
                <div style="margin-top: 16px;">
                    <a href="?modal=novy" class="btn btn-primary">+ Vytvořit první den</a>
                </div>
            </div>
        <?php else: ?>
            <div class="day-display">
                <div class="day-label">Aktuálně vybraný den</div>
                <div class="day-value"><?= htmlspecialchars($currentDay) ?></div>
                <div class="node-pointer-info">
                    Uzel spojového seznamu #<?= $currIdx + 1 ?> z <?= $totalDays ?> (LLGetAll)
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Řetězec uzlů linked listu (pro vizuální přehled procházení) -->
    <?php if ($totalDays > 0): ?>
        <div class="nodes-strip">
            <span style="color: var(--text-sub); font-size: 11px; text-transform: uppercase; font-weight: bold;">HEAD &rarr;</span>
            <?php foreach ($days as $i => $d): ?>
                <a href="?idx=<?= $i ?>" class="node-item <?= $i === $currIdx ? 'active' : '' ?>">
                    <span><?= htmlspecialchars($d) ?></span>
                </a>
                <?php if ($i < $totalDays - 1): ?>
                    <span class="arrow">&rarr;</span>
                <?php else: ?>
                    <span class="arrow">&rarr; NULL</span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ==========================================
     MODÁL: nový den (zadání data)
     Pravidla:
     - První den: vytvoří head_pointer
     - Datum mezi aktuálním a dalším: vloží za aktuální
     - Jinak odmítnuto
========================================== -->
<?php if (isset($_GET['modal']) && $_GET['modal'] === 'novy'): ?>
    <?php
        $prefillDate = $_GET['date'] ?? date('d.m.Y');
    ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Nový den do spojového seznamu</h3>
            <form method="POST">
                <input type="hidden" name="action" value="novy_den">
                <input type="hidden" name="idx" value="<?= $currIdx ?>">

                <div class="form-group">
                    <label>Datum (dd.MM.yyyy):</label>
                    <input type="text" name="date" value="<?= htmlspecialchars($prefillDate) ?>" placeholder="např. 12.11.2026" required autofocus>
                    <?php if ($currentDay): ?>
                        <div style="font-size: 11px; color: var(--text-sub); margin-top: 4px;">
                            Aktuální den: <strong><?= htmlspecialchars($currentDay) ?></strong>
                            <?php if (isset($days[$currIdx + 1])): ?>
                                | Následující: <strong><?= htmlspecialchars($days[$currIdx + 1]) ?></strong>
                            <?php else: ?>
                                | (poslední uzel seznamu)
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer">
                    <a href="?idx=<?= $currIdx ?>" class="btn">Zrušit</a>
                    <button type="submit" class="btn btn-primary">Vložit den (LLAddNode)</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ==========================================
     MODÁL: potvrzení smazání dne
========================================== -->
<?php if ($deleteConfirm !== null): ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Potvrdit smazání dne</h3>
            <p style="font-size: 13px; color: var(--text-sub);">
                Opravdu si přejete vymazat den <strong><?= htmlspecialchars($deleteConfirm) ?></strong> ze spojového seznamu (voláním <code>LLDeleteNode</code>)?
            </p>
            <div class="modal-footer">
                <a href="?idx=<?= $currIdx ?>" class="btn">Zpět</a>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="smaz_den">
                    <input type="hidden" name="idx" value="<?= $currIdx ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($deleteConfirm) ?>">
                    <button type="submit" class="btn btn-danger">Ano, smazat den</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>

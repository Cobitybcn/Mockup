<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

// Punto #10: historial de transacciones de créditos
$txStmt = $pdo->prepare("
    SELECT amount, reason, created_at
    FROM credit_transactions
    WHERE user_id = :user_id
    ORDER BY id DESC
    LIMIT 10
");
$txStmt->execute(['user_id' => (int)$user['id']]);
$creditHistory = $txStmt->fetchAll();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account - The Artwork Curator</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Real payments are currently disabled. The beta uses internal credits to prepare the commercial architecture.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Account</h1>
                    <p><?= h($user['email']) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <section class="stats">
                <div class="stat-card">
                    <span>Available Credits</span>
                    <strong><?= h($user['credits']) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Plan</span>
                    <strong>Beta</strong>
                </div>
                <div class="stat-card">
                    <span>Payments</span>
                    <strong>Off</strong>
                </div>
                <div class="stat-card">
                    <span>Generation</span>
                    <strong>Active</strong>
                </div>
            </section>

            <section class="panel">
                <h2>Beta Credits</h2>
                <p>Each mockup generation uses 1 credit. Root image creation (Step 1) is free in Beta. Credits are refunded automatically if a generation fails.</p>

                <?php if ($creditHistory): ?>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--line); text-align: left;">
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;">Date</th>
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;">Amount</th>
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditHistory as $tx): ?>
                                <tr style="border-bottom: 1px solid var(--line);">
                                    <td style="padding: 8px 12px; color: var(--muted);"><?= h(substr((string)$tx['created_at'], 0, 16)) ?></td>
                                    <td style="padding: 8px 12px; font-weight: 600; color: <?= (int)$tx['amount'] > 0 ? 'var(--accent)' : 'var(--danger, #c0392b)' ?>;">
                                        <?= (int)$tx['amount'] > 0 ? '+' . h($tx['amount']) : h($tx['amount']) ?>
                                    </td>
                                    <td style="padding: 8px 12px; color: var(--ink);"><?= h($tx['reason']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--muted); margin-top: 12px;">No credit transactions yet.</p>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>Payments</h2>
                <p>Payment integration is pending. The beta operates with internal credits prior to connecting live transactions.</p>
            </section>
        </div>
    </main>
</div>
</body>
</html>

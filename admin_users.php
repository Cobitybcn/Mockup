<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

if (!Auth::isAdmin($currentUser)) {
    http_response_code(403);
    exit('You do not have access to this section.');
}

$pdo = Database::connection();
$saved = false;
$error = '';

// Handle credit adjustment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_credits') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetCredits = (int)($_POST['credits'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Admin adjustment';
    }

    if ($targetUserId > 0 && $targetCredits >= 0) {
        try {
            Database::setCredits($targetUserId, $targetCredits, $reason);
            $saved = true;
        } catch (Exception $e) {
            $error = 'Error updating credits: ' . $e->getMessage();
        }
    } else {
        $error = 'Invalid user ID or credit amount.';
    }
}

// Fetch users
$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();

// Fetch last 20 credit transactions
$transactions = $pdo->query('
    SELECT t.*, u.email as user_email 
    FROM credit_transactions t 
    JOIN users u ON t.user_id = u.id 
    ORDER BY t.created_at DESC 
    LIMIT 20
')->fetchAll();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Stats
$totalUsers = count($users);
$totalCredits = array_sum(array_column($users, 'credits'));
$totalAdmins = count(array_filter($users, fn($u) => !empty($u['is_admin'])));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Users & Credits - The Artwork Curator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .users-table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        .premium-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        .premium-table th {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            border-bottom: 2px solid var(--line);
            padding: 14px 16px;
        }

        .premium-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            color: var(--ink);
        }

        .premium-table tr:hover td {
            background-color: var(--accent-light);
        }

        .user-role-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-radius: var(--radius);
            border: 1px solid var(--line-dark);
            color: var(--muted);
        }

        .user-role-badge.admin {
            border-color: rgba(154, 123, 86, 0.3);
            color: var(--accent);
            background: rgba(154, 123, 86, 0.05);
        }

        .inline-credit-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .inline-credit-form input[type="number"] {
            width: 80px;
            padding: 6px 8px;
            font-size: 12px;
            margin: 0;
        }

        .inline-credit-form input[type="text"] {
            width: 150px;
            padding: 6px 8px;
            font-size: 12px;
            margin: 0;
        }

        .inline-credit-form button {
            width: auto;
            margin: 0;
            padding: 6px 12px;
            font-size: 11px;
        }

        .transaction-log {
            margin-top: 32px;
        }

        .transaction-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 700;
            border-radius: var(--radius);
        }

        .transaction-badge.positive {
            color: #2D5A27;
            background: #E8F5E7;
        }

        .transaction-badge.negative {
            color: var(--danger);
            background: #F5E7E7;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Administration panel for managing registered users and their credit balances.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Users & Credits</h1>
                    <p>Load credits, promote users, and audit mockup generation balance transactions.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="admin_api_keys.php">API Settings</a>
                    <a class="button-link secondary" href="root_album.php">Root Artworks</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Credits adjusted successfully.</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <!-- General System Stats -->
            <div class="stats">
                <div class="stat-card">
                    <span>Total Registered Users</span>
                    <strong><?= $totalUsers ?></strong>
                </div>
                <div class="stat-card">
                    <span>Total Active Credits</span>
                    <strong><?= $totalCredits ?></strong>
                </div>
                <div class="stat-card">
                    <span>Administrator Accounts</span>
                    <strong><?= $totalAdmins ?></strong>
                </div>
                <div class="stat-card">
                    <span>Mockups Cost Rate</span>
                    <strong>1 <span style="font-size: 14px; color: var(--muted); font-family: var(--font-sans);">credit/gen</span></strong>
                </div>
            </div>

            <!-- Users Directory List -->
            <section class="panel">
                <h2>Users Directory</h2>
                <p style="color: var(--muted); margin-bottom: 16px;">
                    Review registered artist accounts and adjust their credit balances in real-time.
                </p>
                <div class="users-table-container">
                    <table class="premium-table">
                        <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Credits</th>
                            <th style="width: 420px;">Adjust Credit Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><code>#<?= (int)$u['id'] ?></code></td>
                                <td><strong><?= h($u['name'] !== '' ? $u['name'] : 'N/A') ?></strong></td>
                                <td><?= h($u['email']) ?></td>
                                <td>
                                    <?php if (!empty($u['is_admin'])): ?>
                                        <span class="user-role-badge admin">Admin</span>
                                    <?php else: ?>
                                        <span class="user-role-badge">Artist</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: var(--font-serif); font-size: 18px; font-weight: 600;">
                                        <?= (int)$u['credits'] ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" class="inline-credit-form">
                                        <input type="hidden" name="action" value="update_credits">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <input type="number" name="credits" value="<?= (int)$u['credits'] ?>" min="0" required placeholder="Credits">
                                        <input type="text" name="reason" placeholder="Reason (e.g. Purchase, Promo)" required>
                                        <button type="submit" class="button">Set</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Credit Transactions Audit Log -->
            <section class="panel transaction-log">
                <h2>Recent Transactions (Audit Log)</h2>
                <p style="color: var(--muted); margin-bottom: 16px;">
                    Historical transactions of credit adjustments (mockup generation costs, administrative additions, and error refunds).
                </p>
                <div class="users-table-container">
                    <table class="premium-table">
                        <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>User Email</th>
                            <th>Amount</th>
                            <th>Reason</th>
                            <th>Timestamp</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--muted); padding: 20px;">
                                    No transactions recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><code>#<?= (int)$t['id'] ?></code></td>
                                    <td><?= h($t['user_email']) ?></td>
                                    <td>
                                        <?php if ((int)$t['amount'] > 0): ?>
                                            <span class="transaction-badge positive">+<?= (int)$t['amount'] ?></span>
                                        <?php else: ?>
                                            <span class="transaction-badge negative"><?= (int)$t['amount'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($t['reason']) ?></td>
                                    <td><small><?= h(date('Y-m-d H:i:s', strtotime($t['created_at']))) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>

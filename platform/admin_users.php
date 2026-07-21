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
$notice = '';
$resetLink = '';
$csrf = Auth::csrfToken('admin_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::validateCsrf((string)($_POST['csrf'] ?? ''), 'admin_users')) {
    $error = 'Your form session expired. Reload the page and try again.';
    $_POST = [];
}

function admin_user_by_id(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_access') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetUser = admin_user_by_id($pdo, $targetUserId);
    $planCode = (string)($_POST['plan_code'] ?? FeatureAccess::PLAN_ARTIST_STUDIO);
    $submittedOverrides = is_array($_POST['feature_overrides'] ?? null) ? $_POST['feature_overrides'] : [];
    $featureAliases = [
        'website' => FeatureAccess::WEBSITE_MANAGE,
        'social' => FeatureAccess::SOCIAL_MANAGE,
        'video' => FeatureAccess::VIDEO_MANAGE,
    ];
    $overrideStates = [];
    foreach ($featureAliases as $alias => $feature) {
        $overrideStates[$feature] = (string)($submittedOverrides[$alias] ?? 'inherit');
    }

    if (!$targetUser) {
        $error = 'User not found.';
    } elseif (Auth::isAdmin($targetUser)) {
        $error = 'Admin accounts already have complete access.';
    } elseif (!array_key_exists($planCode, FeatureAccess::plans())) {
        $error = 'Invalid artist plan.';
    } else {
        try {
            FeatureAccess::updateUserAccess(
                $pdo,
                $targetUserId,
                $planCode,
                $overrideStates,
                'Updated by admin #' . (int)$currentUser['id'],
                (int)$currentUser['id'],
                'admin_users'
            );
            $notice = 'Artist plan and feature access updated successfully.';
        } catch (Throwable $e) {
            $error = 'Error updating access: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_password_reset') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetUser = admin_user_by_id($pdo, $targetUserId);

    if (!$targetUser) {
        $error = 'User not found.';
    } else {
        $result = Auth::requestPasswordReset((string)$targetUser['email']);
        $resetLink = (string)($result['debug_link'] ?? '');
        $notice = $resetLink !== ''
            ? 'Password reset link generated.'
            : 'Password reset email requested. If mail is configured, the user will receive it.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $confirmation = trim((string)($_POST['delete_confirmation'] ?? ''));
    $targetUser = admin_user_by_id($pdo, $targetUserId);

    if (!$targetUser) {
        $error = 'User not found.';
    } elseif ($targetUserId === (int)$currentUser['id']) {
        $error = 'You cannot delete your own account from this panel.';
    } elseif (Auth::isAdmin($targetUser)) {
        $error = 'Admin users are protected. Remove admin status manually before deleting.';
    } elseif ($confirmation !== 'DELETE') {
        $error = 'Type DELETE to confirm user deletion.';
    } else {
        try {
            Database::withBusyRetry(function () use ($pdo, $targetUserId): void {
                Database::beginWriteTransaction($pdo);
                try {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $stmt->execute(['id' => $targetUserId]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                    }
                    throw $e;
                }
            });
            $notice = 'User deleted successfully.';
        } catch (Throwable $e) {
            $error = 'Error deleting user: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_users') {
    $selectedIds = array_values(array_unique(array_map('intval', (array)($_POST['selected_user_ids'] ?? []))));
    $selectedIds = array_values(array_filter($selectedIds, static fn(int $id): bool => $id > 0));
    $confirmation = trim((string)($_POST['bulk_delete_confirmation'] ?? ''));

    if (empty($selectedIds)) {
        $error = 'Select at least one user to delete.';
    } elseif ($confirmation !== 'DELETE') {
        $error = 'Type DELETE to confirm bulk deletion.';
    } else {
        try {
            $deletedCount = Database::withBusyRetry(function () use ($pdo, $selectedIds, $currentUser): int {
                Database::beginWriteTransaction($pdo);
                try {
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id IN ({$placeholders})");
                    $stmt->execute($selectedIds);
                    $targets = $stmt->fetchAll();

                    $deletableIds = [];
                    foreach ($targets as $target) {
                        $targetId = (int)($target['id'] ?? 0);
                        if ($targetId <= 0 || $targetId === (int)$currentUser['id'] || Auth::isAdmin($target)) {
                            continue;
                        }
                        $deletableIds[] = $targetId;
                    }

                    if (!empty($deletableIds)) {
                        $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
                        $delete = $pdo->prepare("DELETE FROM users WHERE id IN ({$deletePlaceholders})");
                        $delete->execute($deletableIds);
                    }

                    $pdo->exec('COMMIT');
                    return count($deletableIds);
                } catch (Throwable $e) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                    }
                    throw $e;
                }
            });

            $notice = $deletedCount > 0
                ? "{$deletedCount} user(s) deleted successfully."
                : 'No users were deleted. Admin and current accounts are protected.';
        } catch (Throwable $e) {
            $error = 'Error deleting selected users: ' . $e->getMessage();
        }
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
$totalProArtists = count(array_filter($users, static fn(array $u): bool =>
    empty($u['is_admin']) && FeatureAccess::planForUser($u) === FeatureAccess::PLAN_ARTIST_PRO
));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Users & Credits - Artwork Mockups</title>
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

        .user-actions {
            display: grid;
            gap: 8px;
            min-width: 230px;
        }

        .user-actions-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .user-actions button {
            width: auto;
            margin: 0;
            padding: 6px 10px;
            font-size: 10px;
        }

        .bulk-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
        }

        .bulk-actions form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0;
        }

        .bulk-actions input[type="text"] {
            width: 96px;
            padding: 7px 9px;
            margin: 0;
            font-size: 11px;
        }

        .bulk-actions button {
            width: auto;
            margin: 0;
            padding: 7px 12px;
            font-size: 10px;
        }

        .delete-user-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .delete-user-form input[type="text"] {
            width: 86px;
            padding: 6px 8px;
            margin: 0;
            font-size: 11px;
        }

        .danger-button {
            border-color: var(--danger);
            background: var(--danger);
            color: #fff;
        }

        .danger-button:hover {
            border-color: #7f2d2d;
            background: #7f2d2d;
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

        .access-details {
            min-width: 250px;
        }

        .access-details summary {
            cursor: pointer;
            color: var(--ink);
            font-weight: 600;
        }

        .access-form {
            display: grid;
            gap: 9px;
            margin-top: 12px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
        }

        .access-form label {
            display: grid;
            gap: 4px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .access-form select {
            min-width: 210px;
            margin: 0;
            padding: 7px 8px;
            font-size: 11px;
        }

        .access-form button {
            width: 100%;
            margin: 2px 0 0;
            padding: 8px 12px;
            font-size: 10px;
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
                    <a class="button-link secondary" href="root_album.php">ArtWorks</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Credits adjusted successfully.</div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?>
                <div class="notice">
                    <?= h($notice) ?>
                    <?php if ($resetLink !== ''): ?>
                        <br><a href="<?= h($resetLink) ?>">Open password reset link</a>
                    <?php endif; ?>
                </div>
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
                    <span>Artist Pro Accounts</span>
                    <strong><?= $totalProArtists ?></strong>
                </div>
            </div>

            <!-- Users Directory List -->
            <section class="panel">
                <h2>Users Directory</h2>
                <p style="color: var(--muted); margin-bottom: 16px;">
                    Review registered artist accounts and adjust their credit balances in real-time.
                </p>
                <div class="bulk-actions">
                    <span style="color: var(--muted); font-size: 12px;">Select generated/test users to remove. Admin accounts are ignored.</span>
                    <form id="bulkDeleteUsersForm" method="post" onsubmit="return confirm('Delete selected users and their related data? This cannot be undone.');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="bulk_delete_users">
                        <input type="text" name="bulk_delete_confirmation" placeholder="DELETE" autocomplete="off" required>
                        <button type="submit" class="danger-button">Delete selected</button>
                    </form>
                </div>
                <div class="users-table-container">
                    <table class="premium-table">
                        <thead>
                        <tr>
                            <th>Select</th>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Plan & Feature Access</th>
                            <th>Credits</th>
                            <th style="width: 420px;">Adjust Credit Balance</th>
                            <th style="width: 260px;">Account Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <?php if ((int)$u['id'] !== (int)$currentUser['id'] && !Auth::isAdmin($u)): ?>
                                        <input type="checkbox" name="selected_user_ids[]" value="<?= (int)$u['id'] ?>" form="bulkDeleteUsersForm">
                                    <?php endif; ?>
                                </td>
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
                                    <?php if (Auth::isAdmin($u)): ?>
                                        <span class="user-role-badge admin">Complete access</span>
                                    <?php else: ?>
                                        <?php $userOverrides = FeatureAccess::overridesForUser((int)$u['id']); ?>
                                        <details class="access-details">
                                            <summary><?= h(FeatureAccess::planLabel($u)) ?></summary>
                                            <form method="post" class="access-form">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="update_access">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <label>
                                                    Artist plan
                                                    <select name="plan_code">
                                                        <?php foreach (FeatureAccess::plans() as $planCode => $planLabel): ?>
                                                            <option value="<?= h($planCode) ?>" <?= FeatureAccess::planForUser($u) === $planCode ? 'selected' : '' ?>><?= h($planLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <?php foreach (['website' => FeatureAccess::WEBSITE_MANAGE, 'social' => FeatureAccess::SOCIAL_MANAGE, 'video' => FeatureAccess::VIDEO_MANAGE] as $featureAlias => $featureKey): ?>
                                                    <?php $overrideState = array_key_exists($featureKey, $userOverrides) ? ($userOverrides[$featureKey] ? 'allow' : 'deny') : 'inherit'; ?>
                                                    <label>
                                                        <?= h(FeatureAccess::overridableFeatures()[$featureKey]) ?> override
                                                        <select name="feature_overrides[<?= h($featureAlias) ?>]">
                                                            <option value="inherit" <?= $overrideState === 'inherit' ? 'selected' : '' ?>>According to plan</option>
                                                            <option value="allow" <?= $overrideState === 'allow' ? 'selected' : '' ?>>Beta access enabled</option>
                                                            <option value="deny" <?= $overrideState === 'deny' ? 'selected' : '' ?>>Explicitly disabled</option>
                                                        </select>
                                                    </label>
                                                <?php endforeach; ?>
                                                <button type="submit">Save access</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: var(--font-serif); font-size: 18px; font-weight: 600;">
                                        <?= (int)$u['credits'] ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" class="inline-credit-form">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="update_credits">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <input type="number" name="credits" value="<?= (int)$u['credits'] ?>" min="0" required placeholder="Credits">
                                        <input type="text" name="reason" placeholder="Reason (e.g. Purchase, Promo)" required>
                                        <button type="submit" class="button">Set</button>
                                    </form>
                                </td>
                                <td>
                                    <div class="user-actions">
                                        <div class="user-actions-row">
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="send_password_reset">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="secondary">Reset link</button>
                                            </form>
                                        </div>
                                        <?php if ((int)$u['id'] === (int)$currentUser['id']): ?>
                                            <small>Current admin account protected.</small>
                                        <?php elseif (Auth::isAdmin($u)): ?>
                                            <small>Admin account protected.</small>
                                        <?php else: ?>
                                            <form method="post" class="delete-user-form" onsubmit="return confirm('Delete this user and related data? This cannot be undone.');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <input type="text" name="delete_confirmation" placeholder="DELETE" autocomplete="off" required>
                                                <button type="submit" class="danger-button">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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

<?php
declare(strict_types=1);

function run_assistant_persistence_tests(): void
{
    TestHarness::group('Assistant persistent identity, memory and audit');
    $pdo = Database::connection();
    Database::beginWriteTransaction($pdo);

    try {
        $suffix = bin2hex(random_bytes(6));
        $now = date('c');
        $insertUser = $pdo->prepare('INSERT INTO users(email,password_hash,name,credits,is_admin,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
        $insertUser->execute(["assistant-admin-{$suffix}@example.test", password_hash($suffix, PASSWORD_DEFAULT), 'Assistant Admin Test', 10, 1, $now, $now]);
        $adminId = (int)$pdo->lastInsertId();
        $insertUser->execute(["assistant-user-{$suffix}@example.test", password_hash($suffix, PASSWORD_DEFAULT), 'Assistant User Test', 10, 0, $now, $now]);
        $userId = (int)$pdo->lastInsertId();
        $admin = ['id' => $adminId, 'email' => "assistant-admin-{$suffix}@example.test", 'name' => 'Assistant Admin Test', 'credits' => 10, 'is_admin' => 1];
        $user = ['id' => $userId, 'email' => "assistant-user-{$suffix}@example.test", 'name' => 'Assistant User Test', 'credits' => 10, 'is_admin' => 0];

        $repository = new AssistantRepository($pdo);
        $identityId = $repository->mergeUsersByEmails([$admin['email'], $user['email']], 'Assistant Test Identity');
        TestHarness::assertSame($identityId, $repository->identityIdForUser($adminId), 'admin account belongs to the shared assistant identity');
        TestHarness::assertSame($identityId, $repository->identityIdForUser($userId), 'user account belongs to the shared assistant identity');

        $page = AssistantContext::page(['current_route' => 'website_board.php']);
        $conversation = $repository->conversation(null, $admin, $page);
        $repository->addMessage((int)$conversation['id'], 'user', 'Remember the approved website structure.', $adminId, $page);
        $repository->addMessage((int)$conversation['id'], 'assistant', 'The decision was recorded.', null, $page, 'test-model', 'response-test', ['input_tokens' => 12, 'output_tokens' => 6]);
        $memoryKey = $repository->recordMemory((int)$conversation['id'], $adminId, 'decision', 'Keep the approved website structure.', ['route' => 'website_board.php'], 90);
        $repository->recordAction((int)$conversation['id'], $adminId, 'remember_memory', 'memory', $memoryKey, 'completed');
        $repository->createTask((int)$conversation['id'], $adminId, [
            'title' => 'Review website board spacing',
            'component' => 'website board',
            'description' => 'Review the approved board without changing production data.',
            'expected_behavior' => 'Prepare a safe implementation task.',
            'acceptance_criteria' => ['No production write', 'Preserve current roles'],
        ], $page);
        $repository->recordUsage((int)$conversation['id'], $adminId, 'test-model', ['input_tokens' => 12, 'output_tokens' => 6], 'response-test');
        $repository->refreshSummary((int)$conversation['id'], $adminId);

        $recovered = $repository->latestConversation($user, $page);
        TestHarness::assertSame((string)$conversation['conversation_key'], (string)($recovered['conversation_key'] ?? ''), 'second login recovers the same conversation from the database');
        $state = $repository->state($recovered, $userId);
        TestHarness::assertSame(2, count((array)$state['messages']), 'conversation messages survive account switching');
        TestHarness::assertSame(1, count((array)$state['actions']), 'performed assistant actions are audited');
        TestHarness::assertSame(1, count((array)$state['technical_tasks']), 'pending technical tasks are recovered');

        $durable = $repository->durableContext((int)$conversation['id'], $userId);
        TestHarness::assertTrue(count((array)$durable['relevant_memories']) >= 1, 'relevant decisions are available as durable memory');
        TestHarness::assertSame(1, count((array)$durable['pending_tasks']), 'pending tasks are included in future context');
        TestHarness::assertNotEmpty((string)$durable['conversation_summary'], 'conversation summary is stored in the database');

        $usage = $pdo->prepare('SELECT input_tokens,output_tokens FROM assistant_usage_events WHERE conversation_id=? AND provider_response_id=?');
        $usage->execute([(int)$conversation['id'], 'response-test']);
        $usageRow = $usage->fetch();
        TestHarness::assertSame(12, (int)($usageRow['input_tokens'] ?? 0), 'input token consumption is persisted');
        TestHarness::assertSame(6, (int)($usageRow['output_tokens'] ?? 0), 'output token consumption is persisted');

        $roles = $pdo->prepare('SELECT id,is_admin FROM users WHERE id IN (?,?) ORDER BY id');
        $roles->execute([$adminId, $userId]);
        $roleRows = $roles->fetchAll();
        TestHarness::assertSame(1, (int)$roleRows[0]['is_admin'], 'admin role remains unchanged');
        TestHarness::assertSame(0, (int)$roleRows[1]['is_admin'], 'user role remains unchanged');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

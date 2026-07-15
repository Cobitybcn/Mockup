<?php
declare(strict_types=1);

final class AssistantRepository
{
    private const AREA = 'artworkmockups_faithful';

    public function __construct(private PDO $pdo) {}

    public function identityIdForUser(int $userId): int
    {
        $statement = $this->pdo->prepare("SELECT i.id FROM assistant_identities i JOIN assistant_identity_members m ON m.identity_id=i.id WHERE m.user_id=? AND i.status='active' LIMIT 1");
        $statement->execute([$userId]);
        $identityId = (int)$statement->fetchColumn();
        if ($identityId > 0) {
            return $identityId;
        }
        $user = $this->pdo->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
        $user->execute([$userId]);
        $displayName = $user->fetchColumn();
        if ($displayName === false) {
            throw new AssistantException('La identidad del asistente no está disponible.', 'assistant_identity_not_found');
        }
        $now = date('c');
        $this->pdo->prepare('INSERT INTO assistant_identities(identity_key,display_name,created_at,updated_at) VALUES(?,?,?,?)')
            ->execute([self::uuid(), (string)$displayName, $now, $now]);
        $identityId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO assistant_identity_members(identity_id,user_id,created_at,updated_at) VALUES(?,?,?,?)')
            ->execute([$identityId, $userId, $now, $now]);
        return $identityId;
    }

    public function mergeUsersByEmails(array $emails, string $displayName): int
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (mixed $email): string => strtolower(trim((string)$email)),
            $emails
        ))));
        if (count($emails) < 2) {
            throw new InvalidArgumentException('At least two email addresses are required.');
        }
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $statement = $this->pdo->prepare("SELECT id,email FROM users WHERE LOWER(email) IN ($placeholders)");
        $statement->execute($emails);
        $users = $statement->fetchAll();
        if (count($users) !== count($emails)) {
            throw new InvalidArgumentException('Every assistant identity member must be an existing user.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            Database::beginWriteTransaction($this->pdo);
        }
        try {
            $userIds = array_map(static fn (array $user): int => (int)$user['id'], $users);
            sort($userIds);
            $targetIdentityId = $this->identityIdForUser($userIds[0]);
            $identityIds = [];
            foreach ($userIds as $userId) {
                $identityIds[] = $this->identityIdForUser($userId);
            }
            $identityIds = array_values(array_unique($identityIds));
            $identityPlaceholders = implode(',', array_fill(0, count($identityIds), '?'));
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
            $this->pdo->prepare("UPDATE assistant_conversations SET identity_id=? WHERE identity_id IN ($identityPlaceholders)")
                ->execute([$targetIdentityId, ...$identityIds]);
            $this->pdo->prepare("UPDATE assistant_identity_members SET identity_id=?,updated_at=? WHERE user_id IN ($userPlaceholders)")
                ->execute([$targetIdentityId, date('c'), ...$userIds]);
            $this->pdo->prepare('UPDATE assistant_identities SET display_name=?,updated_at=? WHERE id=?')
                ->execute([trim(mb_substr($displayName, 0, 190)), date('c'), $targetIdentityId]);
            foreach ($identityIds as $identityId) {
                if ($identityId === $targetIdentityId) {
                    continue;
                }
                $delete = $this->pdo->prepare('DELETE FROM assistant_identities WHERE id=? AND NOT EXISTS (SELECT 1 FROM assistant_identity_members WHERE identity_id=?)');
                $delete->execute([$identityId, $identityId]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $targetIdentityId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function conversation(?string $key, array $user, array $page): array
    {
        $identityId = $this->identityIdForUser((int)$user['id']);
        if ($key !== null && $key !== '') {
            $statement = $this->pdo->prepare("SELECT * FROM assistant_conversations WHERE conversation_key=? AND identity_id=? AND area=? AND status='active' LIMIT 1");
            $statement->execute([$key, $identityId, self::AREA]);
            $conversation = $statement->fetch();
            if (!$conversation) {
                throw new AssistantException('La conversación ya no está disponible.', 'conversation_not_found');
            }
            $this->touchConversation((int)$conversation['id'], (int)$user['id'], $page);
            $conversation['page_type'] = (string)$page['page_type'];
            return $conversation;
        }
        $now = date('c');
        $key = self::uuid();
        $this->pdo->prepare('INSERT INTO assistant_conversations(conversation_key,identity_id,created_by_user_id,area,page_type,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$key, $identityId, (int)$user['id'], self::AREA, (string)$page['page_type'], $now, $now]);
        $conversation = [
            'id' => (int)$this->pdo->lastInsertId(),
            'conversation_key' => $key,
            'identity_id' => $identityId,
            'created_by_user_id' => (int)$user['id'],
            'area' => self::AREA,
            'page_type' => (string)$page['page_type'],
            'summary_text' => '',
        ];
        $this->syncEntities((int)$conversation['id'], (int)$user['id'], $page);
        return $conversation;
    }

    public function latestConversation(array $user, array $page): ?array
    {
        $identityId = $this->identityIdForUser((int)$user['id']);
        $statement = $this->pdo->prepare("SELECT * FROM assistant_conversations WHERE identity_id=? AND area=? AND status='active' ORDER BY updated_at DESC,id DESC LIMIT 1");
        $statement->execute([$identityId, self::AREA]);
        $conversation = $statement->fetch();
        if (!$conversation) {
            return null;
        }
        $this->touchConversation((int)$conversation['id'], (int)$user['id'], $page);
        $conversation['page_type'] = (string)$page['page_type'];
        return $conversation;
    }

    public function recentMessages(int $conversationId, int $limit): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM (SELECT id,role,content,created_at FROM assistant_messages WHERE conversation_id=? AND role IN ('user','assistant') ORDER BY id DESC LIMIT ?) recent ORDER BY id");
        $statement->bindValue(1, $conversationId, PDO::PARAM_INT);
        $statement->bindValue(2, max(2, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function addMessage(int $conversationId, string $role, string $content, ?int $actorUserId = null, array $context = [], string $model = '', string $providerId = '', array $usage = [], string $errorCode = ''): int
    {
        $now = date('c');
        $this->pdo->prepare('INSERT INTO assistant_messages(conversation_id,actor_user_id,role,content,context_json,model,provider_response_id,input_tokens,output_tokens,cached_input_tokens,error_code,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $conversationId, $actorUserId, $role, $content,
                $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
                $model, $providerId, (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0),
                (int)($usage['cached_input_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0), $errorCode, $now,
            ]);
        $messageId = (int)$this->pdo->lastInsertId();
        $title = $role === 'user' ? trim(mb_substr(preg_replace('/\s+/u', ' ', $content) ?? $content, 0, 120)) : '';
        $this->pdo->prepare("UPDATE assistant_conversations SET updated_at=?,last_message_at=?,title=CASE WHEN title='' THEN ? ELSE title END WHERE id=?")
            ->execute([$now, $now, $title, $conversationId]);
        return $messageId;
    }

    public function refreshSummary(int $conversationId, int $actorUserId): string
    {
        $messages = $this->recentMessages($conversationId, 12);
        $lines = [];
        foreach ($messages as $message) {
            $content = trim(preg_replace('/\s+/u', ' ', (string)$message['content']) ?? (string)$message['content']);
            if ($content !== '') {
                $lines[] = ($message['role'] === 'user' ? 'Usuario: ' : 'Asistente: ') . mb_substr($content, 0, 600);
            }
        }
        $summary = mb_substr(implode("\n", $lines), 0, 7000);
        $now = date('c');
        $this->pdo->prepare('UPDATE assistant_conversations SET summary_text=?,updated_at=? WHERE id=?')->execute([$summary, $now, $conversationId]);
        $existing = $this->pdo->prepare("SELECT id FROM assistant_memories WHERE conversation_id=? AND memory_type='summary' AND status='active' ORDER BY id DESC LIMIT 1");
        $existing->execute([$conversationId]);
        $memoryId = (int)$existing->fetchColumn();
        if ($memoryId > 0) {
            $this->pdo->prepare('UPDATE assistant_memories SET content=?,actor_user_id=?,updated_at=? WHERE id=?')->execute([$summary, $actorUserId, $now, $memoryId]);
        } else {
            $this->recordMemory($conversationId, $actorUserId, 'summary', $summary, ['source' => 'rolling_extract'], 60);
        }
        return $summary;
    }

    public function recordMemory(int $conversationId, int $actorUserId, string $type, string $content, array $context = [], int $importance = 50): string
    {
        $key = self::uuid();
        $now = date('c');
        $this->pdo->prepare('INSERT INTO assistant_memories(memory_key,conversation_id,actor_user_id,memory_type,content,context_json,importance,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$key, $conversationId, $actorUserId, $type, trim(mb_substr($content, 0, 20000)), $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null, max(0, min(100, $importance)), $now, $now]);
        return $key;
    }

    public function durableContext(int $conversationId, int $userId): array
    {
        $identityId = $this->identityIdForUser($userId);
        $conversation = $this->pdo->prepare('SELECT summary_text FROM assistant_conversations WHERE id=? AND identity_id=? LIMIT 1');
        $conversation->execute([$conversationId, $identityId]);
        $summary = (string)$conversation->fetchColumn();
        $memories = $this->pdo->prepare("SELECT m.memory_type,m.content,m.context_json,m.updated_at FROM assistant_memories m JOIN assistant_conversations c ON c.id=m.conversation_id WHERE c.identity_id=? AND m.memory_type IN ('decision','fact','preference','note') AND m.status='active' ORDER BY m.importance DESC,m.updated_at DESC LIMIT 16");
        $memories->execute([$identityId]);
        $tasks = $this->pdo->prepare("SELECT t.task_key,t.title,t.current_route,t.component,t.description,t.expected_behavior,t.status,t.updated_at FROM assistant_technical_tasks t JOIN assistant_conversations c ON c.id=t.conversation_id WHERE c.identity_id=? AND t.status IN ('pending','in_progress') ORDER BY t.updated_at DESC LIMIT 12");
        $tasks->execute([$identityId]);
        $entities = $this->pdo->prepare('SELECT entity_type,entity_id,updated_at FROM assistant_conversation_entities WHERE conversation_id=? ORDER BY updated_at DESC');
        $entities->execute([$conversationId]);
        return [
            'conversation_summary' => $summary,
            'relevant_memories' => array_map(static fn (array $row): array => [
                'type' => (string)$row['memory_type'],
                'content' => (string)$row['content'],
                'context' => json_decode((string)($row['context_json'] ?? ''), true) ?: [],
                'updated_at' => (string)$row['updated_at'],
            ], $memories->fetchAll()),
            'pending_tasks' => $tasks->fetchAll(),
            'associated_entities' => $entities->fetchAll(),
        ];
    }

    public function recordUsage(int $conversationId, int $actorUserId, string $model, array $usage, string $providerId = '', string $status = 'success', string $errorCode = '', array $context = []): void
    {
        $this->pdo->prepare('INSERT INTO assistant_usage_events(conversation_id,actor_user_id,provider,provider_response_id,model,input_tokens,output_tokens,cached_input_tokens,status,error_code,context_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$conversationId, $actorUserId, 'openai', $providerId, $model, (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0), (int)($usage['cached_input_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0), $status, $errorCode, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null, date('c')]);
    }

    public function recordAction(int $conversationId, int $actorUserId, string $actionType, string $targetType, string $targetKey, string $status, array $request = [], array $result = []): array
    {
        $action = [
            'action_key' => self::uuid(),
            'action_type' => trim(mb_substr($actionType, 0, 80)),
            'target_type' => trim(mb_substr($targetType, 0, 80)),
            'target_key' => trim(mb_substr($targetKey, 0, 190)),
            'status' => trim(mb_substr($status, 0, 24)),
            'created_at' => date('c'),
        ];
        $this->pdo->prepare('INSERT INTO assistant_actions(action_key,conversation_id,actor_user_id,action_type,target_type,target_key,status,request_json,result_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $action['action_key'], $conversationId, $actorUserId, $action['action_type'], $action['target_type'], $action['target_key'], $action['status'],
                $request ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
                $result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
                $action['created_at'],
            ]);
        return $action;
    }

    public function assertRateLimit(int $userId, AssistantConfig $config): void
    {
        $identityId = $this->identityIdForUser($userId);
        $minute = date('c', time() - 60);
        $day = date('Y-m-d') . 'T00:00:00+00:00';
        $statement = $this->pdo->prepare("SELECT SUM(CASE WHEN m.created_at>=? THEN 1 ELSE 0 END) per_minute,SUM(CASE WHEN m.created_at>=? THEN 1 ELSE 0 END) today FROM assistant_messages m JOIN assistant_conversations c ON c.id=m.conversation_id WHERE c.identity_id=? AND m.role='user'");
        $statement->execute([$minute, $day, $identityId]);
        $usage = $statement->fetch() ?: [];
        if ((int)($usage['per_minute'] ?? 0) >= $config->perMinuteLimit()) {
            throw new AssistantException('Has enviado varios mensajes seguidos. Espera un momento.', 'rate_limit_minute');
        }
        if ((int)($usage['today'] ?? 0) >= $config->dailyLimit()) {
            throw new AssistantException('Se alcanzó el límite diario configurado para el asistente.', 'rate_limit_daily');
        }
    }

    public function state(array $conversation, int $userId): array
    {
        $identityId = $this->identityIdForUser($userId);
        if ((int)$conversation['identity_id'] !== $identityId) {
            throw new AssistantException('La conversación ya no está disponible.', 'conversation_not_found');
        }
        $tasks = $this->pdo->prepare("SELECT task_key,title,current_route,component,description,expected_behavior,acceptance_json,status FROM assistant_technical_tasks WHERE conversation_id=? AND status IN ('pending','in_progress') ORDER BY id");
        $tasks->execute([(int)$conversation['id']]);
        $actions = $this->pdo->prepare('SELECT action_key,action_type,target_type,target_key,status,created_at FROM assistant_actions WHERE conversation_id=? ORDER BY id DESC LIMIT 30');
        $actions->execute([(int)$conversation['id']]);
        return [
            'conversation_key' => (string)$conversation['conversation_key'],
            'messages' => $this->recentMessages((int)$conversation['id'], 30),
            'summary' => (string)($conversation['summary_text'] ?? ''),
            'actions' => array_reverse($actions->fetchAll()),
            'technical_tasks' => array_map(static fn (array $task): array => [
                'task_key' => (string)$task['task_key'],
                'title' => (string)$task['title'],
                'route' => (string)$task['current_route'],
                'component' => (string)$task['component'],
                'description' => (string)$task['description'],
                'expected_behavior' => (string)$task['expected_behavior'],
                'acceptance_criteria' => json_decode((string)$task['acceptance_json'], true) ?: [],
                'status' => (string)$task['status'],
            ], $tasks->fetchAll()),
        ];
    }

    public function archive(string $key, int $userId): void
    {
        $identityId = $this->identityIdForUser($userId);
        $this->pdo->prepare("UPDATE assistant_conversations SET status='archived',updated_at=? WHERE conversation_key=? AND identity_id=? AND area=? AND status='active'")
            ->execute([date('c'), $key, $identityId, self::AREA]);
    }

    public function createTask(int $conversationId, int $actorUserId, array $task, array $page): array
    {
        $key = self::uuid();
        $now = date('c');
        $criteria = array_slice(array_values(array_filter(array_map(static fn (mixed $item): string => trim(mb_substr((string)$item, 0, 500)), (array)($task['acceptance_criteria'] ?? [])))), 0, 20);
        $this->pdo->prepare('INSERT INTO assistant_technical_tasks(task_key,conversation_id,actor_user_id,title,current_route,component,description,expected_behavior,acceptance_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$key, $conversationId, $actorUserId, $task['title'], (string)$page['current_route'], $task['component'], $task['description'], $task['expected_behavior'], json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $now, $now]);
        $this->recordMemory($conversationId, $actorUserId, 'task', 'Tarea pendiente para Codex: ' . $task['title'], ['task_key' => $key, 'route' => (string)$page['current_route']], 70);
        return ['task_key' => $key] + $task + ['route' => (string)$page['current_route'], 'acceptance_criteria' => $criteria, 'status' => 'pending'];
    }

    private function touchConversation(int $conversationId, int $actorUserId, array $page): void
    {
        $this->pdo->prepare('UPDATE assistant_conversations SET page_type=?,updated_at=? WHERE id=?')->execute([(string)$page['page_type'], date('c'), $conversationId]);
        $this->syncEntities($conversationId, $actorUserId, $page);
    }

    private function syncEntities(int $conversationId, int $actorUserId, array $page): void
    {
        $entities = [];
        foreach (['artwork_id' => 'artwork', 'series_id' => 'series', 'mockup_id' => 'mockup', 'generation_id' => 'generation', 'publication_id' => 'publication'] as $key => $type) {
            $id = (int)($page[$key] ?? 0);
            if ($id > 0) {
                $entities[] = [$type, $id];
            }
        }
        foreach ((array)($page['selected_mockup_ids'] ?? []) as $id) {
            if ((int)$id > 0) {
                $entities[] = ['mockup', (int)$id];
            }
        }
        if (!$entities) {
            return;
        }
        $now = date('c');
        $json = json_encode(['page_type' => (string)$page['page_type'], 'current_route' => (string)$page['current_route']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $mysql
            ? 'INSERT INTO assistant_conversation_entities(conversation_id,actor_user_id,entity_type,entity_id,context_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE actor_user_id=VALUES(actor_user_id),context_json=VALUES(context_json),updated_at=VALUES(updated_at)'
            : 'INSERT INTO assistant_conversation_entities(conversation_id,actor_user_id,entity_type,entity_id,context_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(conversation_id,entity_type,entity_id) DO UPDATE SET actor_user_id=excluded.actor_user_id,context_json=excluded.context_json,updated_at=excluded.updated_at';
        $statement = $this->pdo->prepare($sql);
        foreach ($entities as [$type, $id]) {
            $statement->execute([$conversationId, $actorUserId, $type, $id, $json, $now, $now]);
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

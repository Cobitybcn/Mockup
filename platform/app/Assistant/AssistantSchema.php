<?php
declare(strict_types=1);

final class AssistantSchema
{
    public static function migrate(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            self::migrateMysql($pdo);
            return;
        }
        self::migrateSqlite($pdo);
    }

    private static function migrateMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_identities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            identity_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            display_name VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_identity_members (
            identity_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            PRIMARY KEY(identity_id,user_id),
            UNIQUE KEY uq_assistant_identity_member_user(user_id),
            CONSTRAINT fk_assistant_member_identity FOREIGN KEY(identity_id) REFERENCES assistant_identities(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            identity_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NOT NULL,
            area VARCHAR(60) NOT NULL DEFAULT 'artworkmockups_faithful',
            page_type VARCHAR(100) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            summary_text MEDIUMTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            last_message_at VARCHAR(40) NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_conversation_identity(identity_id,area,status,updated_at),
            CONSTRAINT fk_assistant_conversation_identity FOREIGN KEY(identity_id) REFERENCES assistant_identities(id) ON DELETE RESTRICT,
            CONSTRAINT fk_assistant_conversation_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            role VARCHAR(20) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            context_json JSON NULL,
            model VARCHAR(120) NOT NULL DEFAULT '',
            provider_response_id VARCHAR(190) NOT NULL DEFAULT '',
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cached_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            error_code VARCHAR(120) NOT NULL DEFAULT '',
            created_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_messages_conversation(conversation_id,id),
            KEY idx_assistant_messages_actor(actor_user_id,created_at),
            CONSTRAINT fk_assistant_message_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_message_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_conversation_entities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            context_json JSON NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_assistant_conversation_entity(conversation_id,entity_type,entity_id),
            CONSTRAINT fk_assistant_entity_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_entity_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_memories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            memory_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            memory_type VARCHAR(30) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            context_json JSON NULL,
            importance TINYINT UNSIGNED NOT NULL DEFAULT 50,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_memory_conversation(conversation_id,status,updated_at),
            CONSTRAINT fk_assistant_memory_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_memory_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_usage_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL DEFAULT 'openai',
            provider_response_id VARCHAR(190) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cached_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL,
            error_code VARCHAR(120) NOT NULL DEFAULT '',
            context_json JSON NULL,
            created_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_usage_identity(actor_user_id,created_at),
            KEY idx_assistant_usage_conversation(conversation_id,created_at),
            CONSTRAINT fk_assistant_usage_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_usage_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            action_type VARCHAR(80) NOT NULL,
            target_type VARCHAR(80) NOT NULL DEFAULT '',
            target_key VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL,
            request_json JSON NULL,
            result_json JSON NULL,
            created_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_actions_conversation(conversation_id,created_at),
            KEY idx_assistant_actions_actor(actor_user_id,created_at),
            CONSTRAINT fk_assistant_action_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_action_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_technical_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            conversation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            current_route VARCHAR(255) NOT NULL DEFAULT '',
            component VARCHAR(190) NOT NULL DEFAULT '',
            description MEDIUMTEXT NOT NULL,
            expected_behavior MEDIUMTEXT NOT NULL,
            acceptance_json JSON NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_assistant_tasks_conversation(conversation_id,status,updated_at),
            CONSTRAINT fk_assistant_task_conversation FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_assistant_task_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function migrateSqlite(PDO $pdo): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS assistant_identities (id INTEGER PRIMARY KEY AUTOINCREMENT,identity_key TEXT NOT NULL UNIQUE,display_name TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'active',created_at TEXT NOT NULL,updated_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS assistant_identity_members (identity_id INTEGER NOT NULL,user_id INTEGER NOT NULL UNIQUE,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(identity_id,user_id),FOREIGN KEY(identity_id) REFERENCES assistant_identities(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS assistant_conversations (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_key TEXT NOT NULL UNIQUE,identity_id INTEGER NOT NULL,created_by_user_id INTEGER NOT NULL,area TEXT NOT NULL DEFAULT 'artworkmockups_faithful',page_type TEXT NOT NULL DEFAULT '',title TEXT NOT NULL DEFAULT '',summary_text TEXT,status TEXT NOT NULL DEFAULT 'active',last_message_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(identity_id) REFERENCES assistant_identities(id),FOREIGN KEY(created_by_user_id) REFERENCES users(id))",
            "CREATE INDEX IF NOT EXISTS idx_assistant_conversation_identity ON assistant_conversations(identity_id,area,status,updated_at)",
            "CREATE TABLE IF NOT EXISTS assistant_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,actor_user_id INTEGER,role TEXT NOT NULL,content TEXT NOT NULL,context_json TEXT,model TEXT NOT NULL DEFAULT '',provider_response_id TEXT NOT NULL DEFAULT '',input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,cached_input_tokens INTEGER NOT NULL DEFAULT 0,error_code TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_assistant_messages_conversation ON assistant_messages(conversation_id,id)",
            "CREATE TABLE IF NOT EXISTS assistant_conversation_entities (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,context_json TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(conversation_id,entity_type,entity_id),FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS assistant_memories (id INTEGER PRIMARY KEY AUTOINCREMENT,memory_key TEXT NOT NULL UNIQUE,conversation_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,memory_type TEXT NOT NULL,content TEXT NOT NULL,context_json TEXT,importance INTEGER NOT NULL DEFAULT 50,status TEXT NOT NULL DEFAULT 'active',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS assistant_usage_events (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,provider TEXT NOT NULL DEFAULT 'openai',provider_response_id TEXT NOT NULL DEFAULT '',model TEXT NOT NULL DEFAULT '',input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,cached_input_tokens INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL,error_code TEXT NOT NULL DEFAULT '',context_json TEXT,created_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS assistant_actions (id INTEGER PRIMARY KEY AUTOINCREMENT,action_key TEXT NOT NULL UNIQUE,conversation_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,action_type TEXT NOT NULL,target_type TEXT NOT NULL DEFAULT '',target_key TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,request_json TEXT,result_json TEXT,created_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE INDEX IF NOT EXISTS idx_assistant_actions_conversation ON assistant_actions(conversation_id,created_at)",
            "CREATE TABLE IF NOT EXISTS assistant_technical_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,task_key TEXT NOT NULL UNIQUE,conversation_id INTEGER NOT NULL,actor_user_id INTEGER NOT NULL,title TEXT NOT NULL,current_route TEXT NOT NULL DEFAULT '',component TEXT NOT NULL DEFAULT '',description TEXT NOT NULL,expected_behavior TEXT NOT NULL,acceptance_json TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES assistant_conversations(id) ON DELETE CASCADE,FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)",
        ];
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }
}

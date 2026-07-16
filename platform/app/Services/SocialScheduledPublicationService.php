<?php
declare(strict_types=1);

final class SocialScheduledPublicationService
{
    private Closure $enqueueTask;
    private Closure $deleteTask;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SocialPublishJobService $jobs,
        ?Closure $enqueueTask = null,
        ?Closure $deleteTask = null
    ) {
        $this->enqueueTask = $enqueueTask ?? static fn (int $jobId, DateTimeImmutable $when): string =>
            CloudTasksService::enqueueSocialPublication($jobId, $when);
        $this->deleteTask = $deleteTask ?? static function (string $taskName): void {
            CloudTasksService::deleteTask($taskName);
        };
    }

    /** @return array<int,array> */
    public function pending(int $userId): array
    {
        if ($userId <= 0) throw new InvalidArgumentException('Invalid social publication owner.');
        return array_map(fn (array $job): array => $this->summary($job), $this->jobs->manageableForUser($userId));
    }

    /** @return array<int,array> */
    public function recent(int $userId): array
    {
        if ($userId <= 0) throw new InvalidArgumentException('Invalid social publication owner.');
        return array_map(fn (array $job): array => $this->summary($job), $this->jobs->recentForUser($userId));
    }

    public function reschedule(int $jobId, int $userId, string $date, string $time, string $timezone): array
    {
        $when = $this->scheduledAt($date, $time, $timezone);
        return $this->replaceTask($jobId, $userId, $when, false);
    }

    public function publishNow(int $jobId, int $userId): array
    {
        return $this->replaceTask($jobId, $userId, new DateTimeImmutable('now', new DateTimeZone('UTC')), true);
    }

    public function retry(int $jobId, int $userId): array
    {
        $when = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->jobs->beginRetry($jobId, $userId);
        try {
            $taskName = ($this->enqueueTask)($jobId, $when);
            $this->jobs->finishRetry($jobId, $userId, $when, $taskName);
        } catch (Throwable $e) {
            $this->jobs->failRetry($jobId, $userId, $e->getMessage());
            throw new RuntimeException('The failed publication could not be queued again. Try again.', 0, $e);
        }
        return $this->summary($this->jobs->job($jobId, $userId));
    }

    public function cancel(int $jobId, int $userId): array
    {
        $original = $this->jobs->job($jobId, $userId);
        $previousStatus = (string)$original['status'];
        $job = $this->jobs->beginManagement($jobId, $userId, 'cancelling');
        try {
            ($this->deleteTask)((string)$job['task_name']);
        } catch (Throwable $e) {
            $this->jobs->restoreManagement($jobId, $userId, $previousStatus, $e->getMessage());
            throw new RuntimeException('The scheduled task could not be cancelled. Try again.', 0, $e);
        }
        $this->jobs->finishCancellation($jobId, $userId);
        return $this->summary($this->jobs->job($jobId, $userId));
    }

    private function replaceTask(int $jobId, int $userId, DateTimeImmutable $when, bool $now): array
    {
        $original = $this->jobs->job($jobId, $userId);
        $previousStatus = (string)$original['status'];
        $job = $this->jobs->beginManagement($jobId, $userId, 'rescheduling');
        try {
            ($this->deleteTask)((string)$job['task_name']);
        } catch (Throwable $e) {
            $this->jobs->restoreManagement($jobId, $userId, $previousStatus, $e->getMessage());
            throw new RuntimeException('The previous scheduled task could not be replaced. Try again.', 0, $e);
        }

        try {
            $taskName = ($this->enqueueTask)($jobId, $when);
            $this->jobs->finishReschedule($jobId, $userId, $when, $taskName);
        } catch (Throwable $e) {
            $this->jobs->failReschedule($jobId, $userId, $when, $e->getMessage());
            throw new RuntimeException($now
                ? 'The publication was prepared for immediate delivery but could not be queued. Try again.'
                : 'The publication date was saved but the task could not be queued. Try again.', 0, $e);
        }

        return $this->summary($this->jobs->job($jobId, $userId));
    }

    private function scheduledAt(string $date, string $time, string $timezone): DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        try {
            $zone = new DateTimeZone(trim($timezone) !== '' ? trim($timezone) : 'UTC');
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid publication timezone.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('Choose a valid publication date and time.');
        }
        $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$when || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException('Choose a valid publication date and time.');
        }
        $now = new DateTimeImmutable('now', $zone);
        if ($when < $now->modify('-1 minute')) throw new InvalidArgumentException('The publication date cannot be in the past.');
        if ($when > $now->modify('+30 days')) throw new InvalidArgumentException('Publications can be scheduled up to 30 days ahead.');
        return $when < $now ? $now : $when;
    }

    private function summary(array $job): array
    {
        $payload = json_decode((string)($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $draftIds = array_values(array_unique(array_filter(array_map('intval', (array)($payload['draft_ids'] ?? [])))));
        $channel = (string)$job['channel'];
        $status = (string)$job['status'];
        $error = (string)($job['error'] ?? '');
        $trialPinterestFailure = $channel === 'pinterest'
            && $status === 'failed'
            && (str_contains($error, 'Trial access') || str_contains($error, 'Pinterest code 29'));
        $label = $this->draftLabel($channel, $draftIds, (int)$job['user_id']);
        $destination = $this->pinterestDestination($channel, $draftIds, (int)$job['user_id']);
        return [
            'id' => (int)$job['id'],
            'channel' => $channel,
            'purpose' => (string)$job['purpose'],
            'status' => $status,
            'scheduled_at' => (string)$job['scheduled_at'],
            'client_key' => (string)($payload['client_key'] ?? ''),
            'item_count' => count($draftIds),
            'label' => $label !== '' ? $label : ucfirst($channel) . ' · publicación #' . (int)$job['id'],
            'error' => $error,
            'external_url' => (string)($job['external_url'] ?? ''),
            'updated_at' => (string)($job['updated_at'] ?? ''),
            'can_reschedule' => in_array((string)$job['status'], ['queued', 'enqueue_failed'], true),
            'can_retry' => in_array($status, ['failed', 'enqueue_failed'], true) && !$trialPinterestFailure,
            'retry_hint' => $trialPinterestFailure
                ? 'Este Pin usó un tablero real, pero Pinterest Trial solo permite el tablero Sandbox. Crea o ajusta el Pin con el tablero de prueba antes de volver a enviarlo.'
                : '',
            'board_id' => $destination['board_id'],
            'board_name' => $destination['board_name'],
        ];
    }

    private function draftLabel(string $channel, array $draftIds, int $userId): string
    {
        if (!$draftIds) return '';
        try {
            if ($channel === 'pinterest') {
                $stmt = $this->pdo->prepare('SELECT title FROM pinterest_pin_drafts WHERE id=? AND user_id=? LIMIT 1');
            } else {
                $stmt = $this->pdo->prepare('SELECT title,description FROM social_channel_drafts WHERE id=? AND user_id=? LIMIT 1');
            }
            $stmt->execute([$draftIds[0], $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return '';
            $label = trim((string)($row['title'] ?? ''));
            if ($label === '') $label = trim((string)($row['description'] ?? ''));
            return mb_substr($label, 0, 120);
        } catch (PDOException) {
            return '';
        }
    }

    /** @return array{board_id:string,board_name:string} */
    private function pinterestDestination(string $channel, array $draftIds, int $userId): array
    {
        if ($channel !== 'pinterest' || !$draftIds) return ['board_id' => '', 'board_name' => ''];
        try {
            $stmt = $this->pdo->prepare('SELECT board_id,board_name FROM pinterest_pin_drafts WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$draftIds[0], $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row)
                ? ['board_id' => (string)($row['board_id'] ?? ''), 'board_name' => (string)($row['board_name'] ?? '')]
                : ['board_id' => '', 'board_name' => ''];
        } catch (PDOException) {
            return ['board_id' => '', 'board_name' => ''];
        }
    }
}

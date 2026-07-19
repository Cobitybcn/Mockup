<?php
declare(strict_types=1);

final class VideoHttp
{
    public static function csrfToken(): string
    {
        Auth::start();
        $_SESSION['video_studio_csrf'] ??= bin2hex(random_bytes(32));
        return (string)$_SESSION['video_studio_csrf'];
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::respond(['ok' => false, 'error' => 'Use POST for this request.'], 405, ['Allow' => 'POST']);
        }
    }

    public static function input(): array
    {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid JSON request.');
        }
        return $input;
    }

    public static function verifyCsrf(array $input): void
    {
        $expected = self::csrfToken();
        if ($expected === '' || !hash_equals($expected, (string)($input['csrf'] ?? ''))) {
            self::respond(['ok' => false, 'error' => 'The Video Lab session expired. Reload the page and try again.'], 403);
        }
    }

    public static function respond(array $payload, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function handle(callable $callback): never
    {
        try {
            $result = $callback();
            self::respond(['ok' => true] + (is_array($result) ? $result : []));
        } catch (InvalidArgumentException $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (OutOfBoundsException $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (DomainException $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            Logger::log('Video Lab request failed: ' . $e->getMessage(), 'error');
            self::respond(['ok' => false, 'error' => 'Video Lab could not complete the request. Check the server log for details.'], 500);
        }
    }
}

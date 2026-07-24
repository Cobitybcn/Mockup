<?php
declare(strict_types=1);

final class RequestSecurity
{
    public static function enforceMutationCsrf(): void
    {
        if (PHP_SAPI === 'cli') return;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;

        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if ($script === '' || preg_match(self::protectedEndpointPattern(), $script) !== 1) return;

        $token = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['app_csrf'] ?? ''));
        if (Auth::validateCsrf($token, 'mutation')) return;

        http_response_code(403);
        header('Cache-Control: no-store');
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid form session. Reload the page and try again.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid form session. Reload the page and try again.';
        }
        exit;
    }

    private static function protectedEndpointPattern(): string
    {
        return '/^(?:account|admin_[a-z0-9_]+|analyze|artist_profile|artwork(?:_details)?|assistant_api|camera_studio|complete_root_views|connections|core_review|curated_mockups|dashboard|delete_[a-z0-9_]+|fichas(?:_reconcile)?|generate_(?:mockup(?:_[a-z0-9_]+)?|one_mockup_from_composed_admin_prompt)|merge_artwork_groups|meta_[a-z0-9_]+|mockup_[a-z0-9_]+|pinterest_[a-z0-9_]+|prepare_publication|reanalyze|regenerate_mockup_proposals|reorder_series(?:_artworks)?|report|root_album|save_mockup_combination_evaluation|select_root|series|social_media_(?:catalog|schedule)|start_generate|toggle_[a-z0-9_]+|upload_(?:existing_root|external_mockup)|video_(?:editor_start|final_artwork|final_upload|reference_upload)|website_(?:board_action|catalog|studio_notes)|world_mother_studio)\.php$/i';
    }
}

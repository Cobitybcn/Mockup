<?php
declare(strict_types=1);

final class AdminSceneEditor
{
    public static function handlePost(array $user): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['admin_scene_save', 'save_scene_quick_from_review'], true)) {
            return;
        }

        if (!Auth::isAdmin($user)) {
            http_response_code(403);
            die('Access denied.');
        }

        (new CameraSlotStudio())->saveSceneQuick($_POST);

        $returnTo = self::safeReturnTo((string)($_POST['return_to'] ?? ''));
        header('Location: ' . ($returnTo !== '' ? $returnTo : 'camera_studio.php'));
        exit;
    }

    public static function render(array $user, string $slotId, string $returnTo, array $options = []): string
    {
        if (!Auth::isAdmin($user)) {
            return '';
        }

        $slotId = trim($slotId);
        if ($slotId === '') {
            return '';
        }

        $studio = new CameraSlotStudio();
        try {
            $slot = $studio->slotForEdit($slotId);
        } catch (Throwable $e) {
            return '';
        }

        $title = trim((string)($options['title'] ?? 'Admin Scene'));
        $title = $title !== '' ? $title : 'Admin Scene';
        $open = !empty($options['open']) ? ' open' : '';
        $prompt = $studio->scenePromptForEdit($slot);
        $returnTo = self::safeReturnTo($returnTo);
        $slotName = (string)($slot['slot_name'] ?? $slotId);
        $enabled = !empty($slot['enabled']);

        return '<details class="admin-scene-editor"' . $open . '>'
            . '<summary>' . self::h($title) . '</summary>'
            . '<form method="post" class="admin-scene-editor-form">'
            . '<input type="hidden" name="action" value="admin_scene_save">'
            . '<input type="hidden" name="return_to" value="' . self::h($returnTo) . '">'
            . '<input type="hidden" name="slot_id" value="' . self::h($slotId) . '">'
            . '<div class="admin-scene-editor-fields">'
            . '<label>Nombre visible<input type="text" name="slot_name" value="' . self::h($slotName) . '"></label>'
            . '<label class="admin-scene-editor-toggle"><input type="checkbox" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '>Activa</label>'
            . '</div>'
            . '<details class="admin-scene-editor-prompt">'
            . '<summary>Prompt completo</summary>'
            . '<textarea name="full_prompt_template" rows="10">' . self::h($prompt) . '</textarea>'
            . '</details>'
            . '<div class="admin-scene-editor-actions">'
            . '<button class="button-link" type="submit">Guardar</button>'
            . '<a class="button-link secondary" href="camera_studio.php?slot_id=' . rawurlencode($slotId) . '">Tablero</a>'
            . '</div>'
            . '</form>'
            . '</details>';
    }

    public static function styles(): string
    {
        return '<style>
            .admin-scene-editor {
                border: 1px solid var(--line);
                border-radius: 4px;
                background: rgba(255,255,255,.52);
                padding: 6px 8px;
                margin-top: 8px;
            }
            .admin-scene-editor summary {
                cursor: pointer;
                list-style: none;
                color: var(--muted);
                font-size: 9px;
                font-weight: 800;
                letter-spacing: .07em;
                text-transform: uppercase;
            }
            .admin-scene-editor summary::-webkit-details-marker { display: none; }
            .admin-scene-editor > summary::after,
            .admin-scene-editor-prompt > summary::after {
                content: "+";
                float: right;
                color: var(--accent);
            }
            .admin-scene-editor[open] > summary,
            .admin-scene-editor-prompt[open] > summary { margin-bottom: 8px; }
            .admin-scene-editor[open] > summary::after,
            .admin-scene-editor-prompt[open] > summary::after { content: "-"; }
            .admin-scene-editor-form { display: grid; gap: 8px; }
            .admin-scene-editor-fields {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                align-items: end;
            }
            .admin-scene-editor-form label {
                display: grid;
                gap: 4px;
                color: var(--muted);
                font-size: 9px;
                font-weight: 800;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .admin-scene-editor-form input[type="text"],
            .admin-scene-editor-form textarea {
                width: 100%;
                border: 1px solid var(--line);
                border-radius: 4px;
                background: var(--surface);
                color: var(--ink);
                padding: 7px 8px;
                font-size: 11px;
            }
            .admin-scene-editor-form textarea {
                min-height: 180px;
                font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
                line-height: 1.42;
                resize: vertical;
            }
            .admin-scene-editor-toggle {
                min-width: 96px;
                height: 32px;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                gap: 6px;
                border: 1px solid var(--line);
                border-radius: 4px;
                background: var(--surface);
                color: var(--ink) !important;
                font-size: 10px !important;
            }
            .admin-scene-editor-actions {
                display: flex;
                gap: 6px;
                align-items: center;
                flex-wrap: wrap;
            }
            .admin-scene-editor-actions .button-link {
                width: auto !important;
                min-width: 0 !important;
                height: 30px !important;
                min-height: 0 !important;
                padding: 0 10px !important;
                margin: 0 !important;
                font-size: 9px !important;
                box-shadow: none !important;
            }
        </style>';
    }

    private static function safeReturnTo(string $returnTo): string
    {
        $returnTo = trim($returnTo);
        if ($returnTo === '' || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo)) {
            return '';
        }
        return ltrim($returnTo, '/');
    }

    private static function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

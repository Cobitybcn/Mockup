<?php
declare(strict_types=1);

final class AssistantView
{
    public static function render(array $user, array $context): void
    {
        $config = new AssistantConfig();
        if (!$config->enabledFor($user)) {
            return;
        }

        Auth::start();
        $_SESSION['assistant_csrf'] ??= bin2hex(random_bytes(32));
        $context = AssistantContext::page($context);
        $encoded = base64_encode(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $h = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $contextLabel = AssistantContext::label((string)$context['page_type']);
        $userName = trim((string)($user['name'] ?? 'Usuario')) ?: 'Usuario';
        $userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
        $provider = $config->provider();
        ?>
        <link rel="stylesheet" href="assets/assistant.css?v=20260715r">
        <div class="faithful-assistant" data-assistant-root data-assistant-mode="connected" data-endpoint="assistant_api.php" data-csrf="<?=$h($_SESSION['assistant_csrf'])?>" data-context="<?=$h($encoded)?>" data-user-name="<?=$h($userName)?>">
            <button type="button" class="faithful-assistant-launcher" data-assistant-open aria-label="Abrir Artwork Assistant" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.8 13.7 9l5.2 1.7-5.2 1.7-1.7 5.2-1.7-5.2-5.2-1.7L10.3 9 12 3.8Z"/><path d="m18.2 16 .8 2.3 2.3.8-2.3.8-.8 2.3-.8-2.3-2.3-.8 2.3-.8.8-2.3Z"/></svg>
                <span>Assistant</span>
            </button>

            <div class="faithful-assistant-backdrop" aria-hidden="true"></div>
            <div class="faithful-assistant-target-banner" data-assistant-target-banner aria-hidden="true">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/></svg>
                <span>Haz clic en el botón, formulario o elemento que quieres mostrarle al asistente.</span>
                <button type="button" data-assistant-target-cancel>Cancel</button>
            </div>

            <section class="faithful-assistant-cropper" data-assistant-cropper role="dialog" aria-modal="true" aria-labelledby="faithful-assistant-crop-title" aria-hidden="true" hidden>
                <div class="faithful-assistant-crop-panel">
                    <header class="faithful-assistant-crop-header">
                        <div>
                            <h2 id="faithful-assistant-crop-title">Selecciona el área que quieres incluir</h2>
                            <p>Arrastra sobre la imagen. Solo la zona confirmada se enviará al asistente.</p>
                        </div>
                        <button type="button" data-assistant-crop-cancel aria-label="Cancelar captura" title="Cancelar">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                        </button>
                    </header>
                    <div class="faithful-assistant-crop-viewport" data-assistant-crop-viewport>
                        <div class="faithful-assistant-crop-stage" data-assistant-crop-stage>
                            <img data-assistant-crop-image alt="Vista previa de la pantalla capturada" draggable="false">
                            <div class="faithful-assistant-crop-selection" data-assistant-crop-selection hidden></div>
                        </div>
                    </div>
                    <footer class="faithful-assistant-crop-actions">
                        <button type="button" class="is-secondary" data-assistant-crop-full>Usar pantalla completa</button>
                        <button type="button" class="is-primary" data-assistant-crop-confirm disabled>Usar selección</button>
                    </footer>
                </div>
            </section>

            <section class="faithful-assistant-workspace" role="dialog" aria-modal="false" aria-label="Artwork Assistant" aria-hidden="true">
                <aside class="faithful-assistant-sidebar" data-assistant-sidebar>
                    <header class="faithful-assistant-sidebar-header">
                        <div class="faithful-assistant-brand-mark" aria-hidden="true">A</div>
                        <div class="faithful-assistant-brand-copy">
                            <strong>Artwork Assistant</strong>
                            <span>Conectado a Artwork</span>
                        </div>
                        <button type="button" class="faithful-assistant-icon-button faithful-assistant-mobile-only" data-assistant-sidebar-close aria-label="Close history">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                        </button>
                    </header>

                    <button type="button" class="faithful-assistant-new" data-assistant-new>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Nueva tarea</span>
                    </button>

                    <div class="faithful-assistant-history-heading">Recientes</div>
                    <nav class="faithful-assistant-history" data-assistant-history-list aria-label="Conversaciones recientes"></nav>
                    <p class="faithful-assistant-history-empty" data-assistant-history-empty>No hay conversaciones todavía.</p>

                    <footer class="faithful-assistant-sidebar-footer">
                        <span class="faithful-assistant-avatar" aria-hidden="true"><?=$h($userInitial)?></span>
                        <span><strong><?=$h($userName)?></strong><small>Memoria persistente</small></span>
                    </footer>
                </aside>

                <main class="faithful-assistant-main">
                    <header class="faithful-assistant-topbar">
                        <button type="button" class="faithful-assistant-icon-button" data-assistant-history-toggle aria-label="Ocultar historial" aria-expanded="true" title="Mostrar u ocultar historial">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="4" width="17" height="16" rx="2.5"/><path d="M9 4v16"/></svg>
                        </button>
                        <div class="faithful-assistant-heading">
                            <strong data-assistant-title>Conversación nueva</strong>
                            <span><i aria-hidden="true"></i><b data-assistant-context-label><?=$h($contextLabel)?></b></span>
                        </div>
                        <div class="faithful-assistant-topbar-actions">
                            <span class="faithful-assistant-prototype-pill">Lectura segura</span>
                            <select class="faithful-assistant-provider-select" data-assistant-provider-select aria-label="Proveedor del asistente">
                                <option value="gemini"<?=$provider === 'gemini' ? ' selected' : ''?>>Gemini (GCP)</option>
                                <option value="openai"<?=$provider === 'openai' ? ' selected' : ''?>>OpenAI</option>
                            </select>
                            <button type="button" class="faithful-assistant-mode-button" data-assistant-focus aria-label="Ampliar a pantalla completa" title="Ampliar a pantalla completa">
                                <svg class="icon-expand" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v5M15 4h5v5M9 20H4v-5M15 20h5v-5"/></svg>
                                <svg class="icon-contract" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h5V4M20 9h-5V4M4 15h5v5M20 15h-5v5"/></svg>
                                <span data-assistant-focus-label>Ampliar</span>
                            </button>
                            <button type="button" class="faithful-assistant-icon-button" data-assistant-close aria-label="Close Artwork Assistant" title="Close">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                            </button>
                        </div>
                    </header>

                    <div class="faithful-assistant-thread" data-assistant-thread aria-live="polite">
                        <div class="faithful-assistant-thread-inner" data-assistant-messages></div>
                    </div>

                    <footer class="faithful-assistant-composer-area">
                        <div class="faithful-assistant-error" data-assistant-error hidden></div>
                        <div class="faithful-assistant-visual-context" data-assistant-visual-context hidden></div>
                        <form class="faithful-assistant-composer" data-assistant-form>
                            <div class="faithful-assistant-composer-tools">
                                <button type="button" data-assistant-attach-image aria-label="Adjuntar una captura o imagen" title="Adjuntar imagen">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 12.5 13.8 7.2a3.2 3.2 0 0 1 4.5 4.5l-7.1 7.1a5 5 0 0 1-7.1-7.1l7.3-7.3"/><path d="m7.8 15.8 7.1-7.1"/></svg>
                                </button>
                                <input type="file" data-assistant-image-input accept="image/png,image/jpeg,image/webp" hidden>
                                <button type="button" data-assistant-select-target aria-label="Señalar un elemento de la pantalla" title="Señalar elemento">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/></svg>
                                </button>
                                <button type="button" data-assistant-capture-screen aria-label="Incluir una captura de la pantalla actual" title="Incluir pantalla">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                                </button>
                            </div>
                            <label class="sr-only" for="faithful-assistant-input">Mensaje para Artwork Assistant</label>
                            <textarea id="faithful-assistant-input" data-assistant-input maxlength="6000" rows="4" placeholder="Escribe lo que necesitas hacer en Artwork…"></textarea>
                            <button type="submit" class="faithful-assistant-send" data-assistant-send aria-label="Enviar mensaje" disabled>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6.5 10.5 12 5l5.5 5.5"/></svg>
                            </button>
                        </form>
                        <div class="faithful-assistant-composer-meta">
                            <span>Pega una captura con Ctrl+V · Enter para enviar · Shift + Enter para una nueva línea</span>
                            <strong>Las respuestas y la memoria se guardan en Artwork</strong>
                        </div>
                    </footer>
                </main>
            </section>
        </div>
        <script src="assets/assistant.js?v=20260722en" defer></script>
        <?php
    }
}

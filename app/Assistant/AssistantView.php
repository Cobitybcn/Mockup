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
        $quickActions = self::quickActions((string)$context['page_type']);
        ?>
        <link rel="stylesheet" href="assets/assistant.css?v=20260715a">
        <div class="faithful-assistant" data-assistant-root data-endpoint="assistant_api.php" data-csrf="<?=$h($_SESSION['assistant_csrf'])?>" data-context="<?=$h($encoded)?>">
            <button type="button" class="faithful-assistant-launcher" data-assistant-open aria-label="Abrir asistente" aria-expanded="false"><span>AI</span></button>
            <div class="faithful-assistant-backdrop" data-assistant-close hidden></div>
            <aside class="faithful-assistant-panel" aria-label="Asistente de Artwork" aria-hidden="true">
                <header class="faithful-assistant-header">
                    <div><span>Artwork Assistant</span><strong>Contexto activo</strong></div>
                    <div><button type="button" class="faithful-assistant-new" data-assistant-new>Nueva</button><button type="button" class="faithful-assistant-close" data-assistant-close aria-label="Cerrar">×</button></div>
                </header>
                <p class="faithful-assistant-context" data-assistant-context><?=$h(ucwords(str_replace('_', ' ', (string)$context['page_type'])))?></p>
                <div class="faithful-assistant-quick-actions">
                    <?php foreach ($quickActions as $action): ?>
                        <button type="button" data-assistant-prompt="<?=$h($action['prompt'])?>"><?=$h($action['label'])?></button>
                    <?php endforeach; ?>
                </div>
                <div class="faithful-assistant-messages" data-assistant-messages aria-live="polite">
                    <div class="faithful-assistant-message faithful-assistant-message-system">Estoy disponible en esta pantalla. Solo consultaré datos autorizados para la cuenta actual.</div>
                </div>
                <div class="faithful-assistant-error" data-assistant-error hidden></div>
                <form class="faithful-assistant-composer" data-assistant-form>
                    <label class="sr-only" for="faithful-assistant-input">Mensaje para el asistente</label>
                    <textarea id="faithful-assistant-input" data-assistant-input maxlength="6000" rows="3" placeholder="Pregunta, analiza o prepara una tarea…" required></textarea>
                    <button type="submit" data-assistant-send>Enviar</button>
                </form>
                <small class="faithful-assistant-note">Versión inicial de lectura. No modifica obras ni publicaciones.</small>
            </aside>
        </div>
        <script src="assets/assistant.js?v=20260715a" defer></script>
        <?php
    }

    private static function quickActions(string $pageType): array
    {
        return match ($pageType) {
            'artwork_detail' => [
                ['label' => 'Explicar esta obra', 'prompt' => 'Resume la obra abierta usando únicamente sus datos autorizados.'],
                ['label' => 'Preparar descripción', 'prompt' => 'Propón una descripción para la obra abierta, sin guardar cambios.'],
                ['label' => 'Preparar SEO', 'prompt' => 'Prepara una propuesta SEO para la obra abierta, sin modificar datos.'],
            ],
            'series' => [
                ['label' => 'Analizar la serie', 'prompt' => 'Analiza la serie actual y resume su coherencia.'],
                ['label' => 'Preparar SEO', 'prompt' => 'Prepara título, descripción y palabras clave SEO para esta serie.'],
            ],
            'mockup_results', 'mockup_album', 'mockup_lab' => [
                ['label' => 'Explicar resultados', 'prompt' => 'Explica los mockups disponibles en el contexto actual.'],
                ['label' => 'Comparar seleccionados', 'prompt' => 'Compara los mockups seleccionados usando únicamente los datos disponibles.'],
            ],
            'website_publisher' => [
                ['label' => 'Revisar contenido', 'prompt' => 'Revisa el contenido del website disponible en esta pantalla.'],
                ['label' => 'Preparar SEO', 'prompt' => 'Prepara una propuesta SEO para el contexto actual del website.'],
            ],
            'social_publishing' => [
                ['label' => 'Preparar publicación', 'prompt' => 'Prepara una propuesta de publicación social para el contexto actual.'],
            ],
            default => [
                ['label' => 'Explicar esta pantalla', 'prompt' => 'Explica qué información autorizada está disponible en esta pantalla.'],
                ['label' => 'Preparar tarea Codex', 'prompt' => 'Ayúdame a preparar una tarea técnica para Codex sobre esta pantalla.'],
            ],
        };
    }
}

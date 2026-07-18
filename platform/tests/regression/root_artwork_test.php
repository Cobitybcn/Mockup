<?php
declare(strict_types=1);

/**
 * Zona protegida: "Generacion de obra raiz"
 * (ver docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, seccion Zonas protegidas).
 *
 * No llama a Gemini (no genera imagenes). Cubre PromptSettings: que los
 * prompts de obra raiz y el placeholder critico de mockup (encontrado como
 * bug real en la Fase 2 de esta auditoria) nunca queden vacios ni se rompan
 * silenciosamente.
 *
 * NOTA (Fase 7): esta suite tenia tambien una seccion que probaba los
 * metodos de normalizacion de CoreArtworkJsonBuilder via Reflection. Esa
 * clase se elimino en la Fase 7 por no tener ningun llamador en todo el
 * repositorio (confirmado por grep en la Fase 3); los .core.json que
 * consumen otros archivos ya existian en disco y no dependen de esta clase
 * para seguir leyendose. Testear metodos de una clase eliminada no protege
 * nada, asi que esa seccion se quito en vez de mantenerse.
 */
function run_root_artwork_regression_tests(): void
{
    TestHarness::group('PromptSettings: prompts de obra raiz nunca vacios');

    TestHarness::assertNotEmpty(PromptSettings::rootArtworkRules(), 'rootArtworkRules() no vacio');
    TestHarness::assertNotEmpty(PromptSettings::rootArtworkRulesFrontal(), 'rootArtworkRulesFrontal() no vacio');
    TestHarness::assertNotEmpty(PromptSettings::rootArtworkRulesLeft(), 'rootArtworkRulesLeft() no vacio');
    TestHarness::assertNotEmpty(PromptSettings::rootArtworkRulesRight(), 'rootArtworkRulesRight() no vacio');
    TestHarness::assertNotEmpty(PromptSettings::artworkAnalysisPrompt(), 'artworkAnalysisPrompt() no vacio');
    TestHarness::assertTrue(PromptSettings::rootArtworkCount() >= 1, 'rootArtworkCount() >= 1');

    TestHarness::group('PromptSettings: placeholder critico del prompt final de mockup');
    // Guardia directa del hallazgo de la Fase 2: AdminPromptComposerPreview::compose()
    // lanza RuntimeException si falta este placeholder. Editar el prompt ADMIN V7
    // sin este token rompe TODA la generacion de mockups en produccion.
    $adminPrompt = str_replace("\r\n", "\n", PromptSettings::mockupFinalRequest());
    TestHarness::assertContains(
        '{{MOCKUP_CONTEXT_PROPOSAL}}',
        $adminPrompt,
        'mockupFinalRequest() contiene {{MOCKUP_CONTEXT_PROPOSAL}} (AdminPromptComposerPreview.php:21-23 depende de esto)'
    );
    TestHarness::assertContains(
        '{{MOCKUP_CONTEXT_PROPOSAL}}',
        str_replace("\r\n", "\n", (string)(PromptSettings::builtInDirectives()['mockup_final_request'] ?? '')),
        'el fallback integrado conserva {{MOCKUP_CONTEXT_PROPOSAL}} incluso sin datos locales'
    );
}

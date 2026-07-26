<?php
declare(strict_types=1);

function run_studio_note_change_classifier_tests(): void
{
    $complete = static function (string $title, string $body): array {
        return [
            'title' => $title,
            'body_html' => $body,
            'excerpt' => 'Entradilla editorial precisa.',
            'slug' => 'nota-editorial',
            'seo_title' => 'Nota editorial | Maurizio Valch',
            'seo_description' => 'Descripción editorial de la nota.',
            'alt_text' => 'Obra abstracta roja en un espacio de estudio.',
            'caption' => 'Obra de la serie STRATA en contexto.',
            'tags' => 'pintura abstracta, territorio, memoria, materia, estratos, arte contemporáneo, estudio, proceso',
            'search_terms' => 'pintura abstracta territorial, serie STRATA, memoria y materia, proceso pictórico, arte contemporáneo, estudio de artista',
            'image_metadata' => [[
                'file' => 'strata.jpg',
                'alt_text' => 'Obra abstracta roja en un espacio de estudio.',
                'caption' => 'Obra de la serie STRATA en contexto.',
            ]],
            'meta_source_hash' => 'generated-source',
        ];
    };

    $publishedEs = $complete(
        'La piel del territorio',
        '<h2>Memoria y materia</h2><p>La superficie conserva sus incisiones.</p><p><img src="studio_note_media.php?file=strata.jpg" data-editor-align="center"></p>'
    );
    $publishedEn = $complete(
        'The Skin of Territory',
        '<h2>Memory and Matter</h2><p>The surface preserves its incisions.</p><p><img src="studio_note_media.php?file=strata.jpg" data-editor-align="center"></p>'
    );

    $unchanged = StudioNoteChangeClassifier::classify(
        $publishedEs,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame('published', $unchanged['state'], 'Studio Notes classifier: an identical published pair has no action');
    TestHarness::assertSame('', $unchanged['primary_action'], 'Studio Notes classifier: no action is rendered without changes');

    $visualEs = $publishedEs;
    $visualEs['body_html'] = str_replace(
        'data-editor-align="center"',
        'data-editor-align="left" data-editor-size="small"',
        $visualEs['body_html']
    );
    $visual = StudioNoteChangeClassifier::classify(
        $visualEs,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame('design_changes', $visual['state'], 'Studio Notes classifier: image layout remains visual');
    TestHarness::assertSame('publish_changes', $visual['primary_action'], 'Studio Notes classifier: visual changes publish without AI');
    TestHarness::assertTrue(!$visual['needs_english_update'], 'Studio Notes classifier: image alignment does not stale English');

    $quillNormalizedEs = $publishedEs;
    $quillNormalizedEs['body_html'] = str_replace(
        '<p><img src="studio_note_media.php?file=strata.jpg" data-editor-align="center"></p>',
        '<div class="ql-align-left"><img src="studio_note_media.php?file=strata.jpg" data-editor-align="left"></div>',
        $quillNormalizedEs['body_html']
    );
    $quillNormalized = StudioNoteChangeClassifier::classify(
        $quillNormalizedEs,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame(
        'publish_changes',
        $quillNormalized['primary_action'],
        'Studio Notes classifier: Quill image wrappers do not trigger a semantic English adaptation'
    );

    $englishWithoutImages = $publishedEn;
    $englishWithoutImages['body_html'] = StudioNoteMediaService::removeImages(
        $englishWithoutImages['body_html']
    );
    $missingEnglishMedia = StudioNoteChangeClassifier::classify(
        $visualEs,
        $publishedEs,
        $englishWithoutImages,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame(
        'publish_changes',
        $missingEnglishMedia['primary_action'],
        'Studio Notes classifier: a published English image fallback does not require Vertex'
    );
    $recoveredEnglishBody = StudioNoteMediaService::mirrorImagesWithPublishedFallback(
        $visualEs['body_html'],
        $englishWithoutImages['body_html'],
        $publishedEn['body_html']
    );
    TestHarness::assertSame(
        1,
        preg_match_all('/<img\b/iu', $recoveredEnglishBody),
        'Studio Notes editor: the published English image is restored before publication'
    );
    TestHarness::assertContains(
        'data-editor-align="left"',
        $recoveredEnglishBody,
        'Studio Notes editor: the restored English image receives the current Spanish alignment'
    );

    $textEs = $publishedEs;
    $textEs['body_html'] = str_replace('incisiones', 'incisiones y huellas', $textEs['body_html']);
    $textOnly = StudioNoteChangeClassifier::classify(
        $textEs,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame('update_english', $textOnly['primary_action'], 'Studio Notes classifier: Spanish text requests an English update');

    $manualEn = $publishedEn;
    $manualEn['body_html'] = str_replace('incisions', 'incisions and traces', $manualEn['body_html']);
    $bothEdited = StudioNoteChangeClassifier::classify(
        $textEs,
        $publishedEs,
        $manualEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertSame('publish_changes', $bothEdited['primary_action'], 'Studio Notes classifier: manual bilingual edits are not overwritten');
    TestHarness::assertSame('Editado manualmente', $bothEdited['english_status'], 'Studio Notes classifier: manual English is visible');

    $seoEs = $publishedEs;
    $seoEs['seo_description'] = 'Descripción SEO corregida manualmente.';
    $seoOnly = StudioNoteChangeClassifier::classify(
        $seoEs,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertTrue($seoOnly['seo_changed'], 'Studio Notes classifier: manual SEO is detected');
    TestHarness::assertSame('publish_changes', $seoOnly['primary_action'], 'Studio Notes classifier: manual SEO publishes without a full analysis');

    $externalImage = $publishedEs;
    $externalImage['body_html'] .= '<p><img src="studio_note_media.php?file=external.jpg"></p>';
    $externalMedia = StudioNoteChangeClassifier::classify(
        $externalImage,
        $publishedEs,
        $publishedEn,
        $publishedEn,
        true,
        true
    );
    TestHarness::assertTrue($externalMedia['media_requires_analysis'], 'Studio Notes classifier: external media without SEO requests only the pending preparation');
    TestHarness::assertSame('update_english', $externalMedia['primary_action'], 'Studio Notes classifier: incomplete media metadata is prepared before publication');

    $initialIncomplete = $complete('Nota nueva', '<p>Original español.</p>');
    $emptyEnglish = [];
    $initial = StudioNoteChangeClassifier::classify(
        $initialIncomplete,
        [],
        $emptyEnglish,
        [],
        false,
        false
    );
    TestHarness::assertSame('update_english', $initial['primary_action'], 'Studio Notes classifier: a new Spanish note prepares English before publication');

    $newSpanish = $complete(
        'El ascenso sin escalera',
        '<p>El ascenso se integra en la materia.</p><p><img src="studio_note_media.php?file=strata.jpg"></p>'
    );
    $newEnglish = $complete(
        'The Ascent Without a Ladder',
        '<p>The ascent becomes integrated into the material.</p><p><img src="studio_note_media.php?file=strata.jpg"></p>'
    );
    $newSpanish['image_metadata'] = [];
    $newEnglish['image_metadata'] = [];
    $catalogSources = [[
        'type' => 'mockup',
        'file' => 'strata.jpg',
        'editorialGuide' => [
            'altText' => 'Obra roja de STRATA en un espacio arquitectónico.',
            'caption' => 'Vista de conjunto de una obra de STRATA.',
        ],
        'editorialGuideEn' => [
            'altText' => 'Red STRATA work in an architectural space.',
            'caption' => 'Overall view of a work from STRATA.',
        ],
    ]];
    $newReady = StudioNoteChangeClassifier::classify(
        StudioNoteMediaService::hydrateImageMetadata($newSpanish, $catalogSources, 'es'),
        [],
        StudioNoteMediaService::hydrateImageMetadata($newEnglish, $catalogSources, 'en'),
        [],
        false,
        false
    );
    TestHarness::assertSame(
        'publish_changes',
        $newReady['primary_action'],
        'Studio Notes classifier: a complete new bilingual note with catalog image SEO offers PUBLICAR'
    );
    TestHarness::assertTrue(
        !$newReady['media_requires_analysis'],
        'Studio Notes classifier: recovered mockup SEO does not request Vertex again'
    );

    $protected = StudioNoteChangeClassifier::protectedSpanishSeoFields($seoEs, $publishedEs);
    TestHarness::assertTrue(in_array('seo_description', $protected, true), 'Studio Notes classifier: manual SEO fields are protected');
    TestHarness::assertTrue(!in_array('seo_title', $protected, true), 'Studio Notes classifier: unchanged generated SEO may be refreshed');
}

<?php
declare(strict_types=1);

final class BilingualEditorialService
{
    private const ENTITY_TYPES = ['series', 'artwork', 'mockup', 'studio_note'];

    public function __construct(private PDO $pdo)
    {
    }

    public function isEnabled(int $userId): bool
    {
        if ($userId <= 0) return false;
        $stmt = $this->pdo->prepare('SELECT enabled FROM bilingual_editorial_settings WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * La ficha editorial de un mockup es una capacidad Pro, no un piloto manual.
     * La bandera legacy de `bilingual_editorial_settings` se mantiene para no
     * cambiar el comportamiento de quien ya la tiene encendida, pero un artista
     * Pro que nunca la tocó también debe poder ver la ficha de sus mockups.
     */
    public function canUseMockupFicha(int $ownerId, bool $viewerIsAdmin = false): bool
    {
        return $viewerIsAdmin
            || $this->isEnabled($ownerId)
            || FeatureAccess::allowsUserId($ownerId, FeatureAccess::EDITORIAL_MANAGE, $this->pdo);
    }

    public function setEnabled(int $userId, bool $enabled): void
    {
        $now = date(DATE_ATOM);
        $working = $this->sourceLocale($userId);
        $publication = $this->primaryAdaptationTarget($userId) ?: $working;
        if ($this->isMysql()) {
            $stmt = $this->pdo->prepare("INSERT INTO bilingual_editorial_settings
                (user_id,enabled,source_locale,publication_locale,created_at,updated_at)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),source_locale=VALUES(source_locale),publication_locale=VALUES(publication_locale),updated_at=VALUES(updated_at)");
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO bilingual_editorial_settings
                (user_id,enabled,source_locale,publication_locale,created_at,updated_at)
                VALUES (?,?,?,?,?,?)
                ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled,source_locale=excluded.source_locale,publication_locale=excluded.publication_locale,updated_at=excluded.updated_at");
        }
        $stmt->execute([$userId, $enabled ? 1 : 0, $working, $publication, $now, $now]);
    }

    /**
     * Idioma de trabajo del artista: el idioma en el que analiza, genera y
     * revisa. Es la fuente de toda adaptacion.
     */
    public function sourceLocale(int $userId): string
    {
        return $this->policy($userId)['working_locale'];
    }

    /**
     * Idiomas de publicacion que requieren adaptacion desde el idioma de
     * trabajo. Vacio cuando el artista publica solo en su idioma.
     *
     * @return list<string>
     */
    public function adaptationTargets(int $userId): array
    {
        return LanguagePolicy::adaptationTargets($this->policy($userId));
    }

    public function primaryAdaptationTarget(int $userId): string
    {
        $targets = $this->adaptationTargets($userId);
        return $targets[0] ?? '';
    }

    /**
     * Resolves the initial arrow direction on the server so the control is not
     * dependent on JavaScript merely to become visible.
     *
     * @return array{source:string,target:string,direction:string,label:string}
     */
    public function adaptationDirection(array $sourceContent, array $adaptedContent, int $userId = 0): array
    {
        $policy = LanguagePolicy::forUser($userId, $this->pdo);
        $working = $policy['working_locale'];
        $targets = LanguagePolicy::adaptationTargets($policy);
        $target = $targets[0] ?? '';
        if ($target !== ''
            && $this->hasMeaningfulContent($sourceContent)
            && $this->hasMissingContent($sourceContent, $adaptedContent)) {
            return [
                'source' => $working,
                'target' => $target,
                'direction' => $working . '-' . $target,
                'label' => 'Completar ' . LanguagePolicy::localeLabel($target),
            ];
        }
        return ['source' => '', 'target' => '', 'direction' => '', 'label' => 'Adaptación completa'];
    }

    /** @return array{working_locale:string,interface_locale:string,publication_locales:list<string>,is_explicit:bool} */
    private function policy(int $userId): array
    {
        return LanguagePolicy::forUser($userId, $this->pdo);
    }

    /** @return list<string> */
    private function storageLocales(): array
    {
        return array_keys(LanguagePolicy::supportedLocales());
    }

    /**
     * Idioma cuya copia alimenta las fichas heredadas que consumen el sitio
     * publico y los canales. El ingles internacional conserva ese papel cuando
     * esta entre los idiomas de publicacion; si no, la copia publica es la del
     * idioma de trabajo.
     */
    private function legacySyncLocale(int $userId): string
    {
        $policy = $this->policy($userId);
        return in_array('en', $policy['publication_locales'], true) ? 'en' : $policy['working_locale'];
    }

    /** @return array{content:array,private_memo:string,status:string,source_hash:string,is_published:bool,published_content:array,published_at:string,has_unpublished_changes:bool} */
    public function get(int $userId, string $entityType, int $entityId, string $locale, array $fallback = []): array
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $working = $this->sourceLocale($userId);
        $stmt = $this->pdo->prepare('SELECT content_json,private_memo,status,source_hash,is_published,published_content_json,published_at FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
        $stmt->execute([$userId, $entityType, $entityId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['content' => $this->normalizeContent($fallback), 'private_memo' => '', 'status' => $locale !== $working && $fallback !== [] ? 'current' : 'unprepared', 'source_hash' => '', 'is_published' => false, 'published_content' => [], 'published_at' => '', 'has_unpublished_changes' => false];
        }
        $content = json_decode((string)$row['content_json'], true);
        $publishedContent = json_decode((string)($row['published_content_json'] ?? ''), true);
        $normalizedContent = is_array($content) ? $this->normalizeContent($content) : [];
        $normalizedPublishedContent = is_array($publishedContent) ? $this->normalizeContent($publishedContent) : [];
        $isPublished = (int)($row['is_published'] ?? 0) === 1;
        $status = trim((string)$row['status']);
        if ($locale !== $working && $status === 'current') {
            $sourceStmt = $this->pdo->prepare('SELECT content_json FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
            $sourceStmt->execute([$userId, $entityType, $entityId, $working]);
            $sourceContent = json_decode((string)$sourceStmt->fetchColumn(), true);
            $normalizedSource = is_array($sourceContent) ? $this->normalizeContent($sourceContent) : [];
            if ($this->hasMissingContent($normalizedSource, $normalizedContent)) {
                $status = 'stale';
                $this->pdo->prepare("UPDATE bilingual_editorial_content SET status='stale',updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=?")
                    ->execute([date(DATE_ATOM), $userId, $entityType, $entityId, $locale]);
            }
        }
        return [
            'content' => $normalizedContent,
            'private_memo' => trim((string)$row['private_memo']),
            'status' => $status,
            'source_hash' => trim((string)$row['source_hash']),
            'is_published' => $isPublished,
            'published_content' => $normalizedPublishedContent,
            'published_at' => trim((string)($row['published_at'] ?? '')),
            'has_unpublished_changes' => $isPublished && $normalizedContent !== $normalizedPublishedContent,
        ];
    }

    /**
     * Publica u oculta el contenido maestro (el del idioma de trabajo). El
     * nombre conserva la epoca en la que el master era siempre espanol.
     */
    public function setSpanishPublished(int $userId, string $entityType, int $entityId, bool $published): array
    {
        return $this->setPublished($userId, $entityType, $entityId, $this->sourceLocale($userId), $published);
    }

    public function setPublished(
        int $userId,
        string $entityType,
        int $entityId,
        string $locale,
        bool $published
    ): array
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        if ($published) {
            // EDITORIAL_CORE — compuerta al publicar: la identidad confirmada
            // se re-verifica y corrige ANTES de congelar el snapshot. Publicar
            // contenido con un nombre divergente deja de ser posible.
            $this->guardIdentityBeforePublish($userId, $entityType, $entityId, $locale);
        }
        $localized = $this->get($userId, $entityType, $entityId, $locale);
        if ($published && !$this->hasMeaningfulContent($localized['content'])) {
            throw new RuntimeException('Add localized content before publishing it.');
        }
        if ($published) {
            $stmt = $this->pdo->prepare('UPDATE bilingual_editorial_content SET is_published=1,published_content_json=content_json,published_at=?,updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=?');
            $now = date(DATE_ATOM);
            $stmt->execute([$now, $now, $userId, $entityType, $entityId, $locale]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE bilingual_editorial_content SET is_published=0,updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=?');
            $stmt->execute([date(DATE_ATOM), $userId, $entityType, $entityId, $locale]);
        }
        $state = $this->get($userId, $entityType, $entityId, $locale);
        return [
            'is_published' => $state['is_published'],
            'published_at' => $state['published_at'],
            'has_unpublished_changes' => $state['has_unpublished_changes'],
            'locale' => $locale,
        ];
    }

    /**
     * Stores an explicit AI-assisted adaptation. Existing target fields win, so
     * the arrow can safely complete a partial language without replacing edits.
     *
     * @return array{content:array,status:string,english_status:string}
     */
    public function saveAdaptation(
        int $userId,
        string $entityType,
        int $entityId,
        string $sourceLocale,
        string $targetLocale,
        array $generatedContent
    ): array {
        $this->assertIdentity($entityType, $entityId, $sourceLocale);
        $this->assertIdentity($entityType, $entityId, $targetLocale);
        if ($sourceLocale !== $this->sourceLocale($userId)
            || !in_array($targetLocale, $this->adaptationTargets($userId), true)) {
            throw new InvalidArgumentException('La adaptación editorial va del idioma de trabajo del artista a uno de sus idiomas de publicación.');
        }
        $this->assertOwned($userId, $entityType, $entityId);
        $source = $this->get($userId, $entityType, $entityId, $sourceLocale);
        $target = $this->get($userId, $entityType, $entityId, $targetLocale);
        $merged = $this->mergeMissingContent((array)$target['content'], $this->normalizeContent($generatedContent));
        $sourceHash = hash('sha256', json_encode($source['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = $this->hasMissingContent((array)$source['content'], $merged) ? 'stale' : 'current';
        $this->upsertRow($userId, $entityType, $entityId, $targetLocale, $merged, (string)$target['private_memo'], $status, $sourceHash);
        if ($targetLocale === $this->legacySyncLocale($userId)) {
            $this->syncPublicCopyToLegacy($userId, $entityType, $entityId, $merged);
        }
        return ['content' => $merged, 'status' => $status, 'english_status' => $status];
    }

    /**
     * Seeds future Spanish-first artwork analysis without replacing text the
     * artist has already written.
     */
    public function fillSourceFromAnalysis(int $userId, string $entityType, int $entityId, array $analysisContent): array
    {
        $locale = $this->sourceLocale($userId);
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        $current = $this->get($userId, $entityType, $entityId, $locale);
        $merged = $this->mergeMissingContent($current['content'], $this->normalizeContent($analysisContent));
        if ($merged === $current['content']) return $merged;
        $hash = hash('sha256', json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->upsertRow($userId, $entityType, $entityId, $locale, $merged, (string)$current['private_memo'], 'source', $hash);
        $this->markAdaptationsStale($userId, $entityType, $entityId);
        return $merged;
    }

    public function seedEnglishFromLegacy(int $userId, string $entityType, int $entityId): array
    {
        return [];
    }

    public function save(int $userId, string $entityType, int $entityId, string $locale, array $content, string $privateMemo = '', bool $editedByArtist = false): array
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        $content = $this->normalizeContent($content);
        $privateMemo = trim($privateMemo);

        $newHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $working = $this->sourceLocale($userId);
        if ($locale === $working) {
            $semanticChanged = true;
            if ($entityType === 'studio_note') {
                $previous = $this->get($userId, $entityType, $entityId, $working);
                $previousContent = (array)($previous['content'] ?? []);
                $semanticChanged = $previousContent === []
                    || !hash_equals(
                        StudioNoteMediaService::semanticHash($previousContent),
                        StudioNoteMediaService::semanticHash($content)
                    );
            }
            $this->upsertRow($userId, $entityType, $entityId, $working, $content, $privateMemo, 'source', $newHash);
            $this->markEditOrigin($userId, $entityType, $entityId, $working, $editedByArtist);
            // Reescribir un mockup lo pone al dia con la lectura de su obra.
            $this->clearOutdatedMark($userId, $entityType, $entityId);
            if ($semanticChanged) {
                $this->markAdaptationsStale($userId, $entityType, $entityId);
            }
            if ($working === $this->legacySyncLocale($userId)) {
                // Sin adaptaciones, la copia publica de las fichas heredadas es
                // el propio master del idioma de trabajo.
                $this->syncPublicCopyToLegacy($userId, $entityType, $entityId, $content);
            }
            $adaptationLocale = $this->primaryAdaptationTarget($userId);
            $adaptationStatus = $adaptationLocale !== ''
                ? (string)$this->get($userId, $entityType, $entityId, $adaptationLocale)['status']
                : 'not_required';
            return ['status' => 'source', 'english_status' => $adaptationStatus];
        }
        $sourceRow = $this->get($userId, $entityType, $entityId, $working);
        $sourceHash = hash('sha256', json_encode($sourceRow['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = $this->hasMissingContent((array)$sourceRow['content'], $content) ? 'stale' : 'current';
        $this->upsertRow($userId, $entityType, $entityId, $locale, $content, $privateMemo, $status, $sourceHash);
        $this->markEditOrigin($userId, $entityType, $entityId, $locale, $editedByArtist);
        if ($locale === $this->legacySyncLocale($userId)) {
            $this->syncPublicCopyToLegacy($userId, $entityType, $entityId, $content);
        }
        return ['status' => $status, 'english_status' => $status];
    }

    /**
     * EDITORIAL_CORE Libro VI Cap. 4: registra si la version vigente de la
     * fila la escribio el artista (soberana) o el sistema (regenerable).
     */
    private function markEditOrigin(int $userId, string $entityType, int $entityId, string $locale, bool $editedByArtist): void
    {
        try {
            $this->pdo->prepare('UPDATE bilingual_editorial_content SET edited_by_artist=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=?')
                ->execute([$editedByArtist ? 1 : 0, $userId, $entityType, $entityId, $locale]);
        } catch (Throwable) {
            // Columna aun no migrada en una base vieja: el guardado del
            // contenido no debe fallar por el flag.
        }
    }

    /**
     * Mockups de una obra cuya version vigente la escribio el artista a mano.
     * La cascada de regeneracion los saltea (edicion soberana).
     *
     * @return list<int>
     */
    public function artistEditedMockupIds(int $userId, int $artworkId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT bec.entity_id FROM bilingual_editorial_content bec
                 JOIN mockups m ON m.id = bec.entity_id AND m.user_id = bec.user_id
                 WHERE bec.user_id=? AND bec.entity_type='mockup' AND m.source_artwork_id=? AND bec.edited_by_artist=1"
            );
            $stmt->execute([$userId, $artworkId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Compuerta de identidad previa al snapshot publicado. La identidad
     * vigente (titulo de obra y de serie) se lee EN VIVO de las tablas
     * canonicas; el JSON viejo de analisis se consulta unicamente como lista
     * de alias a NO usar (higiene de transicion), jamas como fuente.
     */
    private function guardIdentityBeforePublish(int $userId, string $entityType, int $entityId, string $locale): void
    {
        if (!in_array($entityType, ['artwork', 'mockup'], true)) return;
        $artworkId = $entityType === 'artwork' ? $entityId : 0;
        if ($entityType === 'mockup') {
            $stmt = $this->pdo->prepare('SELECT source_artwork_id FROM mockups WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$entityId, $userId]);
            $artworkId = (int)$stmt->fetchColumn();
        }
        if ($artworkId <= 0) return;

        $stmt = $this->pdo->prepare(
            'SELECT a.final_title, s.title AS series_title
             FROM artworks a LEFT JOIN artwork_series s ON s.id=a.series_id AND s.user_id=a.user_id
             WHERE a.id=? AND a.user_id=? LIMIT 1'
        );
        $stmt->execute([$artworkId, $userId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identity)) return;
        $realTitle = trim((string)($identity['final_title'] ?? ''));
        $seriesTitle = trim((string)($identity['series_title'] ?? ''));
        if ($realTitle === '') return;

        $aliases = [];
        try {
            $stmt = $this->pdo->prepare("SELECT generated_json FROM artwork_sheets WHERE user_id=? AND canonical_artwork_id=? AND COALESCE(status,'')<>'merged' ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$userId, $artworkId]);
            $blob = json_decode((string)$stmt->fetchColumn(), true);
            $legacyTitle = trim((string)($blob['canonical_editorial']['title'] ?? ''));
            if ($legacyTitle !== '' && strcasecmp($legacyTitle, $realTitle) !== 0) {
                $aliases[] = $legacyTitle;
            }
        } catch (Throwable) {
            // Sin blob viejo no hay alias que limpiar.
        }
        if ($aliases === []) return;

        $stmt = $this->pdo->prepare('SELECT content_json FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
        $stmt->execute([$userId, $entityType, $entityId, $locale]);
        $content = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($content) || $content === []) return;
        $cleaned = EditorialIdentityGuard::rewriteAliases($content, $realTitle, $aliases, $seriesTitle, []);
        if ($cleaned !== $content) {
            $this->pdo->prepare('UPDATE bilingual_editorial_content SET content_json=?,updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=?')
                ->execute([
                    json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    date(DATE_ATOM),
                    $userId, $entityType, $entityId, $locale,
                ]);
        }
    }

    public function saveUniversalTitle(int $userId, string $entityType, int $entityId, string $title): string
    {
        // El titulo universal no pertenece a un idioma; se valida la identidad
        // de la entidad con el idioma de trabajo del artista.
        $this->assertIdentity($entityType, $entityId, $this->sourceLocale($userId));
        $this->assertOwned($userId, $entityType, $entityId);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') throw new RuntimeException('The universal title cannot be empty.');
        $now = date(DATE_ATOM);

        if ($entityType === 'series') {
            $stmt = $this->pdo->prepare('UPDATE artwork_series SET title=?,updated_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title, $now, $entityId, $userId]);
            $this->pdo->prepare('UPDATE artworks SET series=?,updated_at=? WHERE series_id=? AND user_id=?')->execute([$title, $now, $entityId, $userId]);
        } elseif ($entityType === 'artwork') {
            (new ArtworkSheetService($this->pdo))->saveArtworkTitle($entityId, $userId, $title);
        } elseif ($entityType === 'mockup') {
            $mockup = $this->mockupRow($userId, $entityId);
            $this->ensureMockupSheet($userId, $mockup);
            $this->pdo->prepare('UPDATE mockup_sheets SET title=?,updated_at=? WHERE user_id=? AND (mockup_id=? OR mockup_file=?)')->execute([$title, $now, $userId, $entityId, (string)$mockup['mockup_file']]);
        } else {
            $this->pdo->prepare("UPDATE social_campaigns SET title=?,updated_at=? WHERE id=? AND user_id=? AND campaign_type='website_blog'")
                ->execute([$title, $now, $entityId, $userId]);
        }
        return $title;
    }

    public function backfillEnglish(int $userId): array
    {
        return ['series' => 0, 'artwork' => 0, 'mockup' => 0, 'studio_note' => 0];
    }

    /**
     * Campos DERIVADOS: no los escribe el artista ni son la lectura de la obra.
     * Se calculan a partir de ella para un canal —el listing de Saatchi, el
     * vocabulario de descubrimiento del sitio— y por eso se aprueban aparte.
     */
    public const DERIVED_FIELDS = [
        'saatchi_title',
        'saatchi_description',
        'saatchi_caption',
        'saatchi_keywords',
        'discovery_keywords',
        // Analisis segun influencias: se deriva de la lectura y de las
        // afinidades declaradas, no ES la lectura. Regenerarlo no marca la
        // lectura como cambiada ni dispara cascadas hacia los mockups.
        'influences_analysis',
    ];

    /**
     * Marca los mockups de una obra como "mi fuente cambio": su texto salio de
     * una lectura que ya no es la aprobada.
     *
     * Publicar MARCA, no regenera. Escribir el texto de cada mockup es trabajo
     * de la ficha de la obra, que es donde el artista lo decide viendo cuantos
     * son. La edicion soberana se respeta: lo que el artista escribio a mano no
     * se marca, porque no hay nada que propagarle.
     *
     * @return list<int> ids de los mockups marcados
     */
    public function markMockupsOutdated(int $userId, int $artworkId): array
    {
        $protegidos = $this->artistEditedMockupIds($userId, $artworkId);
        $stmt = $this->pdo->prepare('SELECT id FROM mockups WHERE user_id=? AND source_artwork_id=? ORDER BY id');
        $stmt->execute([$userId, $artworkId]);

        $now = date(DATE_ATOM);
        $marcados = [];
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $mockupId) {
            if (in_array($mockupId, $protegidos, true)) {
                continue;
            }
            try {
                $this->pdo->prepare('UPDATE bilingual_editorial_content SET upstream_changed_at=?,updated_at=?
                    WHERE user_id=? AND entity_type=? AND entity_id=?')
                    ->execute([$now, $now, $userId, 'mockup', $mockupId]);
            } catch (Throwable) {
                // Base anterior a la migracion del 2026-08-04: sin columna no hay
                // marca, y publicar sigue funcionando igual.
                return $marcados;
            }
            $marcados[] = $mockupId;
        }
        return $marcados;
    }

    /**
     * Los mockups de una obra cuyo texto quedo atras de la lectura aprobada. Es
     * lo que la ficha cuenta para decirle al artista cuanto hay para regenerar.
     *
     * @return list<int>
     */
    public function outdatedMockupIds(int $userId, int $artworkId): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT DISTINCT b.entity_id
                FROM bilingual_editorial_content b
                JOIN mockups m ON m.id = b.entity_id AND m.user_id = b.user_id
                WHERE b.user_id=? AND b.entity_type='mockup' AND m.source_artwork_id=?
                    AND TRIM(COALESCE(b.upstream_changed_at,'')) <> ''
                ORDER BY b.entity_id");
            $stmt->execute([$userId, $artworkId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    /** Se limpia cuando ese mockup vuelve a escribirse desde la lectura nueva. */
    public function clearOutdatedMark(int $userId, string $entityType, int $entityId): void
    {
        if ($entityType !== 'mockup') {
            return;
        }
        try {
            $this->pdo->prepare("UPDATE bilingual_editorial_content SET upstream_changed_at=''
                WHERE user_id=? AND entity_type=? AND entity_id=?")
                ->execute([$userId, $entityType, $entityId]);
        } catch (Throwable) {
            // Sin columna no hay marca que limpiar.
        }
    }

    /**
     * Si la LECTURA de la entidad cambio respecto de lo ya publicado.
     *
     * EDITORIAL_CORE VI.4 dice que al re-publicar la lectura de una obra sus
     * mockups se regeneran "desde la version nueva": presupone que hay una
     * version nueva. Esta pregunta es la que faltaba hacerse, porque hasta ahora
     * la cascada se disparaba en cada publicacion aunque el texto fuera identico
     * —y regenerar el texto de todos los mockups no es un acto gratuito—.
     *
     * Los campos derivados no cuentan: no son la lectura, se calculan a partir
     * de ella y tienen su propia aprobacion.
     *
     * Sin copia publicada devuelve true: la primera publicacion si propaga.
     */
    public function readingChangedSincePublish(int $userId, string $entityType, int $entityId): bool
    {
        $working = $this->sourceLocale($userId);
        $stmt = $this->pdo->prepare('SELECT content_json,published_content_json,is_published
            FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
        $stmt->execute([$userId, $entityType, $entityId, $working]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !(int)($row['is_published'] ?? 0)) {
            return true;
        }

        $sinDerivados = static function (mixed $json): array {
            $content = json_decode((string)$json, true);
            if (!is_array($content)) {
                return [];
            }
            foreach (self::DERIVED_FIELDS as $campo) {
                unset($content[$campo]);
            }
            ksort($content);
            return $content;
        };

        return $sinDerivados($row['content_json']) !== $sinDerivados($row['published_content_json']);
    }

    /**
     * Mezcla campos DERIVADOS —vocabulario de descubrimiento, campos propios de
     * un canal— en el borrador de un idioma, y nada mas.
     *
     * Existe porque esos campos no son la lectura: se derivan de ella. Por eso
     * esta escritura:
     *
     *   - toca solo `content_json`. La copia publicada la llena `publish()`, que
     *     es la accion del artista: publicar es aprobar, y nada entra ahi sin que
     *     alguien lo haya leido.
     *   - no marca las adaptaciones como viejas. `save()` lo hace siempre para
     *     una obra, y con razon cuando cambia la lectura; pero agregar keywords
     *     al castellano no invalida el texto en ingles, y marcarlo daria una
     *     alarma falsa en cada obra del catalogo.
     *   - conserva `status` y `source_hash` tal como estaban, para no mentirle al
     *     mecanismo que compara la adaptacion con su fuente.
     *
     * @param array<string,string> $fields
     */
    public function mergeDerivedFields(int $userId, string $entityType, int $entityId, string $locale, array $fields): void
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        if ($fields === []) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT content_json,private_memo,status,source_hash FROM bilingual_editorial_content
            WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
        $stmt->execute([$userId, $entityType, $entityId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('No hay contenido editorial en ese idioma: el vocabulario se deriva de una lectura que todavia no existe.');
        }

        $content = json_decode((string)$row['content_json'], true);
        $content = is_array($content) ? $content : [];
        foreach ($fields as $key => $value) {
            $content[$key] = $value;
        }

        $this->upsertRow(
            $userId,
            $entityType,
            $entityId,
            $locale,
            $this->normalizeContent($content),
            (string)$row['private_memo'],
            (string)$row['status'],
            (string)$row['source_hash']
        );
    }

    private function upsertRow(int $userId, string $entityType, int $entityId, string $locale, array $content, string $memo, string $status, string $sourceHash): void
    {
        $now = date(DATE_ATOM);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->isMysql()) {
            $sql = "INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content_json=VALUES(content_json),private_memo=VALUES(private_memo),status=VALUES(status),source_hash=VALUES(source_hash),updated_at=VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,entity_type,entity_id,locale) DO UPDATE SET content_json=excluded.content_json,private_memo=excluded.private_memo,status=excluded.status,source_hash=excluded.source_hash,updated_at=excluded.updated_at";
        }
        $this->pdo->prepare($sql)->execute([$userId, $entityType, $entityId, $locale, $json ?: '{}', $memo, $status, $sourceHash, $now, $now]);
    }

    private function assertOwned(int $userId, string $entityType, int $entityId): void
    {
        if ($entityType === 'studio_note') {
            $stmt = $this->pdo->prepare("SELECT payload_json FROM social_campaigns WHERE id=? AND user_id=? AND campaign_type='website_blog' LIMIT 1");
            $stmt->execute([$entityId, $userId]);
            $payload = json_decode((string)($stmt->fetchColumn() ?: ''), true);
            if (!is_array($payload)
                || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) {
                throw new RuntimeException('Editorial item not found.');
            }
            return;
        }
        $table = $entityType === 'series' ? 'artwork_series' : ($entityType === 'artwork' ? 'artworks' : 'mockups');
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$entityId, $userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Editorial item not found.');
    }

    private function mockupRow(int $userId, int $mockupId): array
    {
        $stmt = $this->pdo->prepare('SELECT id,mockup_file,source_artwork_id FROM mockups WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$mockupId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Mockup not found.');
        return $row;
    }

    private function ensureMockupSheet(int $userId, array $mockup): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mockup_sheets WHERE user_id=? AND (mockup_id=? OR mockup_file=?) ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, (int)$mockup['id'], (string)$mockup['mockup_file']]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $this->pdo->prepare('UPDATE mockup_sheets SET mockup_id=? WHERE id=? AND user_id=? AND (mockup_id IS NULL OR mockup_id=0)')
                ->execute([(int)$mockup['id'], $existingId, $userId]);
            return;
        }

        $artworkId = max(0, (int)($mockup['source_artwork_id'] ?? 0));
        if ($artworkId <= 0) throw new RuntimeException('This mockup is not linked to an artwork.');
        $artworkSheet = $this->pdo->prepare('SELECT id FROM artwork_sheets WHERE user_id=? AND canonical_artwork_id=? ORDER BY id DESC LIMIT 1');
        $artworkSheet->execute([$userId, $artworkId]);
        $artworkSheetId = (int)$artworkSheet->fetchColumn();
        $now = date(DATE_ATOM);
        $insert = $this->pdo->prepare("INSERT INTO mockup_sheets
            (user_id,artwork_sheet_id,artwork_id,mockup_id,mockup_file,user_notes,title,description,keywords,tags,alt_text,caption,status,generated_json,created_at,updated_at)
            VALUES (?,?,?,?,?,'','','','','','','','draft','{}',?,?)");
        $insert->execute([$userId, $artworkSheetId > 0 ? $artworkSheetId : null, $artworkId, (int)$mockup['id'], (string)$mockup['mockup_file'], $now, $now]);
    }

    private function assertIdentity(string $entityType, int $entityId, string $locale): void
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true) || $entityId <= 0 || !in_array($locale, $this->storageLocales(), true)) {
            throw new InvalidArgumentException('Invalid bilingual editorial identity.');
        }
    }

    private function normalizeContent(array $content): array
    {
        $normalized = [];
        foreach ($content as $key => $value) {
            $normalizedKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            $key = is_string($normalizedKey) ? $normalizedKey : '';
            if ($key === '') continue;
            if (is_array($value)) $normalized[$key] = $this->normalizeContent($value);
            elseif (is_scalar($value) || $value === null) $normalized[$key] = $this->plainEditorialText((string)$value);
        }
        ksort($normalized);
        return $normalized;
    }

    private function plainEditorialText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $value) ?? $value;
        $value = preg_replace('/\*\*(.*?)\*\*/us', '$1', $value) ?? $value;
        $value = preg_replace('/__(.*?)__/us', '$1', $value) ?? $value;
        $value = preg_replace('/(?<!\*)\*([^*\r\n]+)\*(?!\*)/u', '$1', $value) ?? $value;
        $value = preg_replace('/`([^`\r\n]+)`/u', '$1', $value) ?? $value;
        $value = preg_replace('/^\s*#{1,6}\s+/mu', '', $value) ?? $value;
        return trim(str_replace(['**', '__'], '', $value));
    }

    private function mergeMissingContent(array $existing, array $generated): array
    {
        $merged = $existing;
        foreach ($generated as $key => $value) {
            if (is_array($value)) {
                $current = is_array($merged[$key] ?? null) ? $merged[$key] : [];
                $merged[$key] = $this->mergeMissingContent($current, $value);
                continue;
            }
            if (trim((string)($merged[$key] ?? '')) === '' && trim((string)$value) !== '') {
                $merged[$key] = trim((string)$value);
            }
        }
        ksort($merged);
        return $merged;
    }

    private function hasMeaningfulContent(array $content): bool
    {
        foreach ($content as $value) {
            if (is_array($value) && $this->hasMeaningfulContent($value)) return true;
            if (!is_array($value) && trim((string)$value) !== '') return true;
        }
        return false;
    }

    private function hasMissingContent(array $source, array $target): bool
    {
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                if ($this->hasMissingContent($value, is_array($target[$key] ?? null) ? $target[$key] : [])) return true;
                continue;
            }
            if (trim((string)$value) !== '' && trim((string)($target[$key] ?? '')) === '') return true;
        }
        return false;
    }

    /**
     * Un cambio en el master deja obsoletas todas las adaptaciones, sea cual
     * sea el par de idiomas del artista.
     */
    private function markAdaptationsStale(int $userId, string $entityType, int $entityId): void
    {
        $this->pdo->prepare("UPDATE bilingual_editorial_content SET status='stale',updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale<>?")
            ->execute([date(DATE_ATOM), $userId, $entityType, $entityId, $this->sourceLocale($userId)]);
    }

    private function syncPublicCopyToLegacy(int $userId, string $entityType, int $entityId, array $content): void
    {
        $now = date(DATE_ATOM);
        if ($entityType === 'series') {
            $this->pdo->prepare('UPDATE artwork_series SET subtitle=?,description=?,long_description=?,tags=?,keywords=?,seo_description=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([
                    (string)($content['subtitle'] ?? ''),
                    (string)($content['short_description'] ?? ''),
                    (string)($content['description'] ?? ''),
                    (string)($content['tags'] ?? ''),
                    (string)($content['search_terms'] ?? ''),
                    (string)($content['seo_description'] ?? ''),
                    $now, $entityId, $userId,
                ]);
            return;
        }
        if ($entityType === 'artwork') {
            $this->pdo->prepare("UPDATE artwork_sheets SET subtitle=?,description=?,short_description=?,keywords=?,tags=?,alt_text=?,caption=?,updated_at=? WHERE user_id=? AND canonical_artwork_id=? AND COALESCE(status,'')<>'merged'")
                ->execute([
                    (string)($content['subtitle'] ?? ''),
                    (string)($content['description'] ?? ''),
                    (string)($content['short_description'] ?? ''),
                    (string)($content['keywords'] ?? $content['search_terms'] ?? ''),
                    (string)($content['tags'] ?? ''),
                    (string)($content['alt_text'] ?? ''),
                    (string)($content['caption'] ?? ''),
                    $now, $userId, $entityId,
                ]);
            return;
        }
        if ($entityType === 'studio_note') {
            return;
        }
        $mockup = $this->mockupRow($userId, $entityId);
        $this->ensureMockupSheet($userId, $mockup);
        $this->pdo->prepare('UPDATE mockup_sheets SET description=?,keywords=?,tags=?,alt_text=?,caption=?,updated_at=? WHERE user_id=? AND (mockup_id=? OR mockup_file=?)')
            ->execute([
                (string)($content['description'] ?? ''),
                (string)($content['search_terms'] ?? $content['keywords'] ?? ''),
                (string)($content['tags'] ?? ''),
                (string)($content['alt_text'] ?? ''),
                (string)($content['caption'] ?? ''),
                $now, $userId, $entityId, (string)$mockup['mockup_file'],
            ]);
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }
}

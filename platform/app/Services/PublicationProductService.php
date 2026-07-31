<?php
declare(strict_types=1);

/**
 * The frozen "producto terminado" of a publication: ONE read-only projection
 * assembling every destination's copy (Pinterest, Instagram, Facebook, TikTok,
 * X placeholder, Saatchi manual package) from the EXISTING editorial memory.
 *
 * EDITORIAL_CORE: projection-never-source — nothing here is editable and the
 * payload must NEVER be fed back into any AI generation (VI.2 governs live
 * context for generation; this snapshot exists only for distribution).
 * Locale rule mirrors the public site (AppPublishedLocalization): the working
 * locale reads the PUBLISHED snapshot (publicar = aprobar); adaptation locales
 * read the reviewed draft content.
 */
final class PublicationProductService
{
    public const SCHEMA = 'publication-product.v4';

    private PublicationService $publications;

    public function __construct(private readonly PDO $pdo, ?PublicationService $publications = null)
    {
        $this->publications = $publications ?? new PublicationService($pdo);
    }

    /**
     * Pure projection: reads live sources, writes nothing.
     *
     * @throws RuntimeException when the page is not published or the media
     *   composition is empty (both are Website-panel responsibilities).
     */
    public function assemble(int $publicationId, int $userId): array
    {
        $publication = $this->publications->get($publicationId, $userId);
        if ((string)$publication['status'] !== 'published') {
            throw new RuntimeException(t('The product starts from the published page: publish the artwork in the Website panel first.', 'El producto parte de la página publicada: publicá primero la obra en el panel Sitio web.'));
        }
        $items = array_values((array)($publication['items'] ?? []));
        if ($items === []) {
            throw new RuntimeException(t('The media composition is empty: select at least one mockup in the Website panel.', 'La composición de media está vacía: seleccioná al menos un mockup en el panel Sitio web.'));
        }

        $sheetStmt = $this->pdo->prepare('SELECT canonical_artwork_id FROM artwork_sheets WHERE id=? AND user_id=? LIMIT 1');
        $sheetStmt->execute([(int)$publication['artwork_sheet_id'], $userId]);
        $artworkId = (int)($sheetStmt->fetchColumn() ?: 0);
        $titleStmt = $this->pdo->prepare('SELECT final_title FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $titleStmt->execute([$artworkId, $userId]);
        $universalTitle = trim((string)($titleStmt->fetchColumn() ?: ''));

        $editorial = new BilingualEditorialService($this->pdo);
        $workingLocale = $editorial->sourceLocale($userId);
        $adaptationLocales = array_values(array_filter(
            $editorial->adaptationTargets($userId),
            static fn(string $locale): bool => $locale !== $workingLocale
        ));
        $locales = array_values(array_unique(array_merge([$workingLocale], $adaptationLocales)));

        $resolvedItems = [];
        foreach ($items as $item) {
            $resolvedItems[] = $item + ['resolved_mockup_id' => $this->resolveMockupId($item, $userId)];
        }
        $mockupIds = array_values(array_filter(array_map(
            static fn(array $item): int => (int)$item['resolved_mockup_id'],
            $resolvedItems
        ), static fn(int $id): bool => $id > 0));

        $rows = $this->editorialRows($userId, $artworkId, $mockupIds, $locales);
        $artworkByLocale = [];
        $artworkSources = [];
        foreach ($locales as $locale) {
            [$content, $status] = $this->localeContent($rows['artwork'][$locale] ?? null, $locale === $workingLocale);
            $artworkByLocale[$locale] = $this->artworkFields($content);
            $artworkSources[$locale] = ['status' => $status];
        }

        $mediaItems = [];
        $mockupSources = [];
        foreach ($resolvedItems as $item) {
            $mockupId = (int)$item['resolved_mockup_id'];
            $entry = [
                'mockup_id' => $mockupId,
                'mockup_sheet_id' => (int)$item['mockup_sheet_id'],
                'file' => basename((string)($item['mockup_file'] ?? '')),
                'position' => (int)($item['position'] ?? 0),
                'role' => (string)($item['role'] ?? 'context'),
            ];
            foreach ($locales as $locale) {
                [$content, $status] = $this->localeContent(
                    $mockupId > 0 ? ($rows['mockup'][$mockupId][$locale] ?? null) : null,
                    $locale === $workingLocale
                );
                if ($mockupId <= 0) {
                    $status = 'unlinked';
                }
                $entry[$locale] = [
                    'caption' => MockupSocialContentService::text($content['caption'] ?? '', (string)($item['caption'] ?? '')),
                    'alt_text' => MockupSocialContentService::text($content['alt_text'] ?? '', (string)($item['alt_text'] ?? '')),
                    'search_terms' => MockupSocialContentService::text($content['search_terms'] ?? ''),
                ];
                $entry['social'][$locale] = $this->socialChannels($content);
                $mockupSources[(string)$mockupId][$locale] = ['status' => $status];
            }
            $mediaItems[] = $entry;
        }

        $video = $this->videoBlock($userId, $artworkId);
        $cover = $mediaItems[0];

        $destinations = [
            'pinterest' => [],
            'instagram' => [],
            'facebook' => [],
            'tiktok' => [
                'video_export_id' => $video['export_id'] ?? null,
                // The carousel travels the whole composition (TikTok accepts up
                // to 35 photos); the cover is the composition cover.
                'carousel_images' => array_values(array_map(
                    static fn(array $item): string => (string)$item['file'],
                    array_slice($mediaItems, 0, 35)
                )),
                'cover_index' => 0,
            ],
            'x' => null,
            'saatchi' => $this->saatchiBlock($artworkByLocale, $mediaItems, $workingLocale, $adaptationLocales),
        ];
        foreach ($locales as $locale) {
            $social = (array)($cover['social'][$locale] ?? []);
            $artworkContent = $artworkByLocale[$locale];

            $pinterest = (array)($social['pinterest'] ?? []);
            $destinations['pinterest'][$locale] = [
                'title' => MockupSocialContentService::text($pinterest['title'] ?? ''),
                'description' => MockupSocialContentService::text($pinterest['description'] ?? ''),
                // Fallback mirrors MockupPinterestDraftService: the cover
                // mockup's own search terms, never the artwork's.
                'keywords' => MockupSocialContentService::list($pinterest['keywords'] ?? [], (string)($cover[$locale]['search_terms'] ?? '')),
                'board_suggestions' => MockupSocialContentService::list($pinterest['board_suggestions'] ?? []),
            ];

            $instagram = (array)($social['instagram'] ?? []);
            $instagramCaption = MockupSocialContentService::text($instagram['caption'] ?? '');
            $instagramCta = MockupSocialContentService::text($instagram['cta'] ?? '');
            $destinations['instagram'][$locale] = [
                'caption' => $instagramCaption,
                'hook' => MockupSocialContentService::text($instagram['hook'] ?? ''),
                'hashtags' => MockupSocialContentService::list($instagram['hashtags'] ?? []),
                'cta' => $instagramCta,
                'composed' => implode("\n\n", array_filter([$instagramCaption, $instagramCta], static fn(string $part): bool => $part !== '')),
            ];

            $facebook = (array)($social['facebook'] ?? []);
            $facebookParts = [
                MockupSocialContentService::text($facebook['post_text'] ?? ''),
                MockupSocialContentService::text($facebook['link_description'] ?? ''),
                MockupSocialContentService::text($facebook['cta'] ?? ''),
            ];
            $destinations['facebook'][$locale] = [
                'headline' => MockupSocialContentService::text($facebook['headline'] ?? ''),
                'post_text' => $facebookParts[0],
                'link_description' => $facebookParts[1],
                'cta' => $facebookParts[2],
                'composed' => implode("\n\n", array_filter($facebookParts, static fn(string $part): bool => $part !== '')),
            ];

            $tiktok = (array)($social['tiktok'] ?? []);
            $tiktokComposed = self::tiktokCaption(
                MockupSocialContentService::text($tiktok['caption_seed'] ?? ''),
                $artworkContent['tags']
            );
            $visualHook = MockupSocialContentService::text($tiktok['visual_hook'] ?? '');
            // El objeto que se publica es la OBRA, no la escena que la rodea:
            // el carrusel abre con la lectura de la obra y la escena del mockup
            // de portada entra despues, como contexto.
            $artworkShort = trim((string)($artworkContent['short_description'] ?? ''));
            $carouselComposed = self::tiktokCaption(
                self::composeBlocks([$artworkShort, MockupSocialContentService::text($tiktok['caption_seed'] ?? '')]),
                $artworkContent['tags'],
                4000
            );
            $destinations['tiktok'][$locale] = [
                'caption' => $tiktokComposed['caption'],
                'caption_seed' => MockupSocialContentService::text($tiktok['caption_seed'] ?? ''),
                'hashtags' => $tiktokComposed['hashtags'],
                'visual_hook' => $visualHook,
                'suggested_motion' => MockupSocialContentService::text($tiktok['suggested_motion'] ?? ''),
                'video_notes' => MockupSocialContentService::text($tiktok['video_notes'] ?? ''),
                // Creator's Draft only carries title and description: the title
                // names the artwork (TikTok caps it at 90) and the description
                // opens on the artwork's own reading.
                'carousel' => [
                    'title' => mb_substr($universalTitle !== '' ? $universalTitle : $visualHook, 0, 90),
                    'description' => $carouselComposed['caption'],
                    'opens_with' => 'artwork',
                ],
            ];
        }

        // Social series (3×3): each post carries the editorial copy of ITS
        // lead image — never the same caption repeated within the channel.
        foreach (self::seriesGroups($mediaItems) as $groupIndex => $group) {
            $lead = $group[0];
            $igPost = ['part' => $groupIndex + 1, 'lead_key' => (string)((int)$lead['mockup_sheet_id']), 'images' => array_values(array_map(static fn(array $i): string => (string)$i['file'], $group))];
            $fbPost = $igPost;
            // Una serie no puede abrir tres veces igual: el post 1 presenta la
            // OBRA y cierra con su escena; los siguientes abren con su propia
            // escena y dejan la obra despues. Informacion completa en los tres,
            // primeras lineas distintas — que es lo unico que se ve en el feed
            // antes del truncado.
            $artworkFirst = $groupIndex === 0;
            $igPost['opens_with'] = $artworkFirst ? 'artwork' : 'scene';
            $fbPost['opens_with'] = $igPost['opens_with'];
            foreach ($locales as $locale) {
                $artworkShort = trim((string)($artworkByLocale[$locale]['short_description'] ?? ''));
                $leadInstagram = (array)($lead['social'][$locale]['instagram'] ?? []);
                $igCaption = MockupSocialContentService::text($leadInstagram['caption'] ?? '', (string)($destinations['instagram'][$locale]['caption'] ?? ''));
                $igCta = MockupSocialContentService::text($leadInstagram['cta'] ?? '', (string)($destinations['instagram'][$locale]['cta'] ?? ''));
                $igPost[$locale] = [
                    'composed' => self::composeBlocks(
                        $artworkFirst ? [$artworkShort, $igCaption, $igCta] : [$igCaption, $artworkShort, $igCta],
                        2200
                    ),
                    'hashtags' => MockupSocialContentService::list($leadInstagram['hashtags'] ?? [], (array)($destinations['instagram'][$locale]['hashtags'] ?? [])),
                    'alts' => array_values(array_map(static fn(array $i): string => (string)($i[$locale]['alt_text'] ?? ''), $group)),
                ];
                $leadFacebook = (array)($lead['social'][$locale]['facebook'] ?? []);
                $fbText = MockupSocialContentService::text($leadFacebook['post_text'] ?? '', (string)($destinations['facebook'][$locale]['post_text'] ?? ''));
                $fbLink = MockupSocialContentService::text($leadFacebook['link_description'] ?? '');
                $fbCta = MockupSocialContentService::text($leadFacebook['cta'] ?? '');
                $fbPost[$locale] = [
                    'headline' => MockupSocialContentService::text($leadFacebook['headline'] ?? '', (string)($destinations['facebook'][$locale]['headline'] ?? '')),
                    'composed' => self::composeBlocks(
                        $artworkFirst ? [$artworkShort, $fbText, $fbLink, $fbCta] : [$fbText, $artworkShort, $fbLink, $fbCta]
                    ),
                    'alts' => array_values(array_map(static fn(array $i): string => (string)($i[$locale]['alt_text'] ?? ''), $group)),
                ];
            }
            $destinations['instagram']['series'][] = $igPost;
            $destinations['facebook']['series'][] = $fbPost;
        }

        return [
            'schema' => self::SCHEMA,
            'locales' => ['working' => $workingLocale, 'adaptations' => $adaptationLocales],
            'artwork' => ['title' => $universalTitle] + $artworkByLocale,
            'media' => [
                'cover_file' => basename((string)($publication['header_file'] ?? '')),
                'items' => $mediaItems,
                'video' => $video,
            ],
            'destinations' => $destinations,
            'sources' => [
                'artwork' => ['entity_id' => $artworkId] + $artworkSources,
                'mockups' => $mockupSources,
                'page' => [
                    'publication_id' => (int)$publication['id'],
                    'slug' => (string)$publication['slug'],
                    'published_at' => (string)($publication['published_at'] ?? ''),
                ],
            ],
            'generated_at' => date('c'),
        ];
    }

    /** assemble() + freeze: one atomic upsert per publication. */
    public function generate(int $publicationId, int $userId): array
    {
        $payload = $this->assemble($publicationId, $userId);
        $fingerprint = self::fingerprint($payload);
        $now = date('c');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->pdo->prepare('SELECT id FROM publication_products WHERE publication_id=? AND user_id=? LIMIT 1');
        $existing->execute([$publicationId, $userId]);
        $id = (int)($existing->fetchColumn() ?: 0);
        if ($id > 0) {
            $this->pdo->prepare("UPDATE publication_products
                SET status='current', payload_json=?, source_fingerprint=?, generated_at=?, updated_at=?
                WHERE id=? AND user_id=?")
                ->execute([$encoded, $fingerprint, $now, $now, $id, $userId]);
        } else {
            $this->pdo->prepare("INSERT INTO publication_products
                (user_id, publication_id, status, payload_json, source_fingerprint, generated_at, created_at, updated_at)
                VALUES (?,?,'current',?,?,?,?,?)")
                ->execute([$userId, $publicationId, $encoded, $fingerprint, $now, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return [
            'id' => $id,
            'publication_id' => $publicationId,
            'status' => 'current',
            'payload' => $payload,
            'source_fingerprint' => $fingerprint,
            'generated_at' => $now,
        ];
    }

    public function find(int $publicationId, int $userId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM publication_products WHERE publication_id=? AND user_id=? LIMIT 1');
            $stmt->execute([$publicationId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row)) return null;
        $payload = json_decode((string)$row['payload_json'], true);
        return [
            'id' => (int)$row['id'],
            'publication_id' => (int)$row['publication_id'],
            'status' => (string)$row['status'],
            'payload' => is_array($payload) ? $payload : [],
            'source_fingerprint' => (string)$row['source_fingerprint'],
            'generated_at' => (string)$row['generated_at'],
        ];
    }

    /** True when the live sources no longer match the frozen projection. */
    public function isStale(array $productRow, int $userId): bool
    {
        try {
            $payload = $this->assemble((int)$productRow['publication_id'], $userId);
        } catch (Throwable) {
            return true;
        }
        return self::fingerprint($payload) !== (string)($productRow['source_fingerprint'] ?? '');
    }

    /** Canonical sha256 over the projected content (never over provenance/timestamps). */
    public static function fingerprint(array $payload): string
    {
        $projected = array_intersect_key($payload, array_flip(['schema', 'locales', 'artwork', 'media', 'destinations']));
        return hash('sha256', self::canonicalize($projected));
    }

    /**
     * Social series criterion (3×3): chunks of 3 images, at most 3 posts.
     * 4-6 items → 2 posts; more than 9 → the extras join the third post
     * (capped at 10, the Instagram carousel limit); anything beyond is left
     * out of the series — the full material already lives on Pinterest.
     *
     * @param list<array> $items @return list<list<array>>
     */
    public static function seriesGroups(array $items): array
    {
        $items = array_values($items);
        if ($items === []) return [];
        $chunks = array_chunk($items, 3);
        if (count($chunks) <= 3) return $chunks;
        $groups = [$chunks[0], $chunks[1], []];
        foreach (array_slice($chunks, 2) as $chunk) {
            foreach ($chunk as $item) {
                if (count($groups[2]) >= 10) break 2;
                $groups[2][] = $item;
            }
        }
        return $groups;
    }

    /**
     * Saatchi keyword compression: ≤12 keywords, each ≤20 chars, natural
     * phrases with spaces preserved, case-insensitive dedupe. Overlong tags
     * are DROPPED whole — truncation would invent text.
     *
     * @return list<string>
     */
    public static function saatchiKeywords(string|array $tags): array
    {
        $keywords = [];
        foreach (MockupSocialContentService::list($tags) as $tag) {
            $tag = trim($tag);
            if ($tag === '' || mb_strlen($tag) > 20) continue;
            $key = mb_strtolower($tag);
            if (isset($keywords[$key])) continue;
            $keywords[$key] = $tag;
            if (count($keywords) === 12) break;
        }
        return array_values($keywords);
    }

    /**
     * TikTok caption = caption_seed + blank line + hashtags built from the
     * artwork tags of the SAME locale. The 2200 cap drops whole trailing
     * hashtags, never cutting words.
     *
     * @return array{caption:string,hashtags:list<string>}
     */
    /**
     * Joins the blocks of a post in the order the caller decided, dropping what
     * is empty and what repeats verbatim — the artwork reading and the scene
     * copy sometimes carry the same sentence, and printing it twice inside one
     * post is exactly the repetition this composition exists to avoid.
     *
     * @param list<string> $blocks
     */
    private static function composeBlocks(array $blocks, int $limit = 0): string
    {
        $clean = [];
        foreach ($blocks as $block) {
            $block = trim((string)$block);
            if ($block === '' || in_array($block, $clean, true)) continue;
            $clean[] = $block;
        }
        $composed = implode("\n\n", $clean);
        return $limit > 0 && mb_strlen($composed) > $limit ? rtrim(mb_substr($composed, 0, $limit)) : $composed;
    }

    public static function tiktokCaption(string $captionSeed, string|array $tags, int $limit = 2200): array
    {
        $seed = trim($captionSeed);
        // A seed that already carries its own hashtags is complete: appending a
        // second hashtag line would duplicate material inside the same post.
        if (preg_match('/#[\p{L}\p{N}_]/u', $seed) === 1) {
            return ['caption' => mb_strlen($seed) > $limit ? mb_substr($seed, 0, $limit) : $seed, 'hashtags' => []];
        }
        $hashtags = [];
        foreach (MockupSocialContentService::list($tags) as $tag) {
            $clean = preg_replace('/[^\pL\pN]+/u', '', $tag);
            if (!is_string($clean) || $clean === '') continue;
            $hashtags[] = '#' . $clean;
            if (count($hashtags) === 5) break;
        }
        $compose = static fn(array $tail): string => trim($seed . ($tail !== [] ? "\n\n" . implode(' ', $tail) : ''));
        $caption = $compose($hashtags);
        while (mb_strlen($caption) > $limit && $hashtags !== []) {
            array_pop($hashtags);
            $caption = $compose($hashtags);
        }
        if (mb_strlen($caption) > $limit) {
            $caption = mb_substr($caption, 0, $limit);
        }
        return ['caption' => $caption, 'hashtags' => $hashtags];
    }

    // ————— private —————

    /** @return array{artwork:array<string,array>,mockup:array<int,array<string,array>>} rows keyed by locale */
    private function editorialRows(int $userId, int $artworkId, array $mockupIds, array $locales): array
    {
        $result = ['artwork' => [], 'mockup' => []];
        if ($locales === []) return $result;
        $localeMarks = implode(',', array_fill(0, count($locales), '?'));
        $conditions = "(entity_type='artwork' AND entity_id=?)";
        $params = array_merge([$userId], $locales, [$artworkId]);
        if ($mockupIds !== []) {
            $mockupMarks = implode(',', array_fill(0, count($mockupIds), '?'));
            $conditions .= " OR (entity_type='mockup' AND entity_id IN ({$mockupMarks}))";
            $params = array_merge($params, $mockupIds);
        }
        $stmt = $this->pdo->prepare("SELECT entity_type, entity_id, locale, content_json, status, is_published, published_content_json
            FROM bilingual_editorial_content
            WHERE user_id=? AND locale IN ({$localeMarks}) AND ({$conditions})");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['entity_type'] === 'artwork') {
                $result['artwork'][(string)$row['locale']] = $row;
            } else {
                $result['mockup'][(int)$row['entity_id']][(string)$row['locale']] = $row;
            }
        }
        return $result;
    }

    /**
     * Locale read rule (mirror of the public site's AppPublishedLocalization):
     * working locale → published snapshot when published; adaptation → draft.
     *
     * @return array{0:array,1:string} [content, status]
     */
    private function localeContent(?array $row, bool $isWorkingLocale): array
    {
        if (!is_array($row)) return [[], 'missing'];
        $draft = json_decode((string)($row['content_json'] ?? ''), true);
        $draft = is_array($draft) ? $draft : [];
        if ($isWorkingLocale) {
            if ((int)($row['is_published'] ?? 0) === 1) {
                $published = json_decode((string)($row['published_content_json'] ?? ''), true);
                return [is_array($published) ? $published : [], 'published'];
            }
            return [$draft, $draft === [] ? 'missing' : 'draft'];
        }
        if ($draft === []) return [[], 'missing'];
        $status = (string)($row['status'] ?? '');
        return [$draft, in_array($status, ['current', 'stale'], true) ? $status : 'current'];
    }

    /** @return array{subtitle:string,description:string,short_description:string,tags:string,search_terms:string,seo_title:string,seo_description:string,alt_text:string,caption:string} */
    private function artworkFields(array $content): array
    {
        $fields = [];
        foreach (['subtitle', 'description', 'short_description', 'tags', 'search_terms', 'seo_title', 'seo_description', 'alt_text', 'caption'] as $field) {
            $value = $content[$field] ?? '';
            $fields[$field] = is_array($value)
                ? implode(', ', MockupSocialContentService::list($value))
                : trim((string)$value);
        }
        return $fields;
    }

    /** @return array<string,array> only the channels the product consumes */
    private function socialChannels(array $content): array
    {
        $social = (array)($content['social'] ?? []);
        $channels = [];
        foreach (['pinterest', 'instagram', 'facebook', 'tiktok'] as $channel) {
            $channels[$channel] = is_array($social[$channel] ?? null) ? $social[$channel] : [];
        }
        return $channels;
    }

    private function resolveMockupId(array $item, int $userId): int
    {
        $sheetStmt = $this->pdo->prepare('SELECT COALESCE(mockup_id,0), mockup_file FROM mockup_sheets WHERE id=? AND user_id=? LIMIT 1');
        $sheetStmt->execute([(int)$item['mockup_sheet_id'], $userId]);
        $sheetRow = $sheetStmt->fetch(PDO::FETCH_NUM);
        if (!is_array($sheetRow)) return 0;
        $mockupId = (int)$sheetRow[0];
        if ($mockupId > 0) return $mockupId;
        $file = basename((string)($sheetRow[1] ?? ($item['mockup_file'] ?? '')));
        if ($file === '') return 0;
        $stmt = $this->pdo->prepare('SELECT id FROM mockups WHERE user_id=? AND mockup_file=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $file]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function videoBlock(int $userId, int $artworkId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT video_export_id FROM artwork_video_publications WHERE user_id=? AND artwork_id=? LIMIT 1');
            $stmt->execute([$userId, $artworkId]);
            $exportId = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return null;
        }
        return $exportId > 0 ? ['export_id' => $exportId] : null;
    }

    /** @param array<string,array> $artworkByLocale */
    private function saatchiBlock(array $artworkByLocale, array $mediaItems, string $workingLocale, array $adaptationLocales): array
    {
        $keywordLocale = $adaptationLocales[0] ?? $workingLocale;
        $keywords = self::saatchiKeywords((string)($artworkByLocale[$keywordLocale]['tags'] ?? ''));
        if ($keywords === [] && $keywordLocale !== $workingLocale) {
            $keywords = self::saatchiKeywords((string)($artworkByLocale[$workingLocale]['tags'] ?? ''));
            $keywordLocale = $workingLocale;
        }
        $description = [];
        foreach ($artworkByLocale as $locale => $fields) {
            $description[$locale] = (string)($fields['description'] ?? '');
        }
        $images = [];
        foreach ($mediaItems as $item) {
            $captions = [];
            foreach ($artworkByLocale as $locale => $unused) {
                $captions[$locale] = (string)($item[$locale]['caption'] ?? '');
            }
            $images[] = [
                'file' => (string)$item['file'],
                'position' => (int)$item['position'],
                'caption' => $captions,
            ];
        }
        return [
            'keywords' => $keywords,
            'keywords_locale' => $keywordLocale,
            'description' => $description,
            'images' => $images,
        ];
    }

    private static function canonicalize(mixed $value): string
    {
        return json_encode(self::sortKeysDeep($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function sortKeysDeep(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $isList = array_is_list($value);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortKeysDeep($item);
        }
        if (!$isList) ksort($sorted);
        return $sorted;
    }
}

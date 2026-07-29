<?php
declare(strict_types=1);

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports the explicit studio-note/v1 interchange contract.
 *
 * This path is deliberately deterministic: it validates and stores supplied
 * bilingual editorial material but never creates an AI generation job.
 */
final class StudioNoteMarkdownImportService
{
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;
    private const MAX_ZIP_BYTES = 50 * 1024 * 1024;
    private const MAX_ZIP_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
    private const MAX_ZIP_ENTRIES = 100;
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;
    private const SCHEMA = 'studio-note/v1';
    private const LOCALES = ['es', 'en'];
    private const SEO_FIELDS = [
        'title',
        'excerpt',
        'slug',
        'seo_title',
        'meta_description',
        'tags',
        'search_terms',
        'cover_alt',
        'cover_caption',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,title:string,no_ai:bool} */
    public function importUpload(int $userId, array $upload): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Elegí un archivo .zip para importar.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo cargar el paquete ZIP.');
        }

        $name = trim((string)($upload['name'] ?? ''));
        $temporaryFile = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Studio Notes importa un paquete con extensión .zip.');
        }
        if ($temporaryFile === ''
            || (!is_uploaded_file($temporaryFile) && PHP_SAPI !== 'cli')
            || $size <= 0
            || $size > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('El paquete ZIP debe ser válido y no superar 50 MB.');
        }

        [$markdown, $markdownName, $assets] = $this->readZipPackage($temporaryFile);
        return $this->importString($userId, $markdown, $markdownName, $assets, $name);
    }

    /** @return array{id:int,title:string,no_ai:bool} */
    public function importString(
        int $userId,
        string $markdown,
        string $originalName = 'studio-note.md',
        array $assets = [],
        string $packageName = ''
    ): array
    {
        if ($userId <= 0) throw new InvalidArgumentException('Usuario inválido.');
        if (strlen($markdown) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('El archivo .md no puede superar 2 MB.');
        }

        $package = $this->parse($markdown);
        $board = new WebsiteBoardService($this->pdo);
        $sources = $board->sources($userId);
        $resolvedImages = $this->resolveImageInputs(
            $userId,
            (array)$package['manifest']['images'],
            $sources,
            $assets
        );
        $this->validateRelationships($userId, (array)$package['manifest']);

        $now = date(DATE_ATOM);
        $createdAt = (string)$package['manifest']['created_at'];

        $this->pdo->beginTransaction();
        try {
            $spanishMetadata = (array)$package['manifest']['es'];
            $englishMetadata = (array)$package['manifest']['en'];
            $stmt = $this->pdo->prepare('INSERT INTO social_campaigns
                (user_id,campaign_type,title,objective,source_type,source_id,source_label,status,payload_json,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $userId,
                'website_blog',
                (string)$englishMetadata['title'],
                '',
                'custom',
                '',
                'Imported ZIP',
                'draft',
                '{}',
                $createdAt,
                $now,
            ]);
            $noteId = (int)$this->pdo->lastInsertId();
            if ($noteId <= 0) throw new RuntimeException('No se pudo crear el borrador importado.');

            $resolvedImages = $this->persistPackageImages($userId, $noteId, $resolvedImages);
            $coverKey = (string)$package['manifest']['cover_image'];
            $coverSource = $coverKey !== ''
                ? (array)($resolvedImages[$coverKey]['source'] ?? [])
                : [];
            $media = [];
            foreach ($resolvedImages as $image) {
                $source = (array)$image['source'];
                $media[(string)$source['key']] = $source;
            }
            $media = array_values($media);
            $payload = [
                'channels' => ['website_blog'],
                'destinations' => ['website_blog'],
                'editorial_sync_key' => 'studio-note-' . bin2hex(random_bytes(16)),
                'mockup_ids' => array_values(array_map(
                    static fn(array $source): int => (int)$source['id'],
                    array_filter(
                        $media,
                        static fn(array $source): bool => (string)$source['type'] === 'mockup'
                    )
                )),
                'media' => $media,
                'channel_status' => ['website_blog' => 'draft'],
                'markdown_import' => [
                    'schema' => self::SCHEMA,
                    'filename' => basename($originalName),
                    'package' => $packageName !== '' ? basename($packageName) : null,
                    'sha256' => hash('sha256', $markdown),
                    'imported_at' => $now,
                    'created_at' => $createdAt,
                    'series' => (array)$package['manifest']['series'],
                    'artworks' => (array)$package['manifest']['artworks'],
                    'mockups' => (array)$package['manifest']['mockups'],
                ],
            ];
            if ($coverSource !== []) $payload['source'] = $coverSource;
            $updateCampaign = $this->pdo->prepare(
                'UPDATE social_campaigns
                 SET source_type=?,source_id=?,source_label=?,payload_json=?,updated_at=?
                 WHERE id=? AND user_id=?'
            );
            $updateCampaign->execute([
                (string)($coverSource['type'] ?? 'custom'),
                (string)($coverSource['id'] ?? ''),
                (string)($coverSource['label'] ?? 'Imported ZIP'),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $now,
                $noteId,
                $userId,
            ]);

            $spanish = $this->localizedContent(
                $spanishMetadata,
                $this->renderBody((string)$package['spanish_markdown'], $resolvedImages, 'es', $noteId),
                $resolvedImages,
                'es',
                (string)$package['source_hash']
            );
            $english = $this->localizedContent(
                $englishMetadata,
                $this->renderBody((string)$package['english_markdown'], $resolvedImages, 'en', $noteId),
                $resolvedImages,
                'en',
                (string)$package['source_hash']
            );

            $editorial = new BilingualEditorialService($this->pdo);
            $editorial->save($userId, 'studio_note', $noteId, 'es', $spanish);
            $editorial->save($userId, 'studio_note', $noteId, 'en', $english);
            $board->saveNote(
                $userId,
                $noteId,
                (string)$english['title'],
                (string)$english['body_html'],
                (string)$spanish['body_html']
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return [
            'id' => $noteId,
            'title' => (string)$spanish['title'],
            'no_ai' => true,
        ];
    }

    /**
     * @return array{0:string,1:string,2:array<string,array{bytes:string,mime:string}>}
     */
    private function readZipPackage(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la lectura segura de ZIP.');
        }
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::RDONLY);
        if ($opened !== true) throw new RuntimeException('El paquete ZIP está dañado o no puede abrirse.');
        try {
            if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new RuntimeException('El ZIP debe contener entre 1 y 100 archivos.');
            }
            $markdown = null;
            $markdownName = '';
            $assets = [];
            $uncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) throw new RuntimeException('No se pudo validar una entrada del ZIP.');
                $entry = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($entry === '' || str_ends_with($entry, '/')) continue;
                if ($this->unsafeArchivePath($entry)) {
                    throw new RuntimeException('El ZIP contiene una ruta no permitida.');
                }
                $uncompressed += max(0, (int)($stat['size'] ?? 0));
                if ($uncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('El contenido descomprimido del ZIP supera 100 MB.');
                }
                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $operations = 0;
                    $attributes = 0;
                    if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
                        && (($attributes >> 16) & 0170000) === 0120000) {
                        throw new RuntimeException('El ZIP no puede contener enlaces simbólicos.');
                    }
                }
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if ($extension === 'md') {
                    if ($markdown !== null) {
                        throw new RuntimeException('El ZIP debe contener exactamente un archivo .md.');
                    }
                    if ((int)$stat['size'] <= 0 || (int)$stat['size'] > self::MAX_FILE_BYTES) {
                        throw new RuntimeException('El archivo .md del ZIP no puede superar 2 MB.');
                    }
                    $contents = $zip->getFromIndex($index);
                    if (!is_string($contents)) throw new RuntimeException('No se pudo leer el archivo .md del ZIP.');
                    $markdown = $contents;
                    $markdownName = basename($entry);
                    continue;
                }
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    throw new RuntimeException('El ZIP solo puede contener un .md e imágenes JPEG, PNG o WebP.');
                }
                if ((int)$stat['size'] <= 0 || (int)$stat['size'] > self::MAX_IMAGE_BYTES) {
                    throw new RuntimeException('Cada imagen del ZIP debe pesar como máximo 12 MB.');
                }
                $basename = basename($entry);
                $assetKey = strtolower($basename);
                if (isset($assets[$assetKey])) {
                    throw new RuntimeException("El ZIP contiene dos imágenes llamadas {$basename}.");
                }
                $bytes = $zip->getFromIndex($index);
                if (!is_string($bytes) || $bytes === '') {
                    throw new RuntimeException("No se pudo leer {$basename} dentro del ZIP.");
                }
                $mime = match ($extension) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    default => 'image/webp',
                };
                $assets[$assetKey] = ['bytes' => $bytes, 'mime' => $mime];
            }
            if (!is_string($markdown)) {
                throw new RuntimeException('El ZIP debe contener exactamente un archivo .md.');
            }
            return [$markdown, $markdownName, $assets];
        } finally {
            $zip->close();
        }
    }

    private function unsafeArchivePath(string $entry): bool
    {
        if (str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:\//', $entry) === 1) return true;
        foreach (explode('/', $entry) as $segment) {
            if ($segment === '..' || $segment === '') return true;
        }
        return str_contains($entry, "\0");
    }

    /**
     * @return array{
     *   manifest:array<string,mixed>,spanish_markdown:string,
     *   english_markdown:string,source_hash:string
     * }
     */
    public function parse(string $markdown): array
    {
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? $markdown;
        // Los .md llegan escritos en cualquier sistema; CRLF/CR se normalizan antes de parsear.
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if ($markdown === '' || str_contains($markdown, "\0")) {
            throw new RuntimeException('El archivo Markdown está vacío o dañado.');
        }
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/su', $markdown, $match) !== 1) {
            throw new RuntimeException('El archivo debe comenzar con una cabecera YAML delimitada por ---.');
        }

        $yaml = (string)$match[1];
        $body = (string)$match[2];
        if (preg_match('/(^|\s)[&*][A-Za-z0-9_-]+/m', $yaml) === 1) {
            throw new RuntimeException('La cabecera no admite alias ni referencias YAML.');
        }
        try {
            $manifest = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $error) {
            throw new RuntimeException('La cabecera YAML no es válida: ' . $error->getMessage());
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('La cabecera YAML debe contener el contrato studio-note/v1.');
        }

        $spanishMarker = '<!-- STUDIO_NOTE:ES -->';
        $englishMarker = '<!-- STUDIO_NOTE:EN -->';
        if (substr_count($body, $spanishMarker) !== 1 || substr_count($body, $englishMarker) !== 1) {
            throw new RuntimeException('El archivo necesita exactamente un bloque español y uno inglés.');
        }
        $spanishStart = strpos($body, $spanishMarker);
        $englishStart = strpos($body, $englishMarker);
        if ($spanishStart === false || $englishStart === false || $englishStart <= $spanishStart) {
            throw new RuntimeException('El bloque español debe aparecer antes del bloque inglés.');
        }
        $spanishMarkdown = trim(substr(
            $body,
            $spanishStart + strlen($spanishMarker),
            $englishStart - ($spanishStart + strlen($spanishMarker))
        ));
        $englishMarkdown = trim(substr($body, $englishStart + strlen($englishMarker)));
        if ($spanishMarkdown === '' || $englishMarkdown === '') {
            throw new RuntimeException('Los cuerpos español e inglés deben contener texto.');
        }
        if (preg_match('/!\[[^\]]*\]\([^)]+\)/u', $spanishMarkdown . "\n" . $englishMarkdown) === 1) {
            throw new RuntimeException('Usá tokens {{image:clave}}; las imágenes Markdown tradicionales no se importan.');
        }

        $normalizedManifest = $this->validateManifest($manifest);
        $this->validateImageTokens(
            $spanishMarkdown,
            $englishMarkdown,
            (array)$normalizedManifest['images']
        );

        return [
            'manifest' => $normalizedManifest,
            'spanish_markdown' => $spanishMarkdown,
            'english_markdown' => $englishMarkdown,
            'source_hash' => hash('sha256', $markdown),
        ];
    }

    /** @return array<string,mixed> */
    private function validateManifest(array $manifest): array
    {
        if (trim((string)($manifest['schema'] ?? '')) !== self::SCHEMA) {
            throw new RuntimeException('La cabecera debe declarar schema: "studio-note/v1".');
        }
        if (trim((string)($manifest['content_type'] ?? '')) !== 'essay') {
            throw new RuntimeException('content_type debe ser "essay".');
        }
        if (trim((string)($manifest['source_language'] ?? '')) !== 'es') {
            throw new RuntimeException('source_language debe ser "es".');
        }
        if (trim((string)($manifest['status'] ?? '')) !== 'draft') {
            throw new RuntimeException('Por seguridad, el estado importado debe ser "draft".');
        }

        $dateValue = trim((string)($manifest['created_at'] ?? ''));
        if ($dateValue === '') {
            $createdAt = new DateTimeImmutable('now');
        } else {
            try {
                $createdAt = new DateTimeImmutable($dateValue);
            } catch (Throwable) {
                throw new RuntimeException('created_at debe contener una fecha ISO 8601 válida.');
            }
        }

        $localized = [];
        foreach (self::LOCALES as $locale) {
            $content = $manifest[$locale] ?? null;
            if (!is_array($content)) {
                throw new RuntimeException("Falta el bloque de metadata {$locale}.");
            }
            foreach (self::SEO_FIELDS as $field) {
                if (!array_key_exists($field, $content)) {
                    throw new RuntimeException("Falta {$locale}.{$field}.");
                }
            }
            $localized[$locale] = [
                'title' => $this->requiredText($content['title'], "{$locale}.title"),
                'excerpt' => $this->requiredText($content['excerpt'], "{$locale}.excerpt"),
                'slug' => $this->validatedSlug($content['slug'], "{$locale}.slug"),
                'seo_title' => $this->requiredText($content['seo_title'], "{$locale}.seo_title"),
                'meta_description' => $this->requiredText($content['meta_description'], "{$locale}.meta_description"),
                'tags' => $this->requiredList($content['tags'], "{$locale}.tags"),
                'search_terms' => $this->requiredList($content['search_terms'], "{$locale}.search_terms"),
                'cover_alt' => $this->requiredText($content['cover_alt'], "{$locale}.cover_alt"),
                'cover_caption' => $this->requiredText($content['cover_caption'], "{$locale}.cover_caption"),
            ];
        }
        if ($localized['es']['slug'] === $localized['en']['slug']) {
            throw new RuntimeException('Los slugs español e inglés deben ser diferentes.');
        }

        $images = $manifest['images'] ?? [];
        if (!is_array($images)) throw new RuntimeException('images debe ser una lista.');
        $normalizedImages = [];
        $keys = [];
        foreach ($images as $index => $image) {
            if (!is_array($image)) throw new RuntimeException('Cada entrada de images debe ser un objeto.');
            $key = trim((string)($image['key'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $key) !== 1) {
                throw new RuntimeException('Cada imagen necesita una key simple y estable.');
            }
            if (isset($keys[$key])) throw new RuntimeException("La imagen {$key} está repetida.");
            $keys[$key] = true;
            $mockupId = max(0, (int)($image['mockup_id'] ?? 0));
            $file = basename(trim((string)($image['file'] ?? '')));
            if ($mockupId <= 0 && $file === '') {
                throw new RuntimeException("La imagen {$key} necesita mockup_id o file.");
            }
            $role = strtolower(trim((string)($image['role'] ?? 'inline')));
            $size = strtolower(trim((string)($image['size'] ?? 'medium')));
            $align = strtolower(trim((string)($image['align'] ?? 'left')));
            if (!in_array($role, ['cover', 'inline'], true)) {
                throw new RuntimeException("El role de {$key} debe ser cover o inline.");
            }
            if (!in_array($size, ['small', 'medium', 'large'], true)) {
                throw new RuntimeException("El size de {$key} debe ser small, medium o large.");
            }
            if (!in_array($align, ['left', 'center', 'right'], true)) {
                throw new RuntimeException("El align de {$key} debe ser left, center o right.");
            }
            $metadata = [];
            foreach (self::LOCALES as $locale) {
                $imageLocale = $image[$locale] ?? null;
                if (!is_array($imageLocale)) {
                    throw new RuntimeException("Falta images.{$key}.{$locale}.");
                }
                $metadata[$locale] = [
                    'alt_text' => $this->requiredText(
                        $imageLocale['alt_text'] ?? '',
                        "images.{$key}.{$locale}.alt_text"
                    ),
                    'caption' => $this->requiredText(
                        $imageLocale['caption'] ?? '',
                        "images.{$key}.{$locale}.caption"
                    ),
                ];
            }
            $normalizedImages[] = [
                'key' => $key,
                'mockup_id' => $mockupId,
                'file' => $file,
                'role' => $role,
                'size' => $size,
                'align' => $align,
                'es' => $metadata['es'],
                'en' => $metadata['en'],
            ];
        }

        $coverImage = trim((string)($manifest['cover_image'] ?? ''));
        if ($normalizedImages === [] && $coverImage !== '') {
            throw new RuntimeException('cover_image debe estar vacío cuando no hay imágenes.');
        }
        if ($normalizedImages !== []) {
            if ($coverImage === '' || !isset($keys[$coverImage])) {
                throw new RuntimeException('cover_image debe coincidir con una key declarada en images.');
            }
            $coverCount = 0;
            foreach ($normalizedImages as $image) {
                if ($image['role'] === 'cover') $coverCount++;
                if ($image['key'] === $coverImage && $image['role'] !== 'cover') {
                    throw new RuntimeException('La imagen seleccionada como portada debe tener role: "cover".');
                }
            }
            if ($coverCount !== 1) {
                throw new RuntimeException('Debe existir exactamente una imagen con role: "cover".');
            }
        }

        return [
            'schema' => self::SCHEMA,
            'content_type' => 'essay',
            'source_language' => 'es',
            'created_at' => $createdAt->format(DATE_ATOM),
            'status' => 'draft',
            'cover_image' => $coverImage,
            'series' => $this->normalizedReferences($manifest['series'] ?? [], 'series'),
            'artworks' => $this->normalizedReferences($manifest['artworks'] ?? [], 'artworks'),
            'mockups' => $this->normalizedReferences($manifest['mockups'] ?? [], 'mockups', false),
            'es' => $localized['es'],
            'en' => $localized['en'],
            'images' => $normalizedImages,
        ];
    }

    /** @return list<array{id:int,title?:string,file?:string}> */
    private function normalizedReferences(mixed $references, string $field, bool $withTitle = true): array
    {
        if ($references === null) return [];
        if (!is_array($references)) throw new RuntimeException("{$field} debe ser una lista.");
        $normalized = [];
        foreach ($references as $reference) {
            if (is_scalar($reference)) {
                $label = trim((string)$reference);
                if ($label === '') continue;
                $reference = $withTitle ? ['title' => $label] : ['file' => $label];
            }
            if (!is_array($reference)) {
                throw new RuntimeException("Cada relación de {$field} debe indicar un nombre, archivo o ID.");
            }
            $id = max(0, (int)($reference['id'] ?? 0));
            $row = ['id' => $id];
            if ($withTitle) {
                $title = trim((string)($reference['title'] ?? $reference['name'] ?? ''));
                if ($id <= 0 && $title === '') {
                    throw new RuntimeException("Cada relación de {$field} necesita un nombre o un ID.");
                }
                if ($title !== '') $row['title'] = $title;
            } else {
                $file = basename(trim((string)($reference['file'] ?? $reference['filename'] ?? '')));
                $title = trim((string)($reference['title'] ?? $reference['name'] ?? ''));
                if ($id <= 0 && $file === '' && $title === '') {
                    throw new RuntimeException('Cada relación de mockups necesita un archivo, nombre o ID.');
                }
                if ($file !== '') $row['file'] = $file;
                if ($title !== '') $row['title'] = $title;
            }
            $key = $id > 0
                ? 'id:' . $id
                : strtolower((string)($row['title'] ?? $row['file'] ?? count($normalized)));
            $normalized[$key] = $row;
        }
        return array_values($normalized);
    }

    private function validateImageTokens(string $spanish, string $english, array $images): void
    {
        $spanishTokens = $this->imageTokens($spanish);
        $englishTokens = $this->imageTokens($english);
        if ($spanishTokens !== $englishTokens) {
            throw new RuntimeException('Los tokens de imagen deben aparecer en el mismo orden en español e inglés.');
        }
        $declared = array_column($images, 'key');
        if ($spanishTokens !== $declared) {
            throw new RuntimeException('Cada imagen declarada debe aparecer una vez y en el mismo orden en ambos textos.');
        }
    }

    /** @return list<string> */
    private function imageTokens(string $markdown): array
    {
        preg_match_all('/\{\{image:([A-Za-z0-9][A-Za-z0-9._-]{0,79})\}\}/u', $markdown, $matches);
        $tokens = array_values(array_map('strval', (array)($matches[1] ?? [])));
        if (substr_count($markdown, '{{image:') !== count($tokens)) {
            throw new RuntimeException('Existe un token de imagen con formato inválido.');
        }
        if (count($tokens) !== count(array_unique($tokens))) {
            throw new RuntimeException('Una misma imagen no puede insertarse dos veces en la nota importada.');
        }
        return $tokens;
    }

    /**
     * @param list<array<string,mixed>> $images
     * @param list<array<string,mixed>> $sources
     * @return array<string,array{definition:array<string,mixed>,source:array<string,mixed>}>
     */
    private function resolveImageInputs(int $userId, array $images, array $sources, array $assets): array
    {
        $byId = [];
        $byFile = [];
        foreach ($sources as $source) {
            if ((string)($source['type'] ?? '') !== 'mockup') continue;
            $id = (int)($source['id'] ?? 0);
            $file = basename((string)($source['file'] ?? ''));
            if ($id > 0) $byId[$id] = $source;
            if ($file !== '') $byFile[$file] = $source;
        }

        $resolved = [];
        foreach ($images as $image) {
            $key = (string)$image['key'];
            $mockupId = (int)$image['mockup_id'];
            $file = (string)$image['file'];
            $source = $mockupId > 0
                ? ($byId[$mockupId] ?? null)
                : ($byFile[$file] ?? null);
            if (!is_array($source)) {
                $asset = $file !== '' ? ($assets[strtolower($file)] ?? null) : null;
                if (!is_array($asset) || !is_string($asset['bytes'] ?? null)) {
                    throw new RuntimeException("La imagen {$key} no corresponde a un mockup ni a un archivo del ZIP.");
                }
                $resolved[$key] = [
                    'definition' => $image,
                    'asset' => [
                        'bytes' => (string)$asset['bytes'],
                        'mime' => (string)($asset['mime'] ?? ''),
                    ],
                ];
                continue;
            }
            $sourceFile = basename((string)$source['file']);
            if ($file !== '' && $file !== $sourceFile) {
                throw new RuntimeException("El archivo declarado para {$key} no coincide con el mockup.");
            }
            $image['file'] = $sourceFile;
            $resolved[$key] = ['definition' => $image, 'source' => $source];
        }
        return $resolved;
    }

    /** @return array<string,array<string,mixed>> */
    private function persistPackageImages(int $userId, int $noteId, array $images): array
    {
        foreach ($images as $key => $image) {
            if (isset($image['source']) && is_array($image['source'])) continue;
            $asset = (array)($image['asset'] ?? []);
            $source = StudioNoteMediaService::persistImageBytes(
                $userId,
                $noteId,
                (string)($asset['bytes'] ?? ''),
                (string)($asset['mime'] ?? '')
            );
            $definition = (array)$image['definition'];
            $definition['file'] = (string)$source['file'];
            $images[$key] = ['definition' => $definition, 'source' => $source];
        }
        return $images;
    }

    private function validateRelationships(int $userId, array $manifest): void
    {
        $tables = [
            'series' => 'artwork_series',
            'artworks' => 'artworks',
            'mockups' => 'mockups',
        ];
        foreach ($tables as $field => $table) {
            foreach ((array)($manifest[$field] ?? []) as $reference) {
                $id = (int)($reference['id'] ?? 0);
                if ($id <= 0) continue;
                $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND user_id=? LIMIT 1");
                $stmt->execute([$id, $userId]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException("La relación {$field} #{$id} no pertenece a esta cuenta.");
                }
            }
        }
    }

    /** @param array<string,array{definition:array<string,mixed>,source:array<string,mixed>}> $images */
    private function renderBody(string $markdown, array $images, string $locale, int $noteId): string
    {
        $slots = [];
        foreach ($images as $key => $image) {
            $slot = 'STUDIO_NOTE_MD_IMAGE_' . count($slots) . '_SLOT';
            $slots[$slot] = $this->imageTag(
                (array)$image['definition'],
                $locale,
                $noteId
            );
            $markdown = str_replace('{{image:' . $key . '}}', $slot, $markdown);
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $converter = new MarkdownConverter($environment);
        $html = (string)$converter->convert($markdown);
        $html = preg_replace('/<h1\b[^>]*>/iu', '<h2>', $html) ?? $html;
        $html = str_ireplace('</h1>', '</h2>', $html);
        $html = preg_replace('/<h[4-6]\b[^>]*>/iu', '<h3>', $html) ?? $html;
        $html = preg_replace('/<\/h[4-6]>/iu', '</h3>', $html) ?? $html;
        foreach ($slots as $slot => $imageTag) {
            $html = str_replace($slot, $imageTag, $html);
        }
        if (str_contains($html, 'STUDIO_NOTE_MD_IMAGE_')) {
            throw new RuntimeException('No se pudieron colocar todas las imágenes del Markdown.');
        }
        return trim($html);
    }

    private function imageTag(array $image, string $locale, int $noteId): string
    {
        $metadata = (array)$image[$locale];
        return '<img src="'
            . htmlspecialchars(
                StudioNoteMediaService::deliveryUrl($noteId, (string)$image['file'], 1200),
                ENT_QUOTES,
                'UTF-8'
            )
            . '" alt="' . htmlspecialchars((string)$metadata['alt_text'], ENT_QUOTES, 'UTF-8')
            . '" data-editor-size="' . htmlspecialchars((string)$image['size'], ENT_QUOTES, 'UTF-8')
            . '" data-editor-align="' . htmlspecialchars((string)$image['align'], ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /** @return array<string,mixed> */
    private function localizedContent(
        array $metadata,
        string $bodyHtml,
        array $images,
        string $locale,
        string $sourceHash
    ): array {
        $imageMetadata = [];
        foreach ($images as $image) {
            $definition = (array)$image['definition'];
            $localized = (array)$definition[$locale];
            $imageMetadata[] = [
                'file' => (string)$definition['file'],
                'alt_text' => (string)$localized['alt_text'],
                'caption' => (string)$localized['caption'],
            ];
        }
        return [
            'title' => (string)$metadata['title'],
            'excerpt' => (string)$metadata['excerpt'],
            'body_html' => $bodyHtml,
            'slug' => (string)$metadata['slug'],
            'seo_title' => (string)$metadata['seo_title'],
            'seo_description' => (string)$metadata['meta_description'],
            'alt_text' => (string)$metadata['cover_alt'],
            'caption' => (string)$metadata['cover_caption'],
            'tags' => implode(', ', (array)$metadata['tags']),
            'search_terms' => implode(', ', (array)$metadata['search_terms']),
            'image_metadata' => $imageMetadata,
            'meta_source_hash' => $sourceHash,
        ];
    }

    private function requiredText(mixed $value, string $path): string
    {
        if (!is_scalar($value)) throw new RuntimeException("{$path} debe ser texto.");
        $value = trim((string)$value);
        if ($value === '') throw new RuntimeException("{$path} no puede estar vacío.");
        return $value;
    }

    /** @return list<string> */
    private function requiredList(mixed $value, string $path): array
    {
        if (!is_array($value)) throw new RuntimeException("{$path} debe ser una lista.");
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value
        ))));
        if ($normalized === []) throw new RuntimeException("{$path} no puede estar vacío.");
        return $normalized;
    }

    private function validatedSlug(mixed $value, string $path): string
    {
        $slug = $this->requiredText($value, $path);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new RuntimeException("{$path} debe usar minúsculas, números y guiones, sin acentos.");
        }
        return $slug;
    }
}

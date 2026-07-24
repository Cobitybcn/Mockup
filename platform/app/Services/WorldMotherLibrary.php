<?php
declare(strict_types=1);

final class WorldMotherLibrary
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const RESERVED_DIRECTORIES = ['thumbnails'];

    private string $basePath;
    private string $baseRelativePath;
    private bool $createdBaseDirectory = false;
    private array $indexData = [];
    private bool $indexLoaded = false;

    public function __construct(?string $basePath = null, string $baseRelativePath = 'storage/world_mothers')
    {
        $this->baseRelativePath = trim(str_replace('\\', '/', $baseRelativePath), '/');
        $this->basePath = $basePath !== null
            ? rtrim($basePath, DIRECTORY_SEPARATOR . '/\\')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->baseRelativePath);

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
            $this->createdBaseDirectory = true;
        }
    }

    private function loadIndex(): void
    {
        if ($this->indexLoaded) {
            return;
        }
        $indexPath = $this->basePath . DIRECTORY_SEPARATOR . 'index.json';
        $localData = is_file($indexPath)
            ? json_decode((string)file_get_contents($indexPath), true)
            : [];
        $localData = is_array($localData) ? $localData : [];
        $remoteData = [];
        if (StorageService::isGcsActive()) {
            $remoteIndexPath = $indexPath . '.remote';
            if (StorageService::downloadFile($this->baseRelativePath . '/index.json', $remoteIndexPath)) {
                $decodedRemote = json_decode((string)file_get_contents($remoteIndexPath), true);
                $remoteData = is_array($decodedRemote) ? $decodedRemote : [];
                @unlink($remoteIndexPath);
            }
        }

        $this->indexData = self::preferredIndexData(
            $localData,
            $remoteData,
            StorageService::isGcsActive()
        );
        if (self::isValidIndexData($remoteData)) {
            $encodedRemote = json_encode($remoteData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encodedRemote)) {
                @file_put_contents($indexPath, $encodedRemote, LOCK_EX);
            }
        }
        $this->indexLoaded = true;
    }

    /**
     * Cloud storage is the production source of truth. A newer index bundled
     * into a deployment may reference local images that were never uploaded.
     *
     * @param array<string,mixed> $localData
     * @param array<string,mixed> $remoteData
     * @return array<string,mixed>
     */
    private static function preferredIndexData(array $localData, array $remoteData, bool $cloudStorageActive): array
    {
        if ($cloudStorageActive && self::isValidIndexData($remoteData)) {
            return $remoteData;
        }
        return self::isValidIndexData($localData) ? $localData : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function isValidIndexData(array $data): bool
    {
        return isset($data['categories'], $data['images'])
            && is_array($data['categories'])
            && is_array($data['images']);
    }

    public function createdBaseDirectory(): bool
    {
        return $this->createdBaseDirectory;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Create an empty scene folder and immediately publish it to the library index.
     *
     * @return array<string,mixed>
     */
    public function createCategory(string $name): array
    {
        $slug = self::safeCategorySlug($name);
        if ($slug === '') {
            throw new RuntimeException('Write a valid scene name.');
        }
        if ($this->resolveCategorySlug($slug) !== '') {
            throw new RuntimeException('A scene with that name already exists.');
        }

        $path = $this->categoryPath($slug);
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('The scene folder could not be created.');
        }

        $this->rebuildIndex();
        return $this->categoryBySlug($slug);
    }

    /**
     * @return array<string,mixed>
     */
    public function renameCategory(string $sourceSlug, string $newName): array
    {
        $sourceSlug = $this->requireCategorySlug($sourceSlug);
        $targetSlug = self::safeCategorySlug($newName);
        if ($targetSlug === '') {
            throw new RuntimeException('Write a valid new scene name.');
        }
        if ($targetSlug === $sourceSlug) {
            return $this->categoryBySlug($sourceSlug);
        }

        $existingTarget = $this->resolveCategorySlug($targetSlug);
        if ($existingTarget !== '' && $existingTarget !== $sourceSlug) {
            throw new RuntimeException('That scene already exists. Use Merge instead of Rename.');
        }

        if (StorageService::isGcsActive()) {
            return $this->renameCloudCategory($sourceSlug, $targetSlug);
        }

        $sourcePath = $this->categoryPath($sourceSlug);
        $targetPath = $this->categoryPath($targetSlug);
        if (is_dir($targetPath) && realpath($targetPath) !== realpath($sourcePath)) {
            throw new RuntimeException('The destination scene folder already exists.');
        }

        if (strcasecmp($sourcePath, $targetPath) === 0) {
            $temporaryPath = $sourcePath . '__rename_' . bin2hex(random_bytes(4));
            if (!rename($sourcePath, $temporaryPath) || !rename($temporaryPath, $targetPath)) {
                if (is_dir($temporaryPath) && !is_dir($sourcePath)) {
                    @rename($temporaryPath, $sourcePath);
                }
                throw new RuntimeException('The scene folder could not be renamed.');
            }
        } elseif (!rename($sourcePath, $targetPath)) {
            throw new RuntimeException('The scene folder could not be renamed.');
        }

        $this->rebuildIndex();
        return $this->categoryBySlug($targetSlug);
    }

    /**
     * Move every file from one scene into another. Name collisions are preserved
     * with a numeric suffix; byte-identical duplicates are removed.
     *
     * @return array<string,mixed>
     */
    public function mergeCategory(string $sourceValue, string $targetValue): array
    {
        $this->assertLocalManagementAvailable();
        $sourceSlug = $this->requireCategorySlug($sourceValue);
        $targetSlug = $this->requireCategorySlug($targetValue);
        if ($sourceSlug === $targetSlug) {
            throw new RuntimeException('Choose two different scenes to merge.');
        }

        $sourcePath = $this->categoryPath($sourceSlug);
        $targetPath = $this->categoryPath($targetSlug);
        $sourceFiles = $this->categoryFiles($sourcePath);
        $usedNames = [];
        foreach ($this->categoryFiles($targetPath) as $targetFile) {
            $usedNames[strtolower(basename($targetFile))] = $targetFile;
        }

        $moves = [];
        $duplicates = [];
        foreach ($sourceFiles as $sourceFile) {
            $fileName = basename($sourceFile);
            $nameKey = strtolower($fileName);
            if (isset($usedNames[$nameKey]) && self::sameFileContents($sourceFile, $usedNames[$nameKey])) {
                $duplicates[] = $sourceFile;
                continue;
            }

            $destinationName = isset($usedNames[$nameKey])
                ? self::uniqueFileName($fileName, $usedNames)
                : $fileName;
            $destinationPath = $targetPath . DIRECTORY_SEPARATOR . $destinationName;
            $usedNames[strtolower($destinationName)] = $destinationPath;
            $moves[] = [$sourceFile, $destinationPath];
        }

        $completedMoves = [];
        try {
            foreach ($moves as [$sourceFile, $destinationPath]) {
                if (!rename($sourceFile, $destinationPath)) {
                    throw new RuntimeException('A scene image could not be moved: ' . basename($sourceFile));
                }
                $completedMoves[] = [$sourceFile, $destinationPath];
            }
        } catch (Throwable $e) {
            foreach (array_reverse($completedMoves) as [$sourceFile, $destinationPath]) {
                if (is_file($destinationPath) && !is_file($sourceFile)) {
                    @rename($destinationPath, $sourceFile);
                }
            }
            throw $e;
        }

        foreach ($duplicates as $duplicatePath) {
            if (is_file($duplicatePath) && !unlink($duplicatePath)) {
                throw new RuntimeException('A duplicated scene image could not be removed: ' . basename($duplicatePath));
            }
        }
        if (!rmdir($sourcePath)) {
            throw new RuntimeException('Images were merged, but the empty source folder could not be removed.');
        }

        $this->rebuildIndex();
        return [
            'source_slug' => $sourceSlug,
            'target_slug' => $targetSlug,
            'moved_count' => count($moves),
            'duplicate_count' => count($duplicates),
            'target' => $this->categoryBySlug($targetSlug),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteCategory(string $value): array
    {
        $slug = $this->requireCategorySlug($value);
        $path = $this->categoryPath($slug);
        if (StorageService::isGcsActive()) {
            $images = $this->imagesForCategory($slug);
            foreach ($images as $image) {
                $relativePath = (string)($image['relative_path'] ?? '');
                if ($relativePath !== '' && !StorageService::delete($relativePath)) {
                    throw new RuntimeException('A scene image could not be removed from persistent storage.');
                }
                $localPath = $path . DIRECTORY_SEPARATOR . (string)($image['file_name'] ?? '');
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
            }
            if (is_dir($path)) {
                @rmdir($path);
            }
            $this->loadIndex();
            $this->indexData['categories'] = array_values(array_filter(
                (array)($this->indexData['categories'] ?? []),
                static fn (array $category): bool => (string)($category['category_slug'] ?? '') !== $slug
            ));
            unset($this->indexData['images'][$slug]);
            $this->persistIndexData();
            return ['category_slug' => $slug, 'deleted_images' => count($images)];
        }
        $files = $this->categoryFiles($path);

        foreach ($files as $file) {
            if (!unlink($file)) {
                throw new RuntimeException('The scene image could not be deleted: ' . basename($file));
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('The empty scene folder could not be deleted.');
        }

        $this->rebuildIndex();
        return ['category_slug' => $slug, 'deleted_images' => count($files)];
    }

    /**
     * Rebuild the operational index from the real folders on disk. This is also
     * the safe synchronization entry point after manual folder organization.
     *
     * @return array<string,mixed>
     */
    public function rebuildIndex(): array
    {
        $cloudStorageActive = StorageService::isGcsActive();
        if ($cloudStorageActive) {
            $this->loadIndex();
        }
        $categories = [];
        $images = [];
        foreach ($this->categorySlugs() as $slug) {
            $categoryName = self::titleFromSlug($slug);
            $categories[] = [
                'category_slug' => $slug,
                'category_name' => $categoryName,
                'relative_path' => $this->baseRelativePath . '/' . $slug,
            ];
            $images[$slug] = $this->scanImagesForCategory($slug);
        }

        if ($cloudStorageActive) {
            $categoriesBySlug = [];
            foreach ((array)($this->indexData['categories'] ?? []) as $category) {
                $existingSlug = (string)($category['category_slug'] ?? '');
                if ($existingSlug !== '') {
                    $categoriesBySlug[$existingSlug] = $category;
                }
            }
            foreach ($categories as $category) {
                $categoriesBySlug[(string)$category['category_slug']] = $category;
            }
            $categories = array_values($categoriesBySlug);
            usort($categories, static fn (array $a, array $b): int => strcmp((string)$a['category_slug'], (string)$b['category_slug']));

            $mergedImages = (array)($this->indexData['images'] ?? []);
            foreach ($images as $slug => $localImages) {
                $byFileName = [];
                foreach ((array)($mergedImages[$slug] ?? []) as $image) {
                    $fileName = (string)($image['file_name'] ?? '');
                    if ($fileName !== '') {
                        $byFileName[$fileName] = $image;
                    }
                }
                foreach ($localImages as $image) {
                    $byFileName[(string)$image['file_name']] = $image;
                }
                $mergedImages[$slug] = array_values($byFileName);
            }
            $images = $mergedImages;
        }

        $data = [
            'generated_at' => date(DATE_ATOM),
            'categories' => $categories,
            'images' => $images,
        ];
        $this->indexData = $data;
        $this->indexLoaded = true;
        $this->persistIndexData();
        return $data;
    }

    /**
     * @return array{category_slug:string,file_name:string}
     */
    public function deleteImage(string $categoryValue, string $fileName): array
    {
        $slug = $this->requireCategorySlug($categoryValue);
        $fileName = basename(trim($fileName));
        $allowedFiles = [];
        foreach ($this->imagesForCategory($slug) as $image) {
            $allowedFiles[(string)($image['file_name'] ?? '')] = (string)($image['relative_path'] ?? '');
        }
        if ($fileName === '' || !isset($allowedFiles[$fileName])) {
            throw new RuntimeException('The selected scene image no longer exists.');
        }

        $relativePath = $allowedFiles[$fileName];
        if (StorageService::isGcsActive() && !StorageService::delete($relativePath)) {
            throw new RuntimeException('The scene image could not be removed from persistent storage.');
        }
        $localPath = $this->categoryPath($slug) . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($localPath) && !unlink($localPath)) {
            throw new RuntimeException('The local scene image could not be removed.');
        }

        if (StorageService::isGcsActive()) {
            $this->loadIndex();
            $this->indexData['images'][$slug] = array_values(array_filter(
                (array)($this->indexData['images'][$slug] ?? []),
                static fn (array $image): bool => (string)($image['file_name'] ?? '') !== $fileName
            ));
            $this->persistIndexData();
        } else {
            $this->rebuildIndex();
        }

        return ['category_slug' => $slug, 'file_name' => $fileName];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categories(): array
    {
        $this->loadIndex();
        if (!empty($this->indexData['categories'])) {
            $categories = [];
            foreach ($this->indexData['categories'] as $cat) {
                $cat['absolute_path'] = $this->basePath . DIRECTORY_SEPARATOR . $cat['category_slug'];
                if (!StorageService::isGcsActive() && !is_dir($cat['absolute_path'])) {
                    continue;
                }
                $cat['image_count'] = count($this->imagesForCategory($cat['category_slug']));
                $categories[] = $cat;
            }
            return $categories;
        }

        $categories = [];
        foreach ($this->categorySlugs() as $slug) {
            $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . $slug;
            $categories[] = [
                'category_slug' => $slug,
                'category_name' => self::titleFromSlug($slug),
                'relative_path' => $this->baseRelativePath . '/' . $slug,
                'absolute_path' => $absolutePath,
                'image_count' => count($this->imagesForCategory($slug)),
                'modified_at' => self::modifiedAt($absolutePath),
            ];
        }

        return $categories;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function imagesForCategory(string $categorySlug): array
    {
        $categorySlug = trim(str_replace(['\\', '/'], '', $categorySlug));
        if ($categorySlug === '') {
            return [];
        }

        $this->loadIndex();
        if (!empty($this->indexData['images'][$categorySlug])) {
            $images = [];
            $remoteStorageActive = StorageService::isGcsActive();
            foreach ($this->indexData['images'][$categorySlug] as $img) {
                $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . $categorySlug . DIRECTORY_SEPARATOR . $img['file_name'];
                if (!$remoteStorageActive && !is_file($absolutePath)) {
                    continue;
                }
                $img['absolute_path'] = $absolutePath;
                $images[] = $img;
            }
            return $images;
        }

        $categoryPath = $this->basePath . DIRECTORY_SEPARATOR . $categorySlug;
        if (!is_dir($categoryPath)) {
            return [];
        }

        $images = [];
        $iterator = new DirectoryIterator($categoryPath);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $fileName = $file->getFilename();
            $stem = pathinfo($fileName, PATHINFO_FILENAME);
            $images[] = [
                'world_mother_id' => $categorySlug . '/' . $stem,
                'category_slug' => $categorySlug,
                'category_name' => self::titleFromSlug($categorySlug),
                'file_name' => $fileName,
                'title' => self::titleFromSlug($stem),
                'relative_path' => $this->baseRelativePath . '/' . $categorySlug . '/' . $fileName,
                'absolute_path' => $file->getPathname(),
                'extension' => $extension,
                'modified_at' => self::modifiedAt($file->getPathname()),
                'file_size' => $file->getSize(),
            ];
        }

        usort($images, static fn (array $a, array $b): int => strcmp((string)$a['title'], (string)$b['title']));
        return $images;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function allImages(): array
    {
        $images = [];
        foreach ($this->categories() as $category) {
            $images = array_merge($images, $this->imagesForCategory((string)$category['category_slug']));
        }

        return $images;
    }

    /**
     * @return array<int,string>
     */
    public static function allowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * @return array<int,string>
     */
    private function categorySlugs(): array
    {
        if (!is_dir($this->basePath)) {
            return [];
        }

        $slugs = [];
        $iterator = new DirectoryIterator($this->basePath);
        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isDir()) {
                continue;
            }
            $slug = $file->getFilename();
            if (in_array(strtolower($slug), self::RESERVED_DIRECTORIES, true)) {
                continue;
            }
            $slugs[] = $slug;
        }

        sort($slugs, SORT_STRING);
        return $slugs;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scanImagesForCategory(string $categorySlug): array
    {
        $categoryPath = $this->categoryPath($categorySlug);
        if (!is_dir($categoryPath)) {
            return [];
        }

        $images = [];
        foreach (new DirectoryIterator($categoryPath) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $fileName = $file->getFilename();
            $stem = pathinfo($fileName, PATHINFO_FILENAME);
            $images[] = [
                'world_mother_id' => $categorySlug . '/' . $stem,
                'category_slug' => $categorySlug,
                'category_name' => self::titleFromSlug($categorySlug),
                'file_name' => $fileName,
                'title' => self::titleFromSlug($stem),
                'relative_path' => $this->baseRelativePath . '/' . $categorySlug . '/' . $fileName,
                'extension' => $extension,
            ];
        }
        usort($images, static fn (array $a, array $b): int => strcmp((string)$a['title'], (string)$b['title']));
        return $images;
    }

    private function assertLocalManagementAvailable(): void
    {
        if (StorageService::isGcsActive()) {
            throw new RuntimeException('Merging complete scene folders is not available while cloud storage is active.');
        }
    }

    private function indexPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'index.json';
    }

    private function categoryPath(string $slug): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $slug;
    }

    private function resolveCategorySlug(string $value): string
    {
        $value = trim(str_replace(['\\', '/'], '', $value));
        if ($value === '') {
            return '';
        }
        $normalized = self::safeCategorySlug($value);
        $this->loadIndex();
        $knownSlugs = $this->categorySlugs();
        foreach ((array)($this->indexData['categories'] ?? []) as $category) {
            $knownSlugs[] = (string)($category['category_slug'] ?? '');
        }
        foreach (array_values(array_unique(array_filter($knownSlugs))) as $slug) {
            if ($slug === $value || self::safeCategorySlug($slug) === $normalized) {
                return $slug;
            }
        }
        return '';
    }

    private function requireCategorySlug(string $value): string
    {
        $slug = $this->resolveCategorySlug($value);
        if ($slug === '') {
            throw new RuntimeException('The selected scene no longer exists. Refresh the page and try again.');
        }
        return $slug;
    }

    /**
     * @return array<string,mixed>
     */
    private function categoryBySlug(string $slug): array
    {
        foreach ($this->categories() as $category) {
            if ((string)($category['category_slug'] ?? '') === $slug) {
                return $category;
            }
        }
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function renameCloudCategory(string $sourceSlug, string $targetSlug): array
    {
        $sourceImages = $this->imagesForCategory($sourceSlug);
        $targetPath = $this->categoryPath($targetSlug);
        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new RuntimeException('The renamed scene workspace could not be created.');
        }

        $renamedImages = [];
        $uploadedTargets = [];
        try {
            foreach ($sourceImages as $image) {
                $fileName = (string)($image['file_name'] ?? '');
                $sourceRelativePath = (string)($image['relative_path'] ?? '');
                $sourceLocalPath = $this->categoryPath($sourceSlug) . DIRECTORY_SEPARATOR . $fileName;
                if (!is_file($sourceLocalPath) && !StorageService::downloadFile($sourceRelativePath, $sourceLocalPath)) {
                    throw new RuntimeException('A scene image could not be prepared for renaming.');
                }
                $targetLocalPath = $targetPath . DIRECTORY_SEPARATOR . $fileName;
                if (!copy($sourceLocalPath, $targetLocalPath)) {
                    throw new RuntimeException('A scene image could not be copied into the renamed scene.');
                }
                $targetRelativePath = $this->baseRelativePath . '/' . $targetSlug . '/' . $fileName;
                if (!StorageService::uploadFile($targetRelativePath, $targetLocalPath)) {
                    throw new RuntimeException('A renamed scene image could not be saved to persistent storage.');
                }
                $uploadedTargets[] = $targetRelativePath;
                $image['category_slug'] = $targetSlug;
                $image['category_name'] = self::titleFromSlug($targetSlug);
                $image['world_mother_id'] = $targetSlug . '/' . pathinfo($fileName, PATHINFO_FILENAME);
                $image['relative_path'] = $targetRelativePath;
                $renamedImages[] = $image;
            }
        } catch (Throwable $e) {
            foreach ($uploadedTargets as $targetRelativePath) {
                StorageService::delete($targetRelativePath);
            }
            throw $e;
        }

        foreach ($sourceImages as $image) {
            StorageService::delete((string)($image['relative_path'] ?? ''));
        }

        $this->loadIndex();
        $categories = (array)($this->indexData['categories'] ?? []);
        foreach ($categories as &$category) {
            if ((string)($category['category_slug'] ?? '') === $sourceSlug) {
                $category['category_slug'] = $targetSlug;
                $category['category_name'] = self::titleFromSlug($targetSlug);
                $category['relative_path'] = $this->baseRelativePath . '/' . $targetSlug;
            }
        }
        unset($category);
        $this->indexData['categories'] = $categories;
        unset($this->indexData['images'][$sourceSlug]);
        $this->indexData['images'][$targetSlug] = $renamedImages;
        $this->persistIndexData();

        $sourcePath = $this->categoryPath($sourceSlug);
        if (is_dir($sourcePath)) {
            foreach (glob($sourcePath . DIRECTORY_SEPARATOR . '*') ?: [] as $sourceFile) {
                if (is_file($sourceFile)) {
                    @unlink($sourceFile);
                }
            }
            @rmdir($sourcePath);
        }
        return $this->categoryBySlug($targetSlug);
    }

    private function persistIndexData(): void
    {
        $this->indexData['generated_at'] = date(DATE_ATOM);
        $encoded = json_encode($this->indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || file_put_contents($this->indexPath(), $encoded, LOCK_EX) === false) {
            throw new RuntimeException('The scene library index could not be updated.');
        }
        if (StorageService::isGcsActive() && !StorageService::uploadFile($this->baseRelativePath . '/index.json', $this->indexPath())) {
            throw new RuntimeException('The scene library index could not be saved to persistent storage.');
        }
        $this->indexLoaded = true;
    }

    /**
     * @return array<int,string>
     */
    private function categoryFiles(string $path): array
    {
        if (!is_dir($path)) {
            throw new RuntimeException('The selected scene folder was not found.');
        }
        $files = [];
        foreach (new DirectoryIterator($path) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if (!$file->isFile()) {
                throw new RuntimeException('Scene folders cannot contain nested folders. Move that content before continuing.');
            }
            $files[] = $file->getPathname();
        }
        return $files;
    }

    private static function safeCategorySlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return substr(trim($value, '_'), 0, 80);
    }

    /**
     * @param array<string,string> $usedNames
     */
    private static function uniqueFileName(string $fileName, array $usedNames): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        for ($suffix = 2; $suffix < 10000; $suffix++) {
            $candidate = $stem . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
            if (!isset($usedNames[strtolower($candidate)])) {
                return $candidate;
            }
        }
        throw new RuntimeException('A unique filename could not be assigned while merging scenes.');
    }

    private static function sameFileContents(string $left, string $right): bool
    {
        return filesize($left) === filesize($right)
            && hash_file('sha256', $left) === hash_file('sha256', $right);
    }

    private static function titleFromSlug(string $slug): string
    {
        $slug = trim(str_replace(['-', '_'], ' ', $slug));
        $slug = preg_replace('/\s+/', ' ', $slug) ?: '';
        return ucwords(strtolower($slug));
    }

    private static function modifiedAt(string $path): ?string
    {
        $mtime = @filemtime($path);
        return $mtime !== false ? date('c', $mtime) : null;
    }
}

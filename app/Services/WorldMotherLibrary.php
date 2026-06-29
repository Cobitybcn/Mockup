<?php
declare(strict_types=1);

final class WorldMotherLibrary
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private string $basePath;
    private string $baseRelativePath;
    private bool $createdBaseDirectory = false;

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

    public function createdBaseDirectory(): bool
    {
        return $this->createdBaseDirectory;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categories(): array
    {
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

            $slugs[] = $file->getFilename();
        }

        sort($slugs, SORT_STRING);
        return $slugs;
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

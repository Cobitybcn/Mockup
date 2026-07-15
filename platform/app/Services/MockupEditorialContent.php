<?php
declare(strict_types=1);

final class MockupEditorialContent
{
    public static function build(array $artwork, array $analysis, array $artistProfile, string $contextTitle): array
    {
        $profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
        $style = self::englishTerms(self::wordsFrom($profile['style_tags'] ?? $artistProfile['visual_language'] ?? []), ['contemporary', 'abstract', 'material']);
        $mood = self::englishTerms(self::wordsFrom($profile['mood_tags'] ?? []), ['quiet intensity', 'contemplative', 'collector-grade']);
        $palette = self::englishTerms(self::wordsFrom($profile['palette'] ?? $artistProfile['palette_notes'] ?? []), ['balanced palette', 'sober tones']);
        $themes = self::englishTerms(self::wordsFrom($artistProfile['recurring_themes'] ?? ''), ['territory', 'silence', 'material presence']);
        $storedTitle = trim((string)($artwork['final_title'] ?? ''));
        $baseTitle = $storedTitle !== '' ? $storedTitle : self::titleCaseSoft(($palette[0] ?? 'Balanced Palette') . ' in ' . ($themes[0] ?? 'Quiet Space'));
        $subtitle = trim((string)($artwork['subtitle'] ?? ''));
        if ($subtitle === '') $subtitle = self::titleCaseSoft('a ' . ($style[0] ?? 'contemporary') . ' artwork for collectors');
        $titleLine = $baseTitle . ': ' . $subtitle;
        $board = in_array('architectural', $style, true) || in_array('structural', $style, true) ? 'Architectural Minimalism' : 'Contemporary Abstract Art';
        $title = $baseTitle . ' - Original Contemporary Abstract Artwork';
        $description = $titleLine . "\n\n" . 'This Pin features a generated curatorial mockup of an original contemporary artwork in a ' . strtolower($contextTitle) . ' setting. The image highlights the artwork scale, wall presence, color atmosphere, and gallery-ready presentation for collectors, interior designers, galleries, and buyers searching for abstract art for interiors.';
        $altText = 'A generated mockup showing ' . $baseTitle . ' as a contemporary artwork in a ' . strtolower($contextTitle) . ' setting, with visible wall placement, artwork scale, color presence, and surrounding interior atmosphere.';
        $keywords = self::uniqueLimited(array_merge([
            'contemporary abstract art','original painting for sale','artwork for collectors','abstract painting for interiors',
            'minimalist abstract painting','large wall art','gallery ready artwork','art for interior designers','statement painting','modern collector art',
        ], $style, $palette, $themes), 14);
        $hashtags = array_map(
            static fn($tag): string => '#' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)),
            self::uniqueLimited(['contemporary art','abstract painting','original artwork','art collectors','interior design art','large painting','statement art'], 8)
        );
        $social = [
            'Instagram' => $titleLine . "\n\n" . 'Generated curatorial mockup for ' . strtolower($contextTitle) . '. ' . self::titleCaseSoft(implode(', ', array_slice($style, 0, 3))) . ' with ' . implode(', ', array_slice($mood, 0, 2)) . ".\n\n" . implode(' ', array_slice($hashtags, 0, 8)),
            'Facebook' => $titleLine . "\n\n" . 'A curated mockup presentation prepared for collectors, interior designers, galleries, and marketplace publication. This image can accompany the artwork listing, auction page, or artist profile.',
            'X' => $baseTitle . ' - original contemporary artwork shown in a curated mockup for collectors, interiors, and art platforms.',
            'TikTok' => 'Use this mockup as a short reveal: start with the room context, move into the artwork surface and scale, then end with the title "' . $baseTitle . '" and the destination link.',
        ];
        return compact('board','title','description','altText','keywords','hashtags','social','baseTitle','subtitle','titleLine');
    }

    private static function wordsFrom(mixed $value): array
    {
        if (is_array($value)) { $items=[]; foreach($value as $item) $items=array_merge($items,self::wordsFrom($item)); return $items; }
        $parts=preg_split('/[,;|\/\n]+/',strtolower((string)$value));
        return array_values(array_filter(array_map(static fn($part): string=>trim((string)preg_replace('/\s+/',' ',(string)$part)),$parts?:[])));
    }
    private static function uniqueLimited(array $items,int $limit,array $fallback=[]): array
    {
        $out=[]; foreach(array_merge($items,$fallback) as $item){$item=trim((string)preg_replace('/\s+/',' ',(string)$item));if($item==='')continue;$key=strtolower($item);if(!isset($out[$key]))$out[$key]=$item;if(count($out)>=$limit)break;}return array_values($out);
    }
    private static function englishTerm(string $value): string
    {
        $value=str_replace(['Á','É','Í','Ó','Ú','Ü','Ñ','á','é','í','ó','ú','ü','ñ'],['A','E','I','O','U','U','N','a','e','i','o','u','u','n'],$value);
        $value=strtolower(trim((string)preg_replace('/[_-]+/',' ',$value)));
        $map=['abstracto'=>'abstract','contemporaneo'=>'contemporary','geometrico'=>'geometric','arquitectonico'=>'architectural','organico'=>'organic','minimalista'=>'minimal','figurativo'=>'figurative','expresivo'=>'expressive','estructural'=>'structural','simbolico'=>'symbolic','metafisico'=>'metaphysical','silencio'=>'silence','territorio'=>'territory','austeridad'=>'austerity','monolitos'=>'monoliths','coleccionistas'=>'collectors','galeria'=>'gallery','sutil'=>'subtle'];
        return $map[$value]??$value;
    }
    private static function englishTerms(array $items,array $fallback): array
    {
        $terms=[];foreach($items as $item){$term=self::englishTerm((string)$item);if(str_word_count($term)<=4&&strlen($term)<=42)$terms[]=$term;}return self::uniqueLimited($terms,12,$fallback);
    }
    private static function titleCaseSoft(string $value): string
    {
        $small=['and','or','of','in','the','a','an','with','for'];$words=preg_split('/\s+/',strtolower(trim($value)))?:[];$words=array_map(static fn(string $word):string=>in_array($word,$small,true)?$word:ucfirst($word),$words);if($words)$words[0]=ucfirst($words[0]);return implode(' ',$words);
    }
}

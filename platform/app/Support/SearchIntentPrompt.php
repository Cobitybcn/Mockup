<?php
declare(strict_types=1);

final class SearchIntentPrompt
{
    public static function forEntity(string $entityType): string
    {
        $entityRule = match ($entityType) {
            'series' => 'Describe this exact series from the artist-authored title, explanation, conceptual direction and profile. Do not infer identity from image analysis or use the series title alone as a search phrase.',
            'artwork' => 'Stay specific to this exact artwork. Use confirmed medium, material, dimensions, orientation and series only when supplied. Acquisition phrases must describe the original artwork, never promise availability.',
            'mockup' => 'Use the visible architectural setting to express plausible placement and professional context, but keep the artwork as the object of discovery or acquisition. Never infer XL, XXL or monumental scale from the room view; use only the approved artwork dimensions. Never present the mockup itself as the artwork for sale.',
            default => throw new InvalidArgumentException('Unsupported search-intent entity.'),
        };

        return <<<RULES
SEARCH INTENT
Keep the artist's meaning and the buyer's search language as two different layers. The artist-authored material explains the work after a visitor arrives. Search metadata helps that visitor find it through ordinary art-market language.
Derive useful search language from confirmed facts, supported visual evidence, artist profile and series context, but never turn private or curatorial vocabulary into keywords merely because it appears in the source.
{$entityRule}

- Produce only four buyer-facing SEO results wherever the output schema provides them: one compact catalogue classification, one non-duplicative set of real search phrases, one SEO title and one SEO description.
- Do not create separate keyword-primary, secondary, collector, architect, acquisition or long-tail lists. Those are facets of the same buyer search set, not separate content blocks.
- Use a premium art-marketplace discovery model: object type, subject, recognized style, medium/support, one-of-a-kind status, confirmed size/format, dominant color and acquisition intent.
- Produce natural phrases in the required output language, not literal translations and not isolated-word dumps.
- In Spanish, write complete grammatical search phrases with the necessary articles, conjunctions and prepositions. Never compress attributes into unnatural noun stacks such as "pintura acrílico óleo lienzo" or "cuadro tonos tierra azul".
- Start with established, plain-language art searches: artwork category, original status, medium, contemporary style, supported visual attributes, format, scale and acquisition intent.
- Read the artist profile's Materials and process field as the primary source for studio techniques, materials and supports. Preserve every explicitly named medium and its stated role; never collapse a mixed process to only the first technique.
- In catalogue classification, prioritize confirmed medium, technique and support over symbolic or curatorial vocabulary. If the artist states acrylic with oil finishes on canvas, retain acrylic, oil and canvas in natural catalogue language.
- Select facets dynamically from supported evidence: object type, subject, recognized style, surface, dominant color, orientation, medium/support, one-of-a-kind status, scale, acquisition intent and professional context. Never assume a particular subject or style.
- Generate separate natural combinations instead of forcing every facet into one phrase. Use these structural patterns with the actual analyzed values: object + original status; object + style; object + style + medium; object + scale; object + color; object + surface; and object + acquisition intent.
- This framework must work without modification for abstract art, surrealism, figurative art, expressionism, landscape, portraiture or any other recognized category supported by the evidence. Never carry a style from an example into the output.
- Use the ordinary buyer vocabulary of each language. In Spanish, consider both "pintura" and "cuadro" when natural; in English, use idiomatic "painting", "art" and "artwork" constructions.
- Scale is a high-value filter. Use large format, oversized, XL, XXL or monumental only when confirmed physical dimensions genuinely support the term. Otherwise omit it. Never guess scale from visual impact.
- Use color only from the verified palette and texture only from visible or documented surface evidence.
- Across the final search set, cover the supported intentions without creating separate interface blocks: clear art category, acquisition, collectors and professional placement.
- Include explicit acquisition language in some phrases when natural: original work, painting for sale, acquire, buy or their idiomatic equivalent. Keep the tone sober and premium.
- Build specific phrases only from attributes buyers commonly use to narrow an art search: medium, color, orientation, confirmed size or scale, geometric/minimal/gestural character, texture, audience or professional context.
- Prefer phrases a person could genuinely type into a search engine. Do not manufacture a long-tail phrase by attaching a poetic, symbolic, geological or curatorial concept to an art category.
- Keep artist-authored concepts in the editorial description unless the concept is itself a recognized art-market category or style. Never use the formula "inspired by [concept]" merely to create a keyword.
- Catalogue classification must contain ten to fourteen concise filters when the evidence supports them: object type, subject, recognized styles, every confirmed technique and material, support, surface, dominant colors, orientation, format and justified scale. Omit unsupported facets and never fill the list with poetic or private conceptual vocabulary.
- The real-search set must contain twelve to sixteen distinct phrases that someone could plausibly type when seeking art to discover or buy. Include broad category searches, medium/style combinations, supported color/surface/format combinations, acquisition intent and professional context.
- At least six real-search phrases must be genuine long tails combining three or more useful buyer attributes. Keep them natural; do not repeat the classification as prose or manufacture phrases from curatorial concepts.
- The SEO title must be unique, descriptive and concise. Use exactly this structure: exact public title | strongest plain-language category phrase | artist name. Use exactly two spaced vertical separators. Include the title and artist once each; do not write a sentence, use a colon or dash, or add "de" or "by".
- The SEO description must be a human-readable, page-specific summary rather than a keyword list. Identify the exact item, artist, object category, confirmed medium/process and strongest recognized style or visible attribute. Use only one or two search phrases naturally and avoid generic openings such as "Discover" or "Explore".
- Public short and long descriptions must also give Google useful page content: identify the work or series naturally through its category, confirmed medium/process and distinguishing visual character before developing the artist's conceptual language.
- For a series, rebuild the public copy from the current evidence and search architecture. Integrate the recognizable buyer vocabulary of three or four strong descriptive search phrases naturally across the short and long descriptions, with at least one represented in the short description and at least three distinct phrases represented across both texts. Grammatical inflection is allowed; do not force robotic exact matches. Use only category, recognized style, medium/process, surface, color or format phrases there. Keep acquisition, collector and professional-context searches exclusively in metadata. Spread the descriptive phrases through the prose and never append a generic sales sentence.
- For an artwork or mockup, use only the smaller number of search phrases required by its own output instructions. Never stuff search terms into curatorial prose.
- Do not use cheap décor language, generic marketplace filler, keyword stuffing or unsupported availability claims.
- Never invent or imply search volume, competition, ranking, demand or regional performance.
RULES;
    }
}

# Artwork Content Analysis Contract v2

Status: design proposal only. This contract does not modify historical analyses, `artwork_sheets`, `mockup_sheets`, or publication data.

## Core rule

The artwork owns the meaning. A mockup owns only the presentation context.

- One artwork has one canonical editorial identity.
- The artist profile is context, never reusable public copy.
- Interpretations must cite visible evidence and expose their confidence.
- Channel copy is derived from the canonical identity; it never replaces it.
- Mockup content may describe the scene and the artwork–space relationship, but may not rename or reinterpret the artwork.

## Contract A: canonical artwork analysis

```json
{
  "schema_version": "artwork-analysis.v2",
  "artwork_id": 0,
  "analysis_language": "en",
  "source": {
    "image_file": "",
    "artist_profile_version": "",
    "analysis_prompt_version": "",
    "analyzed_at": ""
  },
  "confirmed_facts": {
    "working_title": "",
    "artist": "",
    "year": null,
    "series": null,
    "medium": null,
    "materials": [],
    "width_cm": null,
    "height_cm": null,
    "depth_cm": null,
    "orientation": "",
    "signature": null,
    "certificate_of_authenticity": null,
    "presentation": null,
    "shipping_notes": null
  },
  "evidence_sources": {
    "artist_or_record_facts": [],
    "visual_observations": [],
    "interpretive_claims": []
  },
  "editorial_strategy": {
    "description_opening_type": "",
    "description_opening_rhythm": "",
    "description_structure_type": "",
    "paragraph_functions": [],
    "opening_paragraph": ""
  },
  "visual_analysis": {
    "dominant_colors": [],
    "secondary_colors": [],
    "color_temperature": "",
    "contrast": "",
    "composition": {
      "type": "",
      "organization": "",
      "focal_areas": [],
      "balance": "",
      "depth": ""
    },
    "rhythm_and_movement": "",
    "surface_and_texture": "",
    "visible_elements": [],
    "visible_marks_or_process": [],
    "spatial_presence": "",
    "emotional_atmosphere": [],
    "distinctive_features": []
  },
  "interpretation": {
    "central_reading": "",
    "supporting_readings": [
      {
        "reading": "",
        "visible_evidence": [],
        "confidence": "high|medium|low"
      }
    ],
    "relationship_to_artist_profile": "",
    "relationship_to_series": "",
    "open_questions": [],
    "claims_to_avoid": []
  },
  "canonical_editorial": {
    "title": "",
    "subtitle": "",
    "short_description": "",
    "master_description": "",
    "artist_vocabulary": [],
    "buyer_facing_terms": [],
    "alt_text": "",
    "caption": ""
  },
  "search_metadata": {
    "core_keywords": [],
    "specific_keywords": [],
    "long_tail_terms": []
  },
  "originality_check": {
    "catalogue_checked": false,
    "title_unique": null,
    "closest_title": null,
    "title_similarity": null,
    "closest_description_artwork_id": null,
    "description_similarity": null,
    "repeated_openings": [],
    "repeated_phrases": [],
    "structure_used": "",
    "warnings": [],
    "passed": false
  },
  "review": {
    "analysis_status": "draft|reviewed|approved",
    "editorial_status": "draft|reviewed|approved",
    "reviewed_by": null,
    "reviewed_at": null,
    "notes": ""
  }
}
```

### Validation rules

- `canonical_editorial.title`, `subtitle` and `master_description` are singular values, not arrays of alternatives.
- A visual observation and an interpretation are stored separately.
- Important claims identify whether they come from the artist/record, the image, or an interpretation.
- The application selects and records a balanced narrative opening and sentence rhythm before generation.
- Every symbolic or conceptual reading needs visible evidence.
- Unknown technical facts remain `null`; they are never inferred from the image.
- `master_description` is channel-neutral. Saatchi length, Pinterest wording and website CTA rules belong to channel adapters.
- Approval requires catalogue-level originality checks performed by the application, not claimed by the language model without catalogue data.
- Scale vocabulary requires confirmed dimensions; decorative mass-market vocabulary is rejected.

## Contract B: lightweight mockup analysis

```json
{
  "schema_version": "mockup-analysis.v1",
  "mockup_id": 0,
  "artwork_id": 0,
  "artwork_identity": {
    "title": "",
    "subtitle": ""
  },
  "scene_analysis": {
    "space_type": "",
    "architectural_style": "",
    "materials": [],
    "palette": [],
    "lighting": "",
    "camera_view": "",
    "artwork_scale_reading": "",
    "artwork_space_relationship": "",
    "atmosphere": [],
    "distinctive_scene_features": []
  },
  "editorial": {
    "context_title": "",
    "short_description": "",
    "alt_text": "",
    "caption": "",
    "keywords": []
  },
  "destination_fit": {
    "website": "primary|secondary|not_recommended",
    "pinterest": "primary|secondary|not_recommended",
    "recommended_board_topics": [],
    "reason": ""
  },
  "review": {
    "status": "draft|reviewed|approved",
    "notes": ""
  }
}
```

### Mockup limits

- `artwork_identity.title` is copied from the approved artwork sheet and is not regenerated.
- `context_title` names the scene or presentation; it is not a replacement artwork title.
- Scene copy must be specific to the visible mockup. Generic text such as "curatorial mockup for collectors and interior designers" is not acceptable.
- The mockup analysis cannot introduce new meanings, techniques, materials, series, or facts about the artwork.

## Channel adapters

The canonical analysis does not directly generate final marketplace copy.

- Website: title, subtitle, short description, selected master-description passages, facts, collector information and chosen mockups.
- Saatchi Art: one adapted long description, exactly 12 justified keywords, technical fields and subtle spatial language.
- Pinterest: mockup-specific title/description, approved artwork identity, destination link, board selection and platform topic mapping.

## Mapping from current data

| Current field | v2 destination | Rule |
|---|---|---|
| `suggested_titles[0]` | `canonical_editorial` | Import as a draft only; artist/admin approval is required. |
| `suggested_titles[1..2]` | legacy archive | Preserve but do not expose as canonical alternatives. |
| `visual_language` | `visual_analysis` | Split into observable composition, rhythm and surface fields. |
| `visible_symbols` | `visual_analysis.visible_elements` | Treat as visible elements until interpretation is separately justified. |
| `one_line_curatorial_read` | `interpretation.central_reading` | Mark as an interpretation, not a confirmed fact. |
| `publishing_metadata.keywords` | `search_metadata` | Reclassify as core, specific and long-tail terms. |
| `contextual_proposals` | mockup planning archive | Do not store inside the canonical artwork identity. |
| `mockup_sheets.title` | `editorial.context_title` | Keep separate from the artwork title. |
| `mockup_sheets.description` | `editorial.short_description` | Regenerate only when requested; never overwrite automatically. |

## Safe transition

1. Read existing analyses through a compatibility mapper.
2. Display v2 fields as drafts without rewriting historical JSON.
3. Let the admin review detailed analysis and select/approve the canonical identity.
4. Show artists a reduced editing view backed by the same approved data.
5. Generate new mockup analysis separately.
6. Add catalogue-level similarity checks before approval.
7. Only after validation, make v2 the default contract for new analyses.

No bulk migration, recalculation, publication, or overwrite is part of this design.

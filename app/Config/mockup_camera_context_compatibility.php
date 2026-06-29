<?php
declare(strict_types=1);

return [
    'schema' => 'mockup_camera_context_compatibility.v1',
    'slot_quality_levels' => ['excellent', 'allowed', 'avoid', 'deny'],
    'rules' => [
        'minimal_contemporary_world' => [
            'world_id' => 'minimal_contemporary_world',
            'excellent' => ['reflejo_dorado_tarde_palazzo', 'vista_aerea_contexto_ventanas', 'pasillo_obra_descentrada_proxima'],
            'allowed' => ['contrapicado_7_8', 'borgona_recovecos_3_4_loft_hormigon', 'diagonal_estudio_moderno', 'luz_dorada_sombra_diagonal'],
            'avoid' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro'],
            'allow_camera_slots' => ['reflejo_dorado_tarde_palazzo', 'vista_aerea_contexto_ventanas', 'pasillo_obra_descentrada_proxima', 'contrapicado_7_8', 'borgona_recovecos_3_4_loft_hormigon', 'diagonal_estudio_moderno', 'luz_dorada_sombra_diagonal'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
            'decision_reasons' => [
                'contrapicado_raton_puro' => 'Extreme camera; usable only with control and not ideal default for minimal commercial context.',
            ],
            'family_variant_overrides' => [
                'contemporary_concrete_collector' => [
                    'concrete_collector_oblique' => [
                        'allowed' => ['contrapicado_raton_puro'],
                        'decision_reasons' => [
                            'contrapicado_raton_puro' => 'Allowed for the concrete collector family when the low camera emphasizes sober material scale without turning the room monumental.',
                        ],
                    ],
                ],
            ],
        ],
        'historical_european_world' => [
            'world_id' => 'historical_european_world',
            'excellent' => ['reflejo_dorado_tarde_palazzo', 'borgona_recovecos_3_4_loft_hormigon', 'luz_dorada_sombra_diagonal'],
            'allowed' => ['vista_aerea_contexto_ventanas', 'pasillo_obra_descentrada_proxima', 'contrapicado_7_8'],
            'avoid' => ['nadir_extremo_arquitectonico'],
            'allow_camera_slots' => ['reflejo_dorado_tarde_palazzo', 'borgona_recovecos_3_4_loft_hormigon', 'luz_dorada_sombra_diagonal', 'vista_aerea_contexto_ventanas', 'pasillo_obra_descentrada_proxima', 'contrapicado_7_8'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
        ],
        'period_design_world' => [
            'world_id' => 'period_design_world',
            'excellent' => ['diagonal_estudio_moderno', 'luz_dorada_sombra_diagonal', 'reflejo_dorado_tarde_palazzo'],
            'allowed' => ['pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon', 'vista_aerea_contexto_ventanas'],
            'avoid' => ['nadir_extremo_arquitectonico'],
            'allow_camera_slots' => ['diagonal_estudio_moderno', 'luz_dorada_sombra_diagonal', 'reflejo_dorado_tarde_palazzo', 'pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon', 'vista_aerea_contexto_ventanas'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
        ],
        'artist_studio_world' => [
            'world_id' => 'artist_studio_world',
            'excellent' => ['obra_apoyada_suelo_7_8', 'diagonal_estudio_moderno', 'detalle_textura_lienzo', 'borde_canvas_closeup'],
            'allowed' => ['pasillo_obra_descentrada_proxima', 'contrapicado_7_8', 'luz_dorada_sombra_diagonal', 'rasante_superficie_pintura', 'contrapicado_raton_puro'],
            'avoid' => ['nadir_extremo_arquitectonico'],
            'allow_camera_slots' => ['obra_apoyada_suelo_7_8', 'diagonal_estudio_moderno', 'detalle_textura_lienzo', 'borde_canvas_closeup', 'pasillo_obra_descentrada_proxima', 'contrapicado_7_8', 'luz_dorada_sombra_diagonal', 'rasante_superficie_pintura', 'contrapicado_raton_puro'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
            'decision_reasons' => [
                'contrapicado_raton_puro' => 'Allowed in studio context when floor proximity remains physically plausible and the artwork stays primary.',
            ],
        ],
        'domestic_collector_world' => [
            'world_id' => 'domestic_collector_world',
            'excellent' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal', 'borgona_recovecos_3_4_loft_hormigon'],
            'allowed' => ['pasillo_obra_descentrada_proxima', 'vista_aerea_contexto_ventanas', 'diagonal_estudio_moderno', 'contrapicado_7_8'],
            'avoid' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro'],
            'allow_camera_slots' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal', 'borgona_recovecos_3_4_loft_hormigon', 'pasillo_obra_descentrada_proxima', 'vista_aerea_contexto_ventanas', 'diagonal_estudio_moderno', 'contrapicado_7_8'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
            'decision_reasons' => [
                'contrapicado_7_8' => 'Allowed in collector room when domestic scale is preserved and artwork remains protagonist.',
            ],
        ],
        'premium_domestic_lifestyle_world' => [
            'world_id' => 'premium_domestic_lifestyle_world',
            'excellent' => ['luz_dorada_sombra_diagonal', 'reflejo_dorado_tarde_palazzo', 'vista_aerea_contexto_ventanas'],
            'allowed' => ['pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon', 'diagonal_estudio_moderno'],
            'avoid' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro'],
            'allow_camera_slots' => ['luz_dorada_sombra_diagonal', 'reflejo_dorado_tarde_palazzo', 'vista_aerea_contexto_ventanas', 'pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon', 'diagonal_estudio_moderno'],
            'deny_camera_slots' => [],
            'deny_reasons' => [],
        ],
        'experimental_monumental_world' => [
            'world_id' => 'experimental_monumental_world',
            'excellent' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro', 'vista_aerea_contexto_ventanas'],
            'allowed' => ['contrapicado_7_8'],
            'avoid' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal'],
            'allow_camera_slots' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro', 'vista_aerea_contexto_ventanas', 'contrapicado_7_8'],
            'deny_camera_slots' => ['detalle_textura_lienzo', 'borde_canvas_closeup', 'esquina_obra_perspectiva_extrema', 'rasante_superficie_pintura'],
            'deny_reasons' => ['macro/detail cameras do not express monumental spatial context', 'monumental world must stay physical architecture, not surreal atmosphere or simple enlarged minimal rooms'],
        ],
        'poetic_surreal_world' => [
            'world_id' => 'poetic_surreal_world',
            'excellent' => ['pasillo_obra_descentrada_proxima', 'vista_aerea_contexto_ventanas', 'borgona_recovecos_3_4_loft_hormigon'],
            'allowed' => ['luz_dorada_sombra_diagonal', 'diagonal_estudio_moderno', 'reflejo_dorado_tarde_palazzo'],
            'avoid' => ['nadir_extremo_arquitectonico', 'contrapicado_raton_puro'],
            'allow_camera_slots' => ['pasillo_obra_descentrada_proxima', 'vista_aerea_contexto_ventanas', 'borgona_recovecos_3_4_loft_hormigon', 'luz_dorada_sombra_diagonal', 'diagonal_estudio_moderno', 'reflejo_dorado_tarde_palazzo'],
            'deny_camera_slots' => ['detalle_textura_lienzo', 'borde_canvas_closeup', 'esquina_obra_perspectiva_extrema', 'rasante_superficie_pintura'],
            'deny_reasons' => ['macro/detail cameras risk turning poetic world into surface effect instead of context', 'poetic surreal world must avoid monumental halls, heroic museum scale, and brutalist atrium logic'],
        ],
        'pet_companion_world' => [
            'world_id' => 'pet_companion_world',
            'excellent' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal'],
            'allowed' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal', 'pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon'],
            'avoid' => ['diagonal_estudio_moderno', 'contrapicado_7_8'],
            'allow_camera_slots' => ['reflejo_dorado_tarde_palazzo', 'luz_dorada_sombra_diagonal', 'pasillo_obra_descentrada_proxima', 'borgona_recovecos_3_4_loft_hormigon'],
            'deny_camera_slots' => ['detalle_textura_lienzo', 'borde_canvas_closeup', 'esquina_obra_perspectiva_extrema', 'rasante_superficie_pintura', 'nadir_extremo_arquitectonico', 'contrapicado_raton_puro', 'vista_aerea_contexto_ventanas'],
            'deny_reasons' => ['macro/detail cameras cannot safely include animal presence', 'extreme nadir or floor-dominant cameras risk making the pet the protagonist', 'aerial/window-context cameras can turn the pet into a graphic floor element or secondary subject'],
        ],
    ],
];

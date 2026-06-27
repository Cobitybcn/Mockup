<?php
declare(strict_types=1);

return [
    'sets' => [
        'xl_default_v1' => [
            'set_id' => 'xl_default_v1',
            'label' => 'XL default camera archetypes v1',
            'description' => 'Initial curatorial camera archetype set for XL artworks using six sovereign camera roles.',
            'slots' => [
                1 => [
                    'camera_archetype_id' => 'king_rey',
                    'camera_archetype_name' => 'KING / REY',
                    'camera_archetype_reason' => 'Institutional presentation for an XL artwork: visual authority, stable composition, dignity, and restraint without casual camera energy.',
                    'camera_group' => 'king_noble_institutional',
                    'camera_view' => 'noble near-frontal institutional view, stable and dignified, with only subtle physical depth',
                    'camera_distance' => 'close or medium-close premium commercial presentation',
                    'camera_angle_notes' => 'KING / REY: a noble frontal-feeling room camera for institutional presentation and visual authority. The composition is stable, dignified, and calm; the artwork is presented with presence and respect, without excessive drama and without casual snapshot energy. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
                ],
                2 => [
                    'camera_archetype_id' => 'human_humano',
                    'camera_archetype_name' => 'HUMAN / HUMANO',
                    'camera_archetype_reason' => 'Natural collector-height view for judging XL scale as a real person would see it in a real room.',
                    'camera_group' => 'human_collector_eye_level',
                    'camera_view' => 'natural collector eye-level view, 150–170 cm camera height, soft human-scale oblique',
                    'camera_distance' => 'close or medium-close view',
                    'camera_angle_notes' => 'HUMAN / HUMANO: the camera looks like a real collector standing silently in the room at human eye height, approximately 150–170 cm. The view preserves credible XL scale and feels like direct observation in a real gallery or home, not a technical camera trick. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
                ],
                3 => [
                    'camera_archetype_id' => 'spy_espia',
                    'camera_archetype_name' => 'SPY / ESPIA',
                    'camera_archetype_reason' => 'Discovered partial view from architecture, creating desire and mystery while keeping the XL artwork identifiable.',
                    'camera_group' => 'spy_discovered_architectural_partial',
                    'camera_view' => 'partial discovered view from a doorway, corner, corridor, recess, architectural edge, or lightly obstructed foreground',
                    'camera_distance' => 'close or medium-close view',
                    'camera_angle_notes' => 'SPY / ESPIA: a partial discovered view, as if seen from a doorway, corner, corridor, recess, architectural edge, or restrained foreground obstruction. The image carries desire and mystery, and architecture may frame the artwork, but the artwork remains clearly identifiable. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent perspective.',
                ],
                4 => [
                    'camera_archetype_id' => 'god_dios',
                    'camera_archetype_name' => 'GOD / DIOS',
                    'camera_archetype_reason' => 'Calm omniscient high architectural view for understanding the whole room and the artwork relationship.',
                    'camera_group' => 'god_calm_omniscient_architectural',
                    'camera_view' => 'high architectural omniscient view, elevated and calm, close enough to feel interior rather than drone-like',
                    'camera_distance' => 'controlled architectural view',
                    'camera_angle_notes' => 'GOD / DIOS: a calm omniscient high architectural view that understands the complete room. The relationship between artwork, floor, furniture, human figure if present, and architecture is clear and serene. The camera is elevated but not a distant drone and not a cold technical survey. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must belong to the same controlled elevated perspective.',
                ],
                5 => [
                    'camera_archetype_id' => 'mouse_raton',
                    'camera_archetype_name' => 'MOUSE / RATÓN',
                    'camera_archetype_reason' => 'True floor-level mouse view for an imposing XL artwork, controlled so the artwork remains physically correct.',
                    'camera_group' => 'mouse_floor_level_contrapicado',
                    'camera_view' => 'mouse-height camera 10–25 cm from the floor, true low-angle / contrapicado, floor-dominant foreground',
                    'camera_distance' => 'medium-close view',
                    'camera_angle_notes' => 'MOUSE / RATÓN: the camera sits 10–25 cm from the floor, like a mouse looking upward. The floor dominates the foreground, architecture rises above, and the XL artwork feels large, physical, and imposing. Keep the view controlled: no fisheye, no warped artwork, and no scale distortion. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent upward contrapicado perspective.',
                ],
                6 => [
                    'camera_archetype_id' => 'fly_mosca',
                    'camera_archetype_name' => 'FLY / MOSCA',
                    'camera_archetype_reason' => 'Close aerial oblique curiosity from an upper room corner, intimate and strange without becoming a distant drone view.',
                    'camera_group' => 'fly_close_aerial_oblique',
                    'camera_view' => 'close aerial oblique view from near an upper room corner, curious diagonal interior perspective, not a distant drone',
                    'camera_distance' => 'controlled wide architectural view',
                    'camera_angle_notes' => 'FLY / MOSCA: a close aerial oblique camera, like a fly hovering near a high corner of the room. The view is intimate, curious, nearby, and diagonal, never a distant drone shot. It should reveal floor geometry and the artwork plane with controlled artistic strangeness. The artwork plane, wall plane, floor lines, furniture perspective, and architectural vanishing direction must share one coherent oblique perspective with clean vanishing lines.',
                ],
            ],
        ],
    ],
];

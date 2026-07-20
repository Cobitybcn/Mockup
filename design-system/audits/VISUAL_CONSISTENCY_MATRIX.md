# VISUAL CONSISTENCY MATRIX

| Screen | Route | Status | Main issue | Severity | Recommended pattern | CSS-only possible | Markup required | Priority |
|---|---|---|---|---|---|---|---|---|
| Global navigation | `sidebar.php` | PASS | Sin divergencia material respecto de la referencia | — | Navegación de `scene-creation` | N/A | No | P3 |
| Create Art | `create_scenes.php` | MINOR INCONSISTENCIES | Acción principal rectangular/oscura | MEDIUM | Primary Action + Upload Area | Partial | No para primera pasada | P1 |
| Explore Scenes | `mockup_combinations_review.php` | PASS | Implementación canónica | — | Referencia `scene-creation` | N/A | No | P3 |
| Scene Mockups | `mockup_combination_results.php` | PASS | Sin divergencia material | — | Thumbnail Card + Glass Actions | N/A | No | P3 |
| Mockup Lab | `mockup_variation_lab.php` | NEEDS CONSISTENCY PASS | Toolbars permanentes y Primary Action genérica | MEDIUM | Workspace Panel + Primary Action | Partial | Sí | P1 |
| Series | `series.php` | MINOR INCONSISTENCIES | Cabecera comprimida y título repetido | LOW | Header + Decision Blocks | Yes | Solo para redundancia | P2 |
| ArtWorks | `root_album.php` | NEEDS CONSISTENCY PASS | KPI strip, thumbnails pequeños y metadata excesiva | HIGH | Counter subordinado + Thumbnail Card | No | Sí | P0 |
| Mockup Album | `mockups.php` | MINOR INCONSISTENCIES | Acciones principales genéricas | MEDIUM | Primary Action + Toolbar | Yes | No | P2 |
| Videos | `videos.php` | MINOR INCONSISTENCIES | Decisiones como botones rectangulares | MEDIUM | Decision Block + Header | Yes | No | P2 |
| Website Catalog Sync | `website_board.php` | MINOR INCONSISTENCIES | Título `h2` y acciones locales no convergentes | LOW | Header + Workspace Panel | Partial | Sí para heading | P2 |
| Social Media Board | `social_media_board.php` | MINOR INCONSISTENCIES | Tres boards de igual peso y texto pequeño | MEDIUM | Visual Assignment + Header | Partial | Sí para heading | P1 |
| Artist Profile | `artist_profile.php` | NEEDS CONSISTENCY PASS | Formularios estrechos, texto pequeño, exceso visible | MEDIUM | Workspace Panel vertical + UI Preferences | Partial | Sí | P1 |
| Scene Studio | `world_mother_studio.php` | NEEDS CONSISTENCY PASS | Biblioteca densa con mini-referencias | MEDIUM | Thumbnail Card + Glass Actions | Yes en densidad | Partial | P1 |
| Camera Boards | `camera_studio.php` | DO NOT TOUCH | Workbench administrativo denso sin referencia específica | HIGH | Visual Assignment, sujeto a aprobación | No | Sí | P3 |
| Video Lab | `video.php` | NEEDS CONSISTENCY PASS | Acciones competidoras, barra Add Sequence y módulos horizontales | MEDIUM | Decision Block + flujo vertical | Partial | Sí | P1 |
| Account | `account.php` | DO NOT TOUCH | KPI y módulos de plan tipo dashboard | HIGH | Requiere patrón específico de cuenta | No | Sí | P3 |
| Feature access gate | `FeatureAccess.php` | NEEDS CONSISTENCY PASS | Pantalla sin shell ni lenguaje visual | HIGH | Shell global + Workspace Header | No | Sí | P1 |

## Totales

- Pantallas/superficies auditadas: 18.
- PASS: 4.
- MINOR INCONSISTENCIES: 6.
- NEEDS CONSISTENCY PASS: 6.
- DO NOT TOUCH: 2.


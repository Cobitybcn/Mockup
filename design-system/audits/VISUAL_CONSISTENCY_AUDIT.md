# VISUAL CONSISTENCY AUDIT

Fecha: 2026-07-19

## Resumen ejecutivo

La interfaz conserva una identidad reconocible y coherente en sus flujos visuales más importantes. `Explore Scenes`, `Scene Mockups`, la navegación compartida y `Scene Studio` son las implementaciones más cercanas a la constitución visual aprobada. En ellas se mantiene la jerarquía editorial, el protagonismo de las imágenes, la navegación horizontal dentro de un flujo vertical, las acciones contextuales y el color pastel con función semántica.

La auditoría encontró 18 superficies principales:

- 4 `PASS`.
- 6 `MINOR INCONSISTENCIES`.
- 6 `NEEDS CONSISTENCY PASS`.
- 2 `DO NOT TOUCH`.

No se utilizaron porcentajes. Cada clasificación se apoya en una regla escrita, un Master Pattern o la referencia aprobada `design-system/references/scene-creation/screenshot.png`.

Las dos divergencias más claras son:

1. `ArtWorks` abre con cuatro módulos de contadores de igual peso y después muestra fichas con metadata abundante. El resultado se acerca a un dashboard KPI, patrón expresamente prohibido.
2. Las acciones principales no convergen todavía en un único lenguaje: conviven Decision Blocks aprobados con botones rectangulares genéricos, barras de ancho completo y grupos de acciones primarias equivalentes.

También requieren atención controlada `Mockup Lab`, por la cantidad de controles permanentes alrededor del lienzo; `Artist Profile`, por sus áreas de escritura estrechas y texto pequeño; `Video Lab`, por competir varias decisiones y barras de acción; `Scene Studio`, por la densidad de su biblioteca; y las pantallas de acceso denegado, que abandonan por completo el shell visual del producto.

## Alcance y método

Se revisaron:

- la constitución visual completa y los diez Master Patterns;
- la captura y notas de la referencia `scene-creation`;
- la interfaz local autenticada con una cuenta Artist Pro, en modo lectura;
- el HTML, PHP y CSS vigente de las pantallas que exigen ADMIN;
- rutas, navegación, cabeceras, paneles, tarjetas, thumbnails, acciones sobre imágenes, drop zones, estados, filtros, badges, counters, carruseles y áreas de trabajo.

No se enviaron formularios, no se activaron generaciones, no se arrastraron elementos y no se modificaron datos. `Scene Studio` y `Camera Boards` no estaban disponibles en la sesión Artist Pro; su evaluación se basa en la implementación vigente. La pantalla de acceso restringido sí se verificó renderizada. Esta limitación aumenta el riesgo de cualquier futura corrección en esas dos superficies.

## Conclusiones generales

- El lenguaje visual está más consolidado en los flujos donde la imagen es material de trabajo directo.
- Las bibliotecas recientes reutilizan correctamente thumbnails rectangulares y acciones circulares sobre imagen.
- Los mayores desvíos aparecen cuando una pantalla acumula administración, formularios o utilidades: resurgen KPI cards, toolbars, tipografía demasiado pequeña y botones SaaS.
- El color pastel se utiliza de forma mayormente semántica; no se detectó una deriva general hacia colores saturados.
- El sistema comparte `media-controls.css` en varias bibliotecas, pero aún existen acciones equivalentes con implementaciones locales.
- Los títulos principales oscilan entre aproximadamente 31, 32, 38, 44 y 46 px y, en algunos tableros, el título visible es un `h2`. La diferencia solo se considera inconsistencia cuando debilita el rol editorial, no por el valor numérico en sí.
- Los radios, sombras y paddings todavía dependen de CSS local. La diversidad es visible, pero solo merece convergencia cuando componentes con el mismo rol se ven distintos.

## Auditoría por pantalla

### 1. Global navigation

- **Screen:** Global navigation.
- **Route or file:** `platform/sidebar.php`, `platform/style.css`.
- **Status:** PASS.
- **Patterns correctly applied:** Marca editorial, navegación discreta, separación semántica entre creación, biblioteca, publicación y estudios, estados activos suaves y panel ADMIN plegado por defecto.
- **Inconsistencies detected:** Ninguna inconsistencia visual suficiente para justificar una corrección. La densidad horizontal es alta, pero coincide con la referencia aprobada y no debe reinterpretarse sin una decisión específica.
- **Severity:** Ninguna.
- **Recommended existing pattern to reuse:** La propia navegación de la referencia `scene-creation`.
- **Files likely involved:** `platform/sidebar.php`, `platform/style.css`.
- **Risk level:** Alto, por alcance transversal.
- **CSS-only possible:** No aplica.
- **Markup changes required:** No.
- **Postpone:** Sí; no intervenir sin una tarea específica de navegación.

### 2. Create Art

- **Screen:** Create Art.
- **Route or file:** `platform/create_scenes.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Cabecera editorial de 46 px, flujo vertical, gran área de carga, campos dimensionales subordinados, espacio generoso y paneles secundarios plegados.
- **Inconsistencies detected:** **MEDIUM:** la acción de compromiso se expresa mediante botón rectangular oscuro o botón convencional en lugar del Primary Action pastel del sistema. **LOW:** varios controles de captura y orientación concentran más peso visual que el necesario antes de existir material.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Primary Action / Decision Block de `Explore Scenes`; Upload Area de `Scene Studio`.
- **Files likely involved:** `platform/create_scenes.php`, `platform/style.css`.
- **Risk level:** Medio, porque la captura tiene variantes desktop y mobile.
- **CSS-only possible:** Parcial.
- **Markup changes required:** No para el ajuste visual básico; sí solo si se elimina duplicación real entre variantes.
- **Postpone:** No, pero debe entrar después de normalizar el patrón transversal de Primary Action.

### 3. Explore Scenes / Scene Composer

- **Screen:** Explore Scenes.
- **Route or file:** `platform/mockup_combinations_review.php?id={artwork_id}&board={board}`.
- **Status:** PASS.
- **Patterns correctly applied:** Es la implementación más cercana a la referencia aprobada: título editorial de 44 px, decisión cuadrada rosa, carrusel horizontal, elección de camera board, paneles amplios, combinación visual y acción local.
- **Inconsistencies detected:** No se detectó una divergencia material. Los múltiples `details` abiertos pertenecen a la composición visible de cámaras y no contradicen por sí solos la referencia.
- **Severity:** Ninguna.
- **Recommended existing pattern to reuse:** `design-system/references/scene-creation/`.
- **Files likely involved:** `platform/mockup_combinations_review.php`, `platform/style.css`.
- **Risk level:** Alto, por ser patrón canónico y flujo productivo.
- **CSS-only possible:** No aplica.
- **Markup changes required:** No.
- **Postpone:** Sí; usar como referencia, no como objetivo de corrección.

### 4. Art Mockups / Scene Mockups

- **Screen:** Scene Mockups results.
- **Route or file:** `platform/mockup_combination_results.php?id={artwork_id}`.
- **Status:** PASS.
- **Patterns correctly applied:** Imágenes grandes y rectangulares, grid de comparación, acciones circulares sobre la imagen, favorito a la izquierda, acciones secundarias a la derecha, título editorial y dos decisiones cuadradas pastel.
- **Inconsistencies detected:** No se detectó una inconsistencia material en la vista observada.
- **Severity:** Ninguna.
- **Recommended existing pattern to reuse:** Thumbnail Card + Glass Actions de esta misma pantalla.
- **Files likely involved:** `platform/mockup_combination_results.php`, `platform/style.css`, `platform/media-controls.css`.
- **Risk level:** Alto, por acciones destructivas y regeneración.
- **CSS-only possible:** No aplica.
- **Markup changes required:** No.
- **Postpone:** Sí; conservar como referencia de resultados visuales.

### 5. Mockup Lab

- **Screen:** Mockup Lab.
- **Route or file:** `platform/mockup_variation_lab.php?id={artwork_id}`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Lienzo dominante, resultado cercano a la acción, controles de escala y luz junto a la imagen, historial visual y configuración secundaria plegable.
- **Inconsistencies detected:** **MEDIUM:** varias barras y pilas de controles permanecen visibles alrededor del lienzo y compiten con la imagen. **MEDIUM:** `Apply Changes` usa una barra rectangular de ancho completo en lugar del Primary Action aprobado. **LOW:** títulos secundarios llegan a tamaños muy pequeños y pierden jerarquía editorial.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Workspace Panel y Primary Action de `Explore Scenes`; Glass Actions para utilidades locales.
- **Files likely involved:** `platform/mockup_variation_lab.php`, `platform/style.css`, `platform/media-controls.css`.
- **Risk level:** Alto, por la coexistencia de interfaces desktop y mobile.
- **CSS-only possible:** Parcial.
- **Markup changes required:** Sí, si se reducen controles duplicados o se reagrupan utilidades.
- **Postpone:** No; requiere una pasada acotada y pruebas por breakpoint.

### 6. Series

- **Screen:** Series.
- **Route or file:** `platform/series.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Decision Blocks cuadrados y pastel para series, bloque `+` para crear, thumbnails rectangulares para obras, asignación directa y color de serie con función organizativa.
- **Inconsistencies detected:** **LOW:** la cabecera principal es más pequeña y apretada que la jerarquía editorial canónica. **LOW:** el título `Series` se repite inmediatamente dentro del primer panel.
- **Severity:** LOW.
- **Recommended existing pattern to reuse:** Header de `Explore Scenes`; Decision Blocks de la propia pantalla.
- **Files likely involved:** `platform/series.php`, `platform/ui-catalog.css`, `platform/series_artwork_order.js`.
- **Risk level:** Bajo.
- **CSS-only possible:** Sí para jerarquía y espaciado.
- **Markup changes required:** Solo para eliminar el título redundante.
- **Postpone:** Sí; P2.

### 7. ArtWorks

- **Screen:** ArtWorks.
- **Route or file:** `platform/root_album.php`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Título editorial, filtro de serie, thumbnails verticales, acciones sobre imagen y colección en una superficie amplia.
- **Inconsistencies detected:** **HIGH:** cuatro módulos `Artworks / Mockups / Variants / Credits` funcionan como una franja KPI de dashboard. **MEDIUM:** las fichas repiten título, serie, group id, artwork id, dimensiones, número de vistas y mockups, restando protagonismo a la obra. **MEDIUM:** las miniaturas quedan pequeñas frente al ancho disponible. **LOW:** `Create Art` aparece como botón convencional.
- **Severity:** HIGH.
- **Recommended existing pattern to reuse:** Thumbnail Card y Counter subordinado de `Scene Mockups`; carrusel o rail de `Series` si la colección necesita continuidad horizontal.
- **Files likely involved:** `platform/root_album.php`, `platform/ui-catalog.css`, `platform/media-controls.css`, `platform/series_artwork_order.js`.
- **Risk level:** Alto, por filtros, ordenamiento y acciones de fusión/eliminación.
- **CSS-only possible:** No.
- **Markup changes required:** Sí, para retirar la estructura KPI y reducir metadata repetida.
- **Postpone:** No; P0.

### 8. Mockup Album

- **Screen:** Mockup Album.
- **Route or file:** `platform/mockups.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Cabecera pastel, archivo dominado por imágenes rectangulares grandes, favoritos, acciones sobre imagen, buscador simple y agrupación visual.
- **Inconsistencies detected:** **MEDIUM:** `Import Mockups` y `Prepare selected mockups` usan estilos de botón genérico distintos del Primary Action. **LOW:** la acción `Search` adquiere más presencia que la utilidad que representa.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Primary Action de `Explore Scenes`; Toolbar compacta y subordinada del catálogo.
- **Files likely involved:** `platform/mockups.php`, `platform/ui-catalog.css`, `platform/media-controls.css`, `platform/mockup_upload.css`.
- **Risk level:** Medio.
- **CSS-only possible:** Sí para jerarquía visual; no si se elimina duplicación funcional.
- **Markup changes required:** No para la primera pasada.
- **Postpone:** Sí; P2 después del patrón transversal.

### 9. Videos

- **Screen:** Videos.
- **Route or file:** `platform/videos.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Thumbnails verticales grandes, controles de reproducción y acciones sobre medios, navegación horizontal, paneles amplios y texto secundario reducido.
- **Inconsistencies detected:** **MEDIUM:** `Open Video Lab` y `Upload final video` son botones rectangulares convencionales para decisiones importantes. **LOW:** los títulos mezclan Cormorant y Georgia para roles equivalentes.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Decision Block / Primary Action de `Explore Scenes`; Header de `Scene Mockups`.
- **Files likely involved:** `platform/videos.php`, `platform/videos.css`, `platform/ui-catalog.css`, `platform/media-controls.css`.
- **Risk level:** Medio.
- **CSS-only possible:** Sí para la primera convergencia.
- **Markup changes required:** No necesariamente.
- **Postpone:** Sí; P2.

### 10. Website Catalog Sync

- **Screen:** Website Catalog Sync.
- **Route or file:** `platform/website_board.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Catálogo horizontal, thumbnails de escala útil, colores de identidad, paneles de destino visibles y drag and drop directo.
- **Inconsistencies detected:** **LOW:** el título principal visible usa `h2` y una escala menor a la cabecera editorial canónica. **LOW:** controles de edición locales (`Save`, `Publish`, `Remove`) no convergen completamente con los estilos compartidos. **LOW:** `Catalog` y `Studio Notes` aparecen como dos paneles equivalentes antes de que el flujo vertical quede claro.
- **Severity:** LOW.
- **Recommended existing pattern to reuse:** Header de `Explore Scenes`; Workspace Panel y Drop Zone de `Scene Studio`.
- **Files likely involved:** `platform/website_board.php`, `platform/website_board.css`, `platform/social_media_board.css`, `platform/website_board.js`.
- **Risk level:** Medio.
- **CSS-only possible:** Parcial.
- **Markup changes required:** Sí para corregir la semántica del encabezado; no para espaciado y controles.
- **Postpone:** Sí; P2.

### 11. Social Media Board

- **Screen:** Social Media Board.
- **Route or file:** `platform/social_media_board.php`.
- **Status:** MINOR INCONSISTENCIES.
- **Patterns correctly applied:** Catálogo horizontal, favoritos sobre imágenes, tres destinos visibles, color de canal con significado, asignación espacial y estados próximos al tablero.
- **Inconsistencies detected:** **LOW:** el título principal visible usa `h2` y una escala menor a la cabecera editorial. **MEDIUM:** Pinterest, Instagram y Facebook quedan como tres módulos de igual peso y se aproximan visualmente a un dashboard, aunque su función de destinos justifica mantenerlos como boards. **LOW:** varios labels y counters son demasiado pequeños.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Workspace Panel + Visual Assignment de `Scene Studio`; Header de `Explore Scenes`.
- **Files likely involved:** `platform/social_media_board.php`, `platform/social_media_board.css`, `platform/social_media_board.js`, `platform/media-controls.css`.
- **Risk level:** Medio-alto por publicación y responsive.
- **CSS-only possible:** Parcial.
- **Markup changes required:** Sí para la semántica del encabezado; no para aumentar legibilidad.
- **Postpone:** No es P0; abordar junto con Website para evitar dos soluciones diferentes.

### 12. Artist Profile

- **Screen:** Artist Profile.
- **Route or file:** `platform/artist_profile.php`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Cabecera editorial, superficie clara, secciones con títulos serif y color contenido.
- **Inconsistencies detected:** **MEDIUM:** tres columnas de formularios crean áreas de escritura estrechas y fuerzan scroll interno. **MEDIUM:** gran cantidad de campos y ayuda permanente compite con la lectura. **MEDIUM:** tipografía operativa demasiado pequeña para escritura prolongada. **LOW:** las secciones secundarias permanecen abiertas en lugar de plegarse cuando no son necesarias.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Workspace Panel vertical; preferencias de Forms y Panels de `UI_PREFERENCES.md`.
- **Files likely involved:** `platform/artist_profile.php`, `platform/style.css`.
- **Risk level:** Medio, por persistencia de datos de perfil.
- **CSS-only possible:** Parcial; el apilado vertical y tamaño de texto sí.
- **Markup changes required:** Sí para plegar secciones o reducir ayudas duplicadas.
- **Postpone:** No; P1.

### 13. Scene Studio

- **Screen:** Scene Studio.
- **Route or file:** `platform/world_mother_studio.php`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Cabecera editorial de 44 px, acción de creación con presencia, panel creador aislado, drop zone, paneles plegables y paleta aprobada.
- **Inconsistencies detected:** **MEDIUM:** la biblioteca usa hasta cinco cards por fila y cada card contiene tres thumbnails 4:3 pequeños, reduciendo las referencias a iconos. **MEDIUM:** cards administrativas acumulan metadata, badges y formularios, aunque varios estén plegados. **LOW:** persisten sombras y una acción circular con efectos más fuertes que el Glass Action aprobado.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Thumbnail Card, Workspace Panel y Glass Actions de `Scene Mockups`; drop zone de `Scene Studio`.
- **Files likely involved:** `platform/world_mother_studio.php`, `platform/ui-catalog.css`, `platform/style.css`.
- **Risk level:** Alto; no hubo validación visual ADMIN en esta auditoría.
- **CSS-only possible:** Sí para densidad, escala y efectos; parcial para simplificar metadata.
- **Markup changes required:** Solo si la reducción de metadata no puede resolverse mediante panels plegables existentes.
- **Postpone:** No para la densidad; cualquier cambio estructural debe esperar validación ADMIN.

### 14. Camera Boards

- **Screen:** Camera Boards.
- **Route or file:** `platform/camera_studio.php`, `platform/camera_studio.css`, `platform/camera_studio.js`.
- **Status:** DO NOT TOUCH.
- **Patterns correctly applied:** Destinos visibles, drag and drop, estado de drop, paneles secundarios y edición técnica plegable.
- **Inconsistencies detected:** **HIGH:** workbench de dos columnas, toolbar administrativa, tres boards simultáneos, celdas de 82 px, tokens con texto de 9–11 px y acciones sticky producen una interfaz densa y cercana a un panel administrativo. No existe una referencia visual aprobada específica para esta particularidad funcional.
- **Severity:** HIGH.
- **Recommended existing pattern to reuse:** Board + Visual Assignment de `Scene Studio`, pero solo después de aprobar una referencia específica para cámaras.
- **Files likely involved:** `platform/camera_studio.php`, `platform/camera_studio.css`, `platform/camera_studio.js`.
- **Risk level:** Alto; sin acceso ADMIN renderizado y con interacción compleja.
- **CSS-only possible:** No para una corrección completa.
- **Markup changes required:** Sí.
- **Postpone:** Sí; P3 hasta contar con decisión y referencia específicas.

### 15. Video Lab

- **Screen:** Video Lab.
- **Route or file:** `platform/video.php`, `platform/video_studio.css`, `platform/video_studio.js`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Catálogo horizontal, thumbnails rectangulares, favoritos sobre imagen, color pastel, secuencias como zonas de trabajo y resultado próximo a la generación.
- **Inconsistencies detected:** **MEDIUM:** Guardar, Nuevo y Eliminar se presentan como tres decisiones cuadradas equivalentes y compiten entre sí. **MEDIUM:** `Agregar secuencia` es una barra horizontal de ancho completo pese a representar creación. **MEDIUM:** tres secuencias se muestran como módulos horizontales equivalentes con controles diminutos. **LOW:** abundan labels de 9–11 px.
- **Severity:** MEDIUM.
- **Recommended existing pattern to reuse:** Decision Block de `Series` para `Nueva secuencia`; una única Primary Action por etapa; Workspace Panel vertical de `Explore Scenes`.
- **Files likely involved:** `platform/video.php`, `platform/video_studio.css`, `platform/video_studio.js`, `platform/media-controls.css`.
- **Risk level:** Alto, por ordenamiento, guardado y generación.
- **CSS-only possible:** Parcial.
- **Markup changes required:** Sí para separar utilidades de decisiones y reforzar el flujo vertical.
- **Postpone:** No; P1 con feature preview aislada.

### 16. Account

- **Screen:** Account.
- **Route or file:** `platform/account.php`.
- **Status:** DO NOT TOUCH.
- **Patterns correctly applied:** Cabecera editorial, paneles claros, plan details plegado y colores de disponibilidad suaves.
- **Inconsistencies detected:** **HIGH:** franja KPI de créditos, artworks, scene mockups y variants. **MEDIUM:** tres módulos de disponibilidad de plan con lógica de dashboard SaaS. La pantalla es administrativa y no dispone de una referencia específica.
- **Severity:** HIGH.
- **Recommended existing pattern to reuse:** No aplicar automáticamente un patrón creativo. Requiere una decisión específica para superficies de cuenta.
- **Files likely involved:** `platform/account.php`, `platform/style.css`.
- **Risk level:** Alto por plan, contraseña y pagos.
- **CSS-only possible:** No para una corrección completa.
- **Markup changes required:** Sí.
- **Postpone:** Sí; P3.

### 17. Feature access gate

- **Screen:** Access denied / upgrade required.
- **Route or file:** Salida compartida desde `platform/app/Support/FeatureAccess.php`; observada en `world_mother_studio.php` y `camera_studio.php`.
- **Status:** NEEDS CONSISTENCY PASS.
- **Patterns correctly applied:** Mensaje breve y ausencia de acciones innecesarias.
- **Inconsistencies detected:** **HIGH:** la pantalla abandona navegación, marca, tipografía editorial, paleta, paneles y espaciado del producto. Parece una respuesta técnica ajena a Artwork Mockups.
- **Severity:** HIGH.
- **Recommended existing pattern to reuse:** Shell global + Workspace Header + panel vacío de bajo ruido.
- **Files likely involved:** `platform/app/Support/FeatureAccess.php`, `platform/style.css`, posiblemente una vista compartida de acceso.
- **Risk level:** Medio; lógica de autorización no debe mezclarse con la presentación.
- **CSS-only possible:** No.
- **Markup changes required:** Sí, mediante una vista compartida; sin alterar reglas de acceso.
- **Postpone:** No; P1.

## Inconsistencias transversales

### Primary Actions múltiples

- **Dónde aparece:** Create Art, Mockup Lab, Mockup Album, Videos, Website Catalog Sync y Video Lab.
- **Referencia aprobada:** Decision Block / Primary Action de `Explore Scenes`; Decision Blocks de `Series`.
- **Implementación correcta:** bloque cuadrado o fuertemente cuadrado, pastel, etiqueta corta y un compromiso por etapa.
- **Deben converger:** botones oscuros de captura, barras `Apply Changes` y `Add sequence`, botones marrones de navegación/importación y grupos de varias acciones equivalentes.

### KPI y módulos de dashboard

- **Dónde aparece:** ArtWorks y Account.
- **Referencia aprobada:** Counter compacto y subordinado junto a la colección; nunca como métrica dominante.
- **Implementación correcta:** counters pequeños de Scene Mockups y cabeceras de boards.
- **Deben converger:** franjas de cuatro cards métricas. En Account la convergencia queda postergada por riesgo y falta de referencia específica.

### Glass Actions fragmentadas

- **Dónde aparece:** Scene Mockups, ArtWorks, Mockup Album, Videos, Social Media Board, Video Lab y Scene Studio.
- **Referencia aprobada:** `platform/media-controls.css` y la disposición de Scene Mockups.
- **Implementación correcta:** favorito arriba-izquierda; acciones secundarias arriba-derecha; círculo frosted discreto, icono SVG y nombre accesible.
- **Deben converger:** acciones locales con tamaños, blur, sombras o posiciones propias; el botón circular decorado de Scene Studio requiere especial atención.

### Cabeceras editoriales no equivalentes

- **Dónde aparece:** títulos de 31–32 px en Videos, Website, Social y Video Lab; 38 px en catálogos; 44–46 px en flujos canónicos. Website y Social usan `h2` como título principal.
- **Referencia aprobada:** Workspace Header de `Explore Scenes`.
- **Implementación correcta:** título serif claramente principal, descripción breve, espacio generoso y utilities subordinadas.
- **Deben converger:** títulos principales semánticamente secundarios y cabeceras comprimidas. No se propone fijar un único tamaño global.

### Densidad y texto operativo pequeño

- **Dónde aparece:** Artist Profile, Camera Boards, Video Lab, Social Media Board y Scene Studio.
- **Referencia aprobada:** tipografía operativa discreta pero cómoda; `UI_PREFERENCES.md` para lectura y escritura prolongadas.
- **Implementación correcta:** texto de apoyo legible y poco abundante; panels secundarios plegados.
- **Deben converger:** labels de 8–11 px, metadata repetida y textareas estrechos.

### Thumbnails de escala desigual

- **Dónde aparece:** grandes y correctos en Scene Mockups y Mockup Album; más pequeños en ArtWorks, Camera Boards y la biblioteca de Scene Studio.
- **Referencia aprobada:** Thumbnail Card rectangular de Scene Mockups.
- **Implementación correcta:** la imagen ocupa la mayor parte de una ficha y permite evaluación visual.
- **Deben converger:** cards de ArtWorks y grupos de tres mini-referencias dentro de cada Scene card. Camera Boards queda postergado.

### Radios, sombras y padding locales

- **Dónde aparece:** radios de 4 a 13 px, pills de 999 px y varias sombras locales en Scene Studio, Camera Boards y catálogos.
- **Referencia aprobada:** bordes finos, superficies claras y sombra mínima de la referencia `scene-creation`.
- **Implementación correcta:** tokens compartidos de `style.css` cuando el rol del componente es equivalente.
- **Deben converger:** solo los componentes semánticamente iguales. No normalizar pills o estados que cumplen otra función.

### Drop zones y estados vacíos

- **Dónde aparece:** Create Art, Website, Social y Scene Studio.
- **Referencia aprobada:** biblioteca/drop zone de Scene Studio.
- **Implementación correcta:** destino visible antes del drag, estado suave, layout estable y resultado en el mismo panel.
- **Deben converger:** mensajes, borde y feedback de destinos equivalentes; no convertir Upload Area en formulario permanente.

## Prioridades

### P0 — corregir antes de ampliar el sistema

1. Retirar la franja KPI y reducir metadata redundante de ArtWorks usando Counter + Thumbnail Card existentes.
2. Definir una única ruta de convergencia para Primary Actions, sin tocar todavía cada pantalla por separado: Decision Block para compromisos y Toolbar ligera para utilidades.

### P1 — corrección importante

1. Mockup Lab: reducir toolbars permanentes y hacer que la acción principal reutilice el patrón aprobado.
2. Artist Profile: áreas de escritura amplias, tipografía cómoda y panels secundarios plegables.
3. Video Lab: separar utilidades de decisiones, una acción principal por etapa y flujo vertical de secuencias.
4. Scene Studio: aumentar escala visual de referencias y reducir densidad de cards.
5. Feature access gate: reutilizar el shell visual sin alterar la autorización.
6. Unificar semántica y jerarquía de cabeceras en Website y Social.

### P2 — refinamiento

1. Ajustar cabecera y título redundante de Series.
2. Converger botones de Mockup Album y Videos después de definir Primary Action.
3. Normalizar badges, counters, radios, sombras y padding solo por rol equivalente.

### P3 — no intervenir ahora

1. Camera Boards hasta contar con referencia y validación ADMIN.
2. Account hasta definir un patrón visual específico para superficies de cuenta.
3. Global navigation, Scene Composer, Scene Mockups y Scene Studio: conservar como referencias.

## Riesgos

- Cambiar componentes compartidos sin scope de preview podría alterar muchas pantallas simultáneamente.
- Ocultar metadata en ArtWorks puede afectar fusión, selección, filtros u ordenamiento si se elimina markup funcional en vez de cambiar su jerarquía.
- Mockup Lab y Video Lab tienen implementaciones desktop/mobile y estados de generación; requieren verificación por breakpoint y estado.
- Las superficies ADMIN no fueron renderizadas con una sesión autorizada en esta auditoría.
- Reestilizar estados de acceso no debe suavizar ni alterar la lógica de autorización.
- Normalizar todos los valores CSS por búsqueda y reemplazo rompería diferencias semánticas válidas.

## Recomendaciones

1. Implementar primero una preview ADMIN reversible según `PREVIEW_IMPLEMENTATION_PLAN.md`.
2. Comenzar por dos scopes independientes: `artworks-kpi` y `primary-actions`.
3. Comparar cada scope contra `Explore Scenes`, `Scene Mockups`, `Series` y `Scene Studio`; no crear componentes nuevos.
4. Validar visualmente desktop y mobile con datos reales antes de ampliar el alcance.
5. Mantener Camera Boards y Account fuera de la primera preview.


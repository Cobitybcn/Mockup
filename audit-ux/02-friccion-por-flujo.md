# Reporte de Fricción por Flujo de Usuario

Este documento detalla los puntos de fricción, bloqueos y problemas de experiencia de usuario (UX) identificados en cada uno de los 6 flujos definidos en el alcance del relevamiento.

---

## Flujo 1: Onboarding y Primera Carga de una Obra

Esta etapa abarca desde que el artista ingresa a subir su obra hasta que obtiene las imágenes candidatas listas para ser aisladas.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Carga de archivo** (`artwork_new.php`) | **Sobrecarga de opciones**: Se presentan dos formularios gigantes apilados verticalmente con propósitos similares (Opción AI vs Opción Bypass). Esto confunde al artista nuevo sobre cuál camino tomar, requiriendo que entienda términos técnicos como "Imagen 3 pipeline" o "Bypass mode". | [Ver Carga](screenshots/03-artwork_new.png) | **Media** |
| **Espera de procesamiento** (`waiting.php`) | **Bloqueo de navegación (User Trapped)**: Durante el procesamiento en segundo plano, la pantalla no ofrece ningún botón de "Cancelar" o "Volver". Si el artista se equivoca al ingresar los datos o el archivo de imagen, queda atrapado sin poder abortar el proceso a menos que recurra al menú del navegador. | [Ver Espera](screenshots/04-waiting.png) | **Alta** |
| **Espera de procesamiento** (`waiting.php`) | **Ruido administrativo en la interfaz**: Para usuarios administradores, se renderiza una barra lateral completa mostrando los System Prompts crudos de Imagen 3. Esto introduce una cantidad masiva de texto técnico en una pantalla de espera que debería ser limpia y enfocada al artista. | [Ver Espera](screenshots/04-waiting.png) | **Media** |

---

## Flujo 2: Fase 1 — Fotografía Raíz de la Obra (Perspectivas)

Involucra la validación de las tomas frontal, oblicua izquierda y oblicua derecha necesarias para los mockups tridimensionales.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Selección de root** (`root_select.php`) | **Falta de retorno**: Al visualizar las candidatas generadas, si ninguna es satisfactoria, no existe un control de "Regenerar" o "Subir otra foto" dentro del área de trabajo. El usuario debe abandonar el flujo usando la barra lateral. | [Ver Candidatas](screenshots/05-root_select.png) | **Media** |
| **Revisión del core** (`core_review.php`) | **Campos huérfanos sin acción**: Se muestran los bloques para las tomas "Three-quarter Left" y "Three-quarter Right" con estados rojos de "Missing" (Faltante), pero **no existe ningún botón o mecanismo para subir estas perspectivas**. El artista ve que falta algo crítico, pero el sistema no le permite resolverlo. | [Ver Core](screenshots/07-core_review.png) | **Alta** |

---

## Flujo 3: Generación de Mockups (Scene + Cameras)

Involucra la selección de escenas arquitectónicas, ángulos de cámara y la cola de renderizado.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Selección de cámaras** (`mockup_combinations_review.php`) | **Terminología técnica expuesta (Slugs)**: Los nombres de los ángulos de cámara disponibles para selección se muestran al artista como nombres técnicos de bases de datos/archivos (ej. `corte-agresivo-de-esquina-de-obra-loft` o `borde-de-canvas-close-up-loft`) en lugar de etiquetas claras y artísticas (ej. "Primer plano esquina" o "Vista oblicua superior"). | [Ver Mockup Lab](screenshots/08-mockup_combinations_review.png) | **Alta** |
| **Navegación / Acciones** (`mockup_combinations_review.php`) | **Problema crítico de legibilidad (Micro-botones)**: Los botones de acción en la cabecera (ej. "Generate All", "Prompt Drafts", "Review Mockup Combinations") tienen un tamaño de texto de **9px**. Esto los hace casi ilegibles, difíciles de hacer clic y rompe pautas básicas de accesibilidad visual. | [Ver Mockup Lab](screenshots/08-mockup_combinations_review.png) | **Alta** |
| **Navegación / Filtros** (`mockup_combinations_review.php`) | **Inconsistencia de filtros**: Las pestañas para cambiar de categorías de escenas se confunden con etiquetas de estado y carecen de un orden curatorial claro. | [Ver Mockup Lab](screenshots/08-mockup_combinations_review.png) | **Media** |

---

## Flujo 4: Editor de Metadata Curatorial

Edición de la ficha técnica de la obra y análisis curatorial.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Selección de títulos** (`artwork.php`) | **Recarga de página en selección**: Al elegir uno de los títulos curativos sugeridos (Option 1, 2, 3), la página completa se recarga para guardar el cambio en la base de datos, lo que interrumpe la fluidez y el ritmo de navegación del artista. | [Ver Ficha](screenshots/06-artwork_sheet.png) | **Media** |
| **Visualización Raw** (`artwork.php`) | **Exposición de datos técnicos crudos (JSON)**: Al final de la página se ofrece un panel colapsable que dice "View Raw AI Analysis (JSON)". Al abrirlo, se muestra un bloque gigante de código JSON crudo. Esto confunde e intimida al artista promedio que no comprende sintaxis de programación. | [Ver Ficha](screenshots/06-artwork_sheet.png) | **Media** |
| **Idiomas mezclados** (`artwork.php`) | **Falta de localización consistente**: Títulos de secciones principales están en inglés, pero bloques de navegación secundarios o summaries están escritos en español (ej: "Lectura Curatorial"), creando una experiencia lingüística híbrida y poco profesional. | [Ver Ficha](screenshots/06-artwork_sheet.png) | **Media** |

---

## Flujo 5: Timeline de Video de 5 Hitos

Uso de la línea de tiempo para estructurar la narración y los mockups en video.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Visor del video** (`social_video.php` / simple) | **Hacks de escalado en CSS (zoom: 0.7)**: El archivo `social_video_simple.php` tiene un estilo CSS en el body con `zoom: 0.7`. Esto encoge artificialmente todos los elementos de la página, haciendo que el texto se vea borroso, los botones minúsculos y rompe la responsividad nativa en diferentes monitores. | [Ver Video Simple](screenshots/09-social_video_simple.png) | **Alta** |
| **Arrastre de Mockups** (`social_video_timeline.php`) | **Falta de feedback en Drag & Drop**: Al arrastrar una imagen de la biblioteca a los hitos de la timeline, los contenedores receptores (`svt-slot`) no muestran ningún estado visual activo de "hover" (ej. cambiar color de fondo o borde) para indicarle al artista que puede soltar el elemento ahí de forma segura. | [Ver Timeline](screenshots/10-social_video_timeline.png) | **Media** |
| **Acciones de timeline** (`social_video_timeline.php`) | **Idioma inconsistente con el sistema**: Esta interfaz está programada enteramente en español (ej. "Arrastra un mockup o sube una imagen", "Guardar timeline"), mientras que toda la navegación e interfaces del resto del sitio están en inglés. | [Ver Timeline](screenshots/10-social_video_timeline.png) | **Media** |
| **Carga de archivos** (`social_video_timeline.php`) | **Controles amontonados**: Cada hito de la timeline muestra 5 inputs en una columna estrecha (arrastre de imagen, subir archivo local, textarea de guión, imagen de referencia, texto de referencia). Esto genera una carga cognitiva extrema y amontona los controles haciéndolos difíciles de usar. | [Ver Timeline](screenshots/10-social_video_timeline.png) | **Alta** |

---

## Flujo 6: Configuración, Historial y Galerías

Pantallas de perfil del artista, configuración de cuenta y herramientas del administrador.

| Paso / Pantalla | Fricción Observada | Evidencia | Severidad |
| :--- | :--- | :--- | :--- |
| **Historial de obras** (`dashboard.php`) | **Falta de paginación y búsqueda**: El dashboard acumula todas las obras registradas. A medida que el artista incremente su portafolio (actualmente hay más de 290 registros en la base de datos), la carga de la página se vuelve lenta y buscar una obra en específico requiere scroll infinito, ya que no hay barra de búsqueda ni filtros por estado. | [Ver Dashboard](screenshots/02-dashboard.png) | **Media** |
| **Acceso directo** (`autologin.php`) | **Redirección dura a registro inexistente**: El script de autologin intenta forzar una redirección a `artwork.php?id=110`. Si el usuario no tiene la obra con ID 110 en su base de datos local, el sistema se rompe o muestra una pantalla vacía, en lugar de enviarlo de forma segura al dashboard. | [Ver Login](screenshots/01-login.png) | **Alta** |

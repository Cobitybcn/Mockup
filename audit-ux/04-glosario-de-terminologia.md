# Glosario de Terminología Técnica y Ambigüedades

Este documento recopila todos los términos de carácter técnico, jerga de desarrollo o vocabulario ambiguo que aparecen en la interfaz orientada al usuario (artista), evaluando su impacto y proponiendo alternativas comprensibles.

---

## 1. Conceptos de Infraestructura y Modelos de IA

| Término actual en la UI | Ubicación habitual | ¿Qué entiende el Desarrollador? | ¿Qué interpreta el Artista? | Impacto en UX | Alternativa propuesta |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Imagen 3 pipeline** | `artwork_new.php` / `waiting.php` | El modelo fundacional de generación de imágenes de Google usado para la limpieza. | Un nombre técnico de software o versión de base de datos. | **Confusión**: No aporta valor práctico al usuario saber el nombre del modelo. | *"Sistema de restauración y aislamiento digital"* |
| **Bypass Mode** | `artwork_new.php` | Saltarse la ejecución de las APIs de IA para guardar la imagen cruda. | Un modo técnico que parece una opción de depuración. | **Desconfianza**: Suena a un proceso peligroso o de "hackeo". | *"Carga directa (sin limpieza de IA)"* |
| **Vertex AI / Veo** | `admin_api_keys.php` / Textos de ayuda de video | La plataforma en la nube de Google Cloud y su modelo generativo de video. | Código técnico o marcas ajenas a la aplicación. | **Distracción**: Expone la infraestructura interna del producto. | *"Motor de video cinematográfico"* o simplemente *"Generador de Video"* |
| **FFmpeg** | `social_video_simple.php` / Notas de release | La biblioteca CLI utilizada para procesar, concatenar y renderizar video. | El decodificador y compilador de video del backend. | **Incomprensión**: Vocabulario exclusivo de programadores o editores de video técnicos. | *"Compilador de video local"* o *"Renderizador"* |

---

## 2. Metadatos de Estructura de Datos e Identificadores

| Término actual en la UI | Ubicación habitual | ¿Qué entiende el Desarrollador? | ¿Qué interpreta el Artista? | Impacto en UX | Alternativa propuesta |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Root Image / Root Artwork** | Sidebar, `root_select.php`, `artwork.php` | La imagen base limpia y frontal de la obra (sin marcos ni sombras) sobre la que se proyectan los mockups. | La "raíz" física de una planta o un término de hacking (rootear un teléfono). | **Ambigüedad**: Un artista no asocia "root" con una imagen limpia o un lienzo aislado. | *"Lienzo Limpio"*, *"Fotografía Base"* o *"Obra Aislada"* |
| **CORE JSON / JSON 1.1** | `core_review.php` y alertas | Formato de serialización de datos de la obra que contiene la metadata física detectada. | Archivo estructurado con claves y valores de la obra. | **Intimidación**: Exponer formatos de programación rompe la ilusión de una herramienta creativa. | *"Ficha Técnica Estructural"* o *"Ficha Digital"* |
| **Job ID (ej. `job_1782...`)** | `waiting.php` / `root_select.php` | El timestamp Unix concatenado con un valor aleatorio para identificar el proceso. | Un código de error o un número de ticket de soporte técnico. | **Distracción**: Visualmente ruidoso. Es útil para logs, pero no debe dominar la pantalla de carga. | *"Identificador del proceso"* (y mostrarlo en un tamaño pequeño/secundario) |
| **SEO Slug** | `artwork.php` | La cadena de texto formateada para URLs amigables (ej. `titulo-de-obra`). | Un término de marketing digital o posicionamiento web. | **Distracción**: Terminología técnica de webmasters. | *"Nombre para enlace web"* o *"Identificador web"* |

---

## 3. Parámetros Técnicos y Nombres Internos de la Base de Datos

| Término actual en la UI | Ubicación habitual | ¿Qué entiende el Desarrollador? | ¿Qué interpreta el Artista? | Impacto en UX | Alternativa propuesta |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **World Mother** / **world_mother_category** | `mockup_combinations_review.php` / URLs | El nombre clave del motor que define los entornos tridimensionales/escenas. | Un término conceptual abstracto u místico. | **Confusión**: No tiene relación obvia con diseño de interiores ni salas de exposición. | *"Galería de Espacios"*, *"Catálogo de Escenas"* o *"Estilo de Habitación"* |
| **Camera Slot / Geometry** | `mockup_combinations_review.php` | El punto de coordenadas 3D en la escena donde se colgará la obra. | Un concepto matemático o de renderizado industrial. | **Distracción**: Muy técnico. El artista solo quiere elegir perspectivas de cámara. | *"Perspectiva de Cámara"* o *"Ángulo de Vista"* |
| **Slugs de cámara (ej. `corte-agresivo-de-esquina-loft`)** | Selector de cámaras en Mockup Lab | El nombre físico de la imagen de fondo de la escena en el servidor. | Una descripción de acción violenta o código interno. | **Mala estética**: Rompe el tono premium y sofisticado de una galería de arte. | *"Detalle de esquina"* o *"Primer plano lateral"* |
| **primary_composition_reference** / **left_oblique_reference** | `core_review.php` (Tablas) | Constantes del backend para definir el rol de cada imagen de la obra. | Variables de código fuente o programación. | **Confusión**: El artista ve variables de base de datos en una tabla de metadatos. | *"Referencia frontal principal"* / *"Vista angular izquierda"* |

# Informe: prompt ADMIN y aprovechamiento de JSON en form2

Fecha: 2026-06-16

## Alcance

Se reviso el prompt adjunto en `ULTIMO PROMP QUE MEJORA LAS DESCRIPCIONES MOCKUPS (en).pdf`, la configuracion activa de ADMIN, el flujo de analisis/mockups y el uso de variables en `form2.php`.

No se realizaron cambios en el prompt activo ni en el formulario.

## Hallazgo principal

El prompt del PDF esta orientado a producir mejores titulos, subtitulos y descripciones. El JSON propuesto incluye informacion mucho mas rica que la que hoy se aprovecha visualmente en `form2`.

La base tecnica ya permite recibir y guardar gran parte de esa informacion, pero `form2` no la muestra ni la convierte en campos utiles de seleccion/publicacion. Por eso, si solo se actualiza el prompt, Gemini puede devolver mejores datos, pero buena parte quedara escondida en el JSON crudo.

## Estado actual del ADMIN

En la base local ya existe un `artwork_analysis_prompt` activo grande, de aproximadamente 16.540 caracteres.

Tambien existen capas guardadas:

- `artwork_analysis_layer_a`
- `artwork_analysis_layer_b`
- `artwork_analysis_layer_c`
- `artwork_analysis_layer_d`

La cantidad activa de contextos es `4`, por `mockup_context_count`. El codigo fuerza este valor aunque el prompt mencione otro numero.

El prompt activo ya contiene muchas partes del PDF:

- `suggested_titles`
- `subtitle`
- `short_description`
- `curatorial_description`
- `commercial_description`
- `camera_view`
- `camera_distance`
- `camera_angle_notes`
- `mockup_prompt`
- `negative_prompt`
- `marketplace_title`
- `marketplace_short_description`
- `marketplace_long_description`
- `pinterest_boards`
- `hashtags`
- `format_and_scale`
- `{depth_cm}`

Pero falta una parte importante del PDF:

- `publishing_metadata.catawiki_listing`

El PDF pide explicitamente una ficha Catawiki con:

- `recommended_title`
- `alternative_titles`
- `subtitle`
- `short_description`
- `long_description`
- `technical_details`
- `condition_statement`
- `shipping_statement`
- `seo_keywords`
- `tags`

## Donde se genera y guarda la informacion

El prompt final se arma en `app/Support/PromptSettings.php` y `app/Services/MockupContextEngine.php`.

Puntos relevantes:

- `PromptSettings::artworkAnalysisPrompt()` inyecta las capas A-D y reglas efectivas de ADMIN antes del esquema JSON.
- `MockupContextEngine::buildAnalysisPrompt()` reemplaza placeholders como `{artist_profile_prompt}`, `{title}`, `{width_cm}`, `{height_cm}`, `{depth_cm}`, `{context_count}`.
- `MockupContextEngine::generateMockupPrompts()` toma `contextual_proposals`, genera prompts finales y guarda la informacion.
- `MockupContextEngine::saveToDatabase()` guarda `artwork_analysis.analysis_json` y cada contexto en `mockup_contexts.context_json`.

Conclusion: la estructura de guardado ya soporta arrays y objetos JSON complejos. No hace falta modificar la base de datos para guardar las variables nuevas si se mantienen dentro de `analysis_json` y `context_json`.

## Que aprovecha hoy form2

En modo dinamico, `form2.php` lee la base de datos y arma un `$profile` reducido. Actualmente aprovecha:

- lectura curatorial de una linea
- resumen de estilo
- lenguaje visual
- paleta dominante/secundaria
- temperatura de color
- energia emocional
- contraste
- audiencia primaria
- temporada
- contextos recomendados
- camara
- distancia de camara
- momento del dia
- placement
- figura humana
- razon curatorial
- razon comercial
- prompt final del mockup

Tambien muestra el JSON crudo para ADMIN, pero no lo convierte en una experiencia util para elegir titulos, textos o metadatos.

## Variables del PDF que hoy no se aprovechan totalmente

Estas variables pueden llegar en el JSON, pero hoy quedan invisibles o subutilizadas en `form2`:

- `publishing_metadata.suggested_titles[]`
- `publishing_metadata.suggested_titles[].title`
- `publishing_metadata.suggested_titles[].subtitle`
- `publishing_metadata.suggested_titles[].short_description`
- `publishing_metadata.suggested_titles[].curatorial_description`
- `publishing_metadata.suggested_titles[].commercial_description`
- `publishing_metadata.catawiki_listing`
- `publishing_metadata.descriptions.poetic_focus`
- `publishing_metadata.descriptions.formal_focus`
- `publishing_metadata.descriptions.commercial_focus`
- `publishing_metadata.seo_keywords`
- `publishing_metadata.seo_tags`
- `publishing_metadata.marketplace_title`
- `publishing_metadata.marketplace_short_description`
- `publishing_metadata.marketplace_long_description`
- `publishing_metadata.pinterest_boards`
- `format_and_scale`
- `artist_profile_relation`
- `audience_profile.secondary`
- `audience_profile.buyer_profile`
- `audience_profile.collector_type`
- `audience_profile.interior_design_relevance`
- `audience_profile.gallery_potential`
- `pinterest_marketing.seo_keywords`
- `pinterest_marketing.hashtags`

## Compatibilidad con el form2 actual

El cambio de `suggested_titles` es especialmente importante.

El prompt viejo del codigo esperaba:

```json
"suggested_titles": {
  "poetic": "",
  "descriptive": "",
  "marketplace_friendly": ""
}
```

El PDF propone:

```json
"suggested_titles": [
  {
    "title": "",
    "subtitle": "",
    "short_description": "",
    "curatorial_description": "",
    "commercial_description": ""
  }
]
```

Ese formato nuevo es mejor para el objetivo del usuario, pero `form2` necesita adaptarse para mostrar esas tres opciones y permitir elegir o copiar titulo/subtitulo/descripciones.

## Recomendacion

Hacer el cambio en dos pasos.

1. Actualizar el prompt activo de ADMIN para alinearlo completamente con el PDF.

Esto deberia incluir `catawiki_listing`, conservar `{context_count}` y mantener los campos de camara obligatorios. Tambien conviene ajustar el texto de capas A-D para evitar contradicciones con el prompt principal.

2. Modificar `form2` para aprovechar la informacion nueva.

Agregar una seccion editorial/publicacion con:

- tres opciones de titulo
- subtitulo
- descripcion corta
- descripcion curatorial
- descripcion comercial
- descripcion poetica/formal/comercial
- ficha Catawiki
- keywords/tags
- tab o bloque Pinterest por contexto

## Riesgo si solo se cambia el prompt

El analisis mejorara, pero el usuario no vera la mayor parte de la mejora.

Los datos quedaran disponibles en el JSON crudo y posiblemente en la base, pero no en controles utiles del formulario. Eso limita mucho el valor de pedir mejores titulos, subtitulos y descripciones.

## Riesgo si se cambia form2 sin normalizar

Hay analisis antiguos que pueden tener `suggested_titles` como objeto y analisis nuevos que lo tendran como array. `form2` deberia aceptar ambos formatos para no romper obras ya analizadas.

## Decision tecnica sugerida

- Mantener la base de datos como esta.
- Normalizar en PHP los metadatos editoriales antes de renderizar.
- Soportar formato viejo y nuevo.
- Mostrar los campos nuevos solo si existen.
- No obligar a reanalizar obras antiguas, pero recomendar recalculo para obtener el esquema completo del PDF.

## Archivos relevantes

- `admin_prompts.php`: pantalla donde ADMIN edita el prompt.
- `app/Support/PromptSettings.php`: default del prompt y ensamblado de capas.
- `app/Services/MockupContextEngine.php`: reemplazo de placeholders, llamada a Gemini, normalizacion y guardado.
- `form2.php`: pantalla que hoy consume parcialmente el analisis.


# Auditoria de prompts y arquitectura de mockups

Fecha: 2026-07-01

Objetivo: preparar una base clara para una reorganizacion profunda del sistema sin romper lo que ya funciona.

## Estado actual

Hay tres areas principales:

1. Generacion de obra raiz o uso de obra raiz provista por el usuario: aproximadamente 90% conquistado.
2. Generacion de mockups con camaras + mundo madre: aproximadamente 70% conquistado.
3. Generador de mundos madre para administradores: aproximadamente 50% conquistado.

La prioridad es estabilizar, simplificar y quitar capas viejas. El problema principal ya no parece ser falta de instrucciones, sino exceso de instrucciones en lugares distintos, con algunas reglas nuevas intentando corregir reglas antiguas que siguen activas.

## Zonas protegidas

Estas zonas deben tratarse como protegidas durante cualquier refactor:

- Generacion de obra raiz.
- Flujo de obra raiz provista por el usuario.
- Slots de camara.
- Geometria base de las camaras.

Principio operativo: el slot de camara manda sobre la vista, el angulo y la intencion fotografica. Eso no significa que pueda anular la imagen de mundo madre ni forzar escala fisica absurda.

## Problema central

El sistema tiene instrucciones distribuidas en demasiadas capas:

- Prompt maestro/admin.
- Politicas PHP agregadas al prompt final.
- Reglas derivadas de combinaciones.
- Reglas de mundo madre.
- Reglas de camara.
- Reglas de escala/dominancia.
- Reglas negativas.
- Reglas de preservacion de obra.
- Logica de precomposicion y escala en Python.
- Contextos legacy aun cargados por servicios antiguos.

Esto causa que una instruccion nueva no reemplace realmente a la anterior. A veces solo se suma encima, y Gemini resuelve la contradiccion de forma visualmente incorrecta.

Ejemplo clave: cuando se pide mas dominancia de obra en una sala industrial, Gemini puede agrandar fisicamente la obra en vez de resolverlo como un fotografo: acercando camara, recortando o usando focal mas cerrada.

## Mapa de capas activas

### 1. Entrada y flujo de usuario

Archivos relevantes:

- `artwork_new.php`
- `upload_existing_root.php`
- `select_root.php`
- `mockup_combinations_review.php`
- `generate_mockup_combination.php`
- `world_mother_studio.php`

Riesgo: hay rutas nuevas y rutas legacy coexistiendo. La auditoria profunda debe confirmar que el flujo actual no arrastre analisis curatorial ni seleccion de contexto anterior.

### 2. Obra raiz

Archivos relevantes:

- `app/Services/GeminiArtworkProcessor.php`
- `app/Support/PromptSettings.php`
- `app/Support/CoreArtworkJsonBuilder.php`
- `app/Services/GeminiArtworkAnalyzer.php`

Estado: funciona bien y debe preservarse.

Riesgo: el analisis de obra aun existe. Para el flujo actual de mockups, no deberia volver a decidir contexto, titulo, descripcion ni mundo.

### 3. Combinaciones de mockups

Archivo principal:

- `app/Services/MockupCombinationEngine.php`

Funciones/conceptos sensibles:

- Flujo directo con mundo madre seleccionado.
- Rotacion de mundos madre entre fichas.
- `cameraReferenceMode`.
- `worldMotherCameraRole`.
- `cameraGeometry`.
- `cameraIntegrityBlock`.
- Compatibilidades y nombres legacy relacionados con contextos.

Riesgo: aunque el flujo actual ya usa mundo madre directo, este archivo conserva muchas decisiones antiguas. Puede estar mezclando ideas de contexto, escena, camara y referencia.

### 4. Composicion del prompt final

Archivo principal:

- `app/Support/AdminPromptComposerPreview.php`

Este archivo compone el prompt final a partir de:

- Prompt admin: `PromptSettings::mockupFinalRequest()`.
- Politicas de escala.
- Politicas de dominancia.
- Politicas de canto/borde.
- Politicas de detalle.
- Politicas de autoridad mundo madre/camara.
- Overrides para detalle y obra apoyada.

Riesgo: este es uno de los centros de superposicion. Si el prompt admin contiene reglas estructurales y luego PHP agrega politicas estructurales, el resultado puede ser redundante o contradictorio.

### 5. Politicas PHP agregadas al prompt

Archivos relevantes:

- `app/Support/ArtworkScalePolicy.php`
- `app/Support/ArtworkDominancePolicy.php`
- `app/Support/ArtworkEdgePolicy.php`
- `app/Support/ArtworkDetailCropPolicy.php`
- `app/Support/WorldMotherCameraAuthorityPolicy.php`

Estado: fueron utiles para corregir problemas puntuales, pero ahora deben auditarse como sistema.

Riesgo: estas politicas pueden haberse convertido en parches acumulados. La meta no debe ser agregar otra politica, sino decidir cual es la unica fuente de verdad para cada tema.

### 6. Generador Gemini de mockups

Archivo principal:

- `app/Services/GeminiMockupGenerator.php`

Este servicio agrega:

- Contrato de roles de imagen.
- Instrucciones de preservacion de obra.
- Prompt final enriquecido.
- Referencias de imagen de obra raiz y mundo madre.

Riesgo: aunque el prompt final parezca correcto, aqui se agregan instrucciones adicionales que pueden cambiar la jerarquia real.

### 7. Enriquecedor visual de mundo madre

Archivos relevantes:

- `app/Services/MockupWorldVisualPromptEnhancer.php`
- `app/Support/MockupContextWorldRegistry.php`
- `app/Config/mockup_context_worlds.php`
- `app/Config/mockup_context_families.php`
- `app/Config/mockup_scene_variants.php`

Riesgo: este sistema puede inyectar contratos de mundo o contexto antiguos. Si el flujo nuevo depende de la carpeta `selected` como matriz del mundo madre, esta capa debe revisarse cuidadosamente.

### 8. Puente Python / Vertex

Archivo principal:

- `app/Services/vertex_bridge.py`

Este archivo es critico. No solo transporta imagenes: tambien puede intervenir la escala y precomposicion.

Conceptos detectados:

- `MOCKUP_USE_PRECOMPOSITION`
- `mockup_fill_default`
- `mockup_fill_long_side_le_45`
- `mockup_fill_long_side_le_80`
- `mockup_fill_long_side_le_120`
- `mockup_fill_long_side_le_160`
- `mockup_fill_long_side_le_220`
- `mockup_fill_long_side_gt_220`
- Correcciones de tamano de obra.
- Multiplicadores de escala humana.
- Calculo de `fill_ratio`.
- Directivas finales de armonizacion.

Riesgo mayor: aunque el prompt diga "resolver como fotografo con zoom", esta capa puede estar precondicionando el tamano relativo de la obra antes de enviar o durante la preparacion. Esto explicaria por que algunos cambios de prompt no modifican el resultado.

### 9. Generador de mundos madre

Archivo principal:

- `app/Services/WorldMotherGenerator.php`

Estado: genera, pero todavia con resultados rigidos y repetitivos en algunos estilos.

Riesgo: necesita un prompt fuerte y general, pero no debe crear una sola estetica. Debe producir mundos con profundidad, diagonales, arquitectura util y espacio plausible para insertar obras.

## Archivos legacy o sospechosos

Estos archivos no necesariamente estan mal, pero deben clasificarse como activos, legacy o deprecados:

- `app/Data/context_library.php`
- `app/Data/mockup_camera_archetypes.php`
- `app/Config/mockup_camera_context_compatibility.php`
- `app/Config/mockup_context_families.php`
- `app/Config/mockup_context_worlds.php`
- `app/Config/mockup_scene_variants.php`
- `app/Services/MockContextSelector.php`
- `app/Services/MockupContextEngine.php`
- `app/Support/MockupCameraArchetypeResolver.php`
- `app/Support/MockupContextWorldRegistry.php`

Pregunta clave: si el nuevo flujo es obra raiz + slot de camara + mundo madre seleccionado, estas capas no deberian decidir contexto ni escena en el flujo principal.

## Fallos observados

### Escala exagerada

Sintoma: en salones grandes, la obra aparece fisicamente enorme para ganar presencia visual.

Causa probable: reglas de dominancia visual interpretadas como escala fisica, combinadas con precomposicion o fill ratios.

Solucion conceptual: reemplazar dominancia por lenguaje fotografico. La presencia visual debe resolverse con distancia de camara, focal, crop y encuadre, no con aumento fisico de la obra.

### Mundo madre congelado

Sintoma: la escena madre queda fija y no acompana la camara.

Causa probable: el mundo madre es tratado como layout rigido en algunas camaras y como referencia flexible en otras.

Solucion conceptual: separar camaras de ambiente y camaras de detalle. El mundo madre debe aportar materiales, luz, arquitectura y objetos, pero la camara debe reconstruir la vista segun el slot.

### Detalles demasiado condicionados

Sintoma: vistas de detalle tomaban siempre el mismo lateral o generaban deformaciones.

Causa probable: prompts de detalle con demasiada direccion lateral y reglas extra intentando corregirlo.

Solucion conceptual: el slot debe expresar la intencion fotografica minima. La variacion de lado debe ser parametrica o rotativa, no una capa textual nueva por cada intento.

### Mundos madre repetitivos

Sintoma: cuatro generaciones muy similares.

Causa probable: prompt de mundo madre con poca exigencia de variacion compositiva y poca diversidad de camara.

Solucion conceptual: exigir variacion real entre salidas: vista amplia, diagonal profunda, rincon arquitectonico, pared util de instalacion, luz distinta, distancia distinta.

## Principio de reorganizacion

Cada concepto debe tener un unico propietario:

- Camara: slots de camara.
- Identidad de obra: contrato de preservacion de obra.
- Escala fisica: una sola politica de escala.
- Dominancia visual: politica fotografica, no escala.
- Mundo madre: politica de mundo madre.
- Instalacion: politica de instalacion.
- Calidad/render: una sola capa.
- Negativos: una sola capa.
- Admin prompt: tono y criterios generales, no reglas estructurales que compitan con PHP.
- Precomposicion: explicita, configurable y logueada, o desactivada para el flujo actual.

## Propuesta de arquitectura objetivo

Crear un compilador unico de prompt de mockup.

Debe producir secciones con nombre y procedencia:

1. `IMAGE_ROLE_CONTRACT`
2. `CAMERA_SLOT`
3. `WORLD_MOTHER_REFERENCE`
4. `ARTWORK_IDENTITY`
5. `PHYSICAL_SCALE`
6. `PHOTOGRAPHIC_DOMINANCE`
7. `INSTALLATION`
8. `QUALITY`
9. `NEGATIVE_RULES`

Cada seccion debe indicar:

- Fuente.
- Prioridad.
- Si es obligatoria.
- Si puede ser reemplazada.
- Si aplica solo a detalle, ambiente o balance.

El sistema debe poder exportar un JSON de depuracion con:

- Prompt final.
- Secciones activas.
- Archivos de origen.
- Politicas aplicadas.
- Politicas omitidas.
- Imagenes de referencia usadas.
- Dimensiones fisicas de obra.
- Estado de precomposicion.

## Tareas recomendadas para Claude Code

### Fase 1: inventario

Clasificar cada archivo de prompts/contexto como:

- Activo en flujo actual.
- Activo solo en legacy.
- Admin/config editable.
- Candidato a eliminar o deprecar.
- Candidato a fusionar.

### Fase 2: trazabilidad

Agregar una vista o comando de diagnostico que muestre el prompt final seccionado antes de generar imagen.

No debe generar mockups. Solo debe explicar de donde viene cada instruccion.

### Fase 3: proteger lo que funciona

Crear fixtures o tests de no-regresion para:

- Generacion de obra raiz.
- Uso de obra raiz subida.
- Carga de slots de camara.
- Nombres y geometria base de camaras.

### Fase 4: limpiar flujo de mockups actual

Para el flujo actual, confirmar que no participa:

- Analisis curatorial.
- Titulos generados.
- Descripciones curatoriales.
- Seleccion de contexto desde imagen raiz.
- Compatibilidades antiguas de contexto.

### Fase 5: unificar escala y dominancia

Reemplazar porcentajes rigidos por politica fotografica:

- Mantener escala fisica plausible.
- Aumentar presencia visual mediante acercamiento, focal, crop o composicion.
- No agrandar fisicamente la obra para cumplir dominancia.

Revisar tambien `vertex_bridge.py`, porque puede imponer escala aunque el prompt textual sea correcto.

### Fase 6: revisar mundo madre

Definir si el mundo madre es:

- Referencia flexible de arquitectura, luz, materiales y objetos.
- No layout congelado.
- No plantilla de composicion fija.

La camara debe reconstruir la escena desde el slot, usando el mundo madre como atmosfera y vocabulario visual.

### Fase 7: reducir, no parchear

Antes de agregar una regla nueva, eliminar o neutralizar la regla vieja que compite con ella.

Meta: menos capas, mas trazabilidad, menos contradiccion.

## Recomendacion inmediata

No seguir corrigiendo casos visuales agregando frases.

Primero hacer una auditoria tecnica con trazabilidad real:

1. Tomar una combinacion concreta.
2. Generar el prompt final seccionado.
3. Listar todas las capas activas.
4. Confirmar si `vertex_bridge.py` aplica precomposicion o fill ratio.
5. Desactivar o aislar capas legacy en el flujo actual.
6. Repetir con dos camaras: una de ambiente y una de detalle.

Solo despues conviene redisenar la politica de escala/dominancia.

## Criterio de exito

El sistema debe permitir responder, para cualquier mockup:

- Que camara mando.
- Que mundo madre se uso.
- Que reglas de escala se aplicaron.
- Que reglas fueron omitidas.
- Si hubo precomposicion.
- Que prompt final exacto llego a Gemini.
- Que instrucciones son legacy y no participan.

Si no se puede responder eso, el sistema seguira siendo dificil de estabilizar.

---

## Fase 1: Inventario (resultado, 2026-07-01)

Clasificacion de cada archivo del sistema de prompts/contexto, basada en trazado real de `require`/`use`/instanciacion desde los 6 puntos de entrada del flujo actual (`artwork_new.php`, `upload_existing_root.php`, `select_root.php`, `mockup_combinations_review.php`, `generate_mockup_combination.php`, `world_mother_studio.php`). No se modifico ningun archivo; esto es solo lectura.

### Hallazgo critico: dos prompts finales distintos coexisten

Verificado con lectura directa de codigo (no solo trazado automatico):

- `app/Services/MockPromptBuilder.php` esta marcado `// LEGACY / DO NOT USE IN PHASE 2.3 FLOW` en su propia cabecera (linea 2), pero **sigue ejecutandose**: `MockupContextEngine::generateMockupPrompts()` lo instancia (`MockupContextEngine.php:191`) y llama `->build($mappedContext, $mappedProfile, $imageMeta)` (`MockupContextEngine.php:454`). El resultado se guarda como `$prop['prompt']` y termina persistido en la columna `mockup_contexts.prompt` (`MockupContextEngine.php:2116`).
- Esa columna `prompt` se **muestra** en paneles de admin/reporte: `report.php` (lineas 185, 216, 317, 330, 2011, 2064, 2415), `artwork.php` (lineas 396, 532), `admin_mockup_prompts_status.php:68`. Un admin que la mira cree que esta viendo "el prompt final".
- Pero el prompt que **realmente se envia a Gemini** se reconstruye desde cero en `AdminPromptComposerPreview::compose()` (`app/Services/AdminPromptComposerPreview.php:14-139`), invocado unicamente desde `MockupCombinationEngine.php:552`. Este metodo **nunca lee** `$contextProposal['prompt']`; arma su propio bloque de contexto (`parseContextFields()` + `buildContextBlock()`, lineas 193-322) a partir de `mockup_prompt` y otros campos estructurados de `context_json`, y lo inserta en la plantilla ADMIN V7 (`PromptSettings::mockupFinalRequest()`) junto con las 5 politicas PHP.

Consecuencia practica: el trabajo de `MockPromptBuilder` en el flujo de mockups (no en analisis de obra) es **codigo muerto para generacion, vivo para visualizacion**. Cualquier cambio a `MockPromptBuilder` no afecta lo que Gemini recibe, pero si afecta lo que un admin cree que esta pasando. Esto corrige una suposicion previa (ver memoria de sesion anterior) de que `MockPromptBuilder` era el compositor activo del prompt final — ya no lo es para generacion real; `AdminPromptComposerPreview` es la unica fuente de verdad del texto que llega a Gemini en el flujo de combinaciones.

Nota aparte: `MockPromptBuilder` tambien se usa, via `MockContextSelector`, dentro del analisis de obra raiz (`GeminiArtworkAnalyzer.php:11`, `OpenAIArtworkAnalyzer.php:10`). Ahi su rol es distinto (seleccionar contexto legacy de `context_library.php` como fallback de analisis) y esta fuera del alcance de "quien compone el prompt de mockup", pero es otro punto donde el principio "el analisis de obra no deberia decidir contexto" (Seccion 2 de esta auditoria) puede estar violandose silenciosamente.

### Tabla de clasificacion

**Entrada y flujo** — todos activos en flujo actual (son los puntos de entrada mismos): `artwork_new.php`, `upload_existing_root.php`, `select_root.php`, `mockup_combinations_review.php` (`MockupCombinationEngine.php:92`), `generate_mockup_combination.php` (linea 42), `world_mother_studio.php` (linea 69).

**Obra raiz** — activos en flujo actual: `GeminiArtworkProcessor.php` (via `ServiceFactory.php:58`), `PromptSettings.php` (usado en `artwork_new.php:8` y `AdminPromptComposerPreview.php:16` para el prompt admin), `CoreArtworkJsonBuilder.php`, `GeminiArtworkAnalyzer.php` (`ServiceFactory.php:74`).

**Combinaciones** — activo, es el motor central: `MockupCombinationEngine.php` (`mockup_combinations_review.php:92`, `generate_mockup_combination.php:42`).

**Composicion de prompt final**:
- `AdminPromptComposerPreview.php` — activo en flujo actual; **unica fuente de verdad del prompt final enviado a Gemini** (`MockupCombinationEngine.php:552`).
- `MockPromptBuilder.php` — activo solo en legacy/display para el flujo de mockups (ver hallazgo critico arriba); tambien activo como fallback en analisis de obra raiz via `MockContextSelector`. Candidato a eliminar del camino de generacion de mockups (mantener solo si se decide preservarlo para el fallback de analisis de obra).

**Politicas PHP** — todas activas en flujo actual, instanciadas en `AdminPromptComposerPreview.php` lineas 154-167: `ArtworkScalePolicy.php`, `ArtworkDominancePolicy.php`, `ArtworkEdgePolicy.php`, `ArtworkDetailCropPolicy.php`, `WorldMotherCameraAuthorityPolicy.php`.

**Generador Gemini de mockups** — activo: `GeminiMockupGenerator.php` (`ServiceFactory.php:91`).

**Enriquecedor visual de mundo madre** — todos activos en flujo actual, cargados por `MockupContextWorldRegistry::load()` (`MockupContextWorldRegistry.php:543-546`): `MockupWorldVisualPromptEnhancer.php` (invocado tambien desde `GeminiMockupGenerator.php:102,112`), `MockupContextWorldRegistry.php`, `mockup_context_worlds.php`, `mockup_context_families.php`, `mockup_scene_variants.php`, `mockup_camera_context_compatibility.php`. No son duplicados: son un unico set de config consumido por un unico registry.

**Puente Python/Vertex** — activo: `vertex_bridge.py` (invocado desde `GeminiImageClient.php:19,42`); soporta `MOCKUP_USE_PRECOMPOSITION`, `MOCKUP_PROMPT_FIRST_MODE`, `MOCKUP_USE_BACKGROUND_EDIT` como flags activos, no legacy muerto — requiere revision propia en Fase 5 de la auditoria original (posible precondicionamiento de escala).

**Generador de mundos madre** — activo: `WorldMotherGenerator.php` (`select_root.php:14`, `world_mother_studio.php:67`).

**Legacy/sospechosos**:
- `context_library.php` — activo solo en legacy: usado por `MockContextSelector.php:15` como fallback de analisis de obra raiz, no participa en la generacion de mockups.
- `mockup_camera_archetypes.php` — activo en flujo actual pero con schema legado; usado por `MockupCameraArchetypeResolver.php:122` para mapear dimensiones de obra a camera slots. No eliminar sin reemplazo.
- `mockup_camera_context_compatibility.php` — activo en flujo actual, cargado por `MockupContextWorldRegistry`.
- `MockContextSelector.php` — activo solo en legacy/fallback: usado por los analizadores de obra raiz (Gemini y OpenAI), no por el flujo de mockups. Candidato a fusionar o eliminar junto con `MockPromptBuilder.php` si se decide que el analisis de obra no debe seleccionar contexto (principio ya establecido en Seccion 2 de esta auditoria).
- `MockupContextEngine.php` — activo en flujo actual; archivo de ~1900+ lineas que mezcla resolucion de camera archetypes, generacion de propuestas de contexto (incluyendo la llamada legacy a `MockPromptBuilder`) y la capa Fase 2.9 de identidad/vital presence. Candidato a fusion/split en 3 responsabilidades separadas.
- `MockupCameraArchetypeResolver.php` — activo en flujo actual, usado por `MockupContextEngine.php:143,1486`.

**Fase 2.9** — ambos ya mergeados y activos en flujo actual: `MockupContextIdentity.php` (`MockupContextEngine.php:6`, resuelve linea 2097), `MockupVitalPresenceResolver.php` (`MockupContextEngine.php:7`, resuelve lineas 445, 2108).

**Adicionales encontrados fuera de la lista original** (activos en flujo actual salvo lo indicado): `WorldMotherLibrary.php`, `MockupBatchQueue.php`, `MockupBranchContextBuilder.php`, `MockupBranchPromptDraftBuilder.php`, `GeminiImageClient.php`, `ServiceFactory.php`. Activos solo en fallback/test: `MockArtworkProcessor.php`, `MockArtworkAnalyzer.php`, `MockMockupGenerator.php`. Activos en fallback opcional (si `imageProvider()` esta configurado en OpenAI): `OpenAIArtworkProcessor.php`, `OpenAIArtworkAnalyzer.php`, `OpenAIMockupGenerator.php`. Fuera de alcance de esta auditoria (paralelo, no mockups): `SocialVideoService.php`, `VeoVideoClient.php`.

Scripts de diagnostico ya existentes en la raiz del proyecto, no listados en el mapa original, que pueden ser reutiles para la Fase 2 (trazabilidad) en vez de construir uno nuevo desde cero: `compare_mockup_prompt_composition.php`, `audit_recent_mockup_generation.php`, `generate_one_mockup_from_composed_admin_prompt.php`, `verify_scale_rules.php`.

### Resumen para decidir Fase 2

- El compositor real del prompt de mockup es uno solo (`AdminPromptComposerPreview`), lo cual es una buena noticia: no hay que unificar dos compositores en competencia, hay que **apagar la escritura/lectura del compositor fantasma** (`MockPromptBuilder` en el camino de mockups) y dejar en claro en los paneles de admin que columna reflejan.
- El riesgo mayor pendiente de confirmar sigue siendo `vertex_bridge.py` (Fase 5 de la auditoria original): si precondiciona escala o aplica precomposicion, ningun cambio de texto en `AdminPromptComposerPreview` lo va a corregir.
- Ya existen varios scripts de diagnostico (`compare_mockup_prompt_composition.php`, `audit_recent_mockup_generation.php`) que probablemente ya hacen parte de lo que pide la Fase 2; revisarlos antes de construir una vista nueva.

---

## Fase 2: Trazabilidad del prompt final (resultado, 2026-07-01)

Se construyo `scratch/mockup_prompt_trace.php`, un script de solo lectura (CLI o web, no genera mockups) que llama al mismo motor real que usa `mockup_combinations_review.php` (`MockupCombinationEngine::buildForArtwork`, que el propio motor marca como `generation_mode = review_only_no_image_generation`), toma el prompt final real de una combinacion concreta, y lo desglosa seccion por seccion llamando a las mismas clases publicas/estaticas que usa `AdminPromptComposerPreview::compose()` (no las reimplementa). Los dos overrides privados de `AdminPromptComposerPreview` se invocan via `ReflectionMethod` para leer su codigo real en vez de copiar su texto.

Por cada seccion, la herramienta reconstruye el prompt completo (mismo orden, mismo separador `"\n\n"` que `compose()`) y lo compara byte a byte contra el prompt real devuelto por el motor. Se corrio contra las 14 combinaciones reales de la obra 400 (todas las variantes de camera slot: detalle, borde, esquina, rasante, nadir, apoyada en el suelo, diagonal, contrapicado, reflejo, aerea, pasillo, recovecos) y en las 14 la reconstruccion **coincidio exactamente** con el prompt real. Esto da confianza de que el desglose por secciones no es una aproximacion: es la logica real, verificada.

### Hallazgo mayor: la tabla `mockup_contexts` esta completamente desconectada del flujo de generacion actual

Verificado con `grep` (no hay ni una sola coincidencia de `mockup_contexts` en todo `app/Services/MockupCombinationEngine.php`) y confirmado en ejecucion real contra 5 obras (`artwork_id` 1, 100, 200, 350, 400): en las 14 combinaciones de la obra 400, `source_context_id` fue siempre `0` y el prompt legacy fue siempre cadena vacia.

La causa esta en `MockupCombinationEngine::directContextRowsForCameraSlots()` (`MockupCombinationEngine.php:204-237`), que se llama **sin condicion** desde `buildForArtwork()` (linea 63) y siempre fabrica filas de contexto sinteticas en memoria (`'id' => 0, 'prompt' => ''`) a partir del world mother category y el camera slot seleccionados. Nunca hace un `SELECT` contra `mockup_contexts`.

Consecuencia: todo el pipeline que iba desde `MockupContextEngine::generateMockupPrompts()` (analisis de obra, `analyze.php`/`reanalyze.php`/`regenerate_mockup_proposals.php`) pasando por `MockPromptBuilder`, `MockupCameraArchetypeResolver`, `MockContextSelector`/`context_library.php` como fallback, y la capa Fase 2.9 completa (`MockupContextIdentity`, `MockupVitalPresenceResolver`, el objeto `vital_presence` en `context_json`) **escribe datos validos en `mockup_contexts` que nunca vuelven a leerse para generar una imagen**. Esa tabla solo se usa hoy para mostrar contenido en paneles de admin (`report.php`, `artwork.php`, `admin_mockup_prompts_status.php`). Confirmado tambien por busqueda de `vital_presence` en todo el proyecto: solo aparece en `MockupVitalPresenceResolver.php`, `MockPromptBuilder.php` y `MockupContextEngine.php` — nunca en `MockupCombinationEngine.php`, `AdminPromptComposerPreview.php` ni `GeminiMockupGenerator.php`.

Esto corrige y amplia el hallazgo anterior de esta misma Fase 2 (que solo hablaba de `MockPromptBuilder`): no es una sola clase legacy shadow-computing un prompt no usado, es **toda la cadena de analisis/propuesta de contexto** la que quedo aislada del flujo de combinaciones "mundo madre directo" que es, segun la introduccion de esta misma auditoria, el flujo activo hoy (~70% conquistado). Esto tambien responde directamente a la Fase 4 de esta auditoria ("confirmar que no participa: analisis curatorial, titulos, descripciones, seleccion de contexto desde imagen raiz") — la respuesta empirica es que, en efecto, no participa, pero el codigo que lo calcula sigue corriendo y consumiendo tiempo/costo de Gemini en la etapa de analisis sin ningun efecto sobre el mockup final.

### Otros hallazgos confirmados por la herramienta (obra 400, combinacion 1, slot `detalle_textura_lienzo`)

- `ARTWORK SCALE POLICY` y `WORLD MOTHER AUTHORITY POLICY` se aplican siempre (confirmado en las 14/14 combinaciones).
- Las politicas condicionales (`ArtworkDominancePolicy`, `ArtworkEdgePolicy`, `ArtworkDetailCropPolicy`) y los dos overrides privados (`detailSlotCompositionOverride`, `floorLeaningSlotOverride`) se activan o se omiten exactamente segun las listas de `camera_slot_id` documentadas en cada clase (ver Fase 1) — no hay logica oculta adicional.
- Los flags de precomposicion, verificados via `defined()`/`app_env()` en el mismo proceso PHP que sirve la app: `MOCKUP_PROMPT_FIRST_MODE=true`, lo que fuerza `MOCKUP_USE_PRECOMPOSITION=false` en `vertex_bridge.py` (sin importar el valor crudo en `.env`); y `MOCKUP_PROMPT_FIRST_NO_MASK_MODE=true`, lo que fuerza `MOCKUP_USE_BACKGROUND_EDIT=false` aunque `.env` diga `true`. **En el estado actual del entorno, no hay precomposicion ni mascara de inpainting activas**: el bug de "escala exagerada" descrito en Fallos observados no puede venir de precomposicion en `vertex_bridge.py` en este momento; si persiste, hay que buscarlo en el texto del prompt (`ARTWORK SCALE POLICY` vs. el resto del bloque admin) o en como Gemini interpreta la combinacion de bloques, no en warping de imagen del lado Python.

### Como usar la herramienta

```
php scratch/mockup_prompt_trace.php --artwork-id=<id> --index=<1..N> [--format=json]
```

o via navegador: `scratch/mockup_prompt_trace.php?artwork_id=<id>&index=<1..N>&format=json`. `--index` es el numero de combinacion tal como aparece en `mockup_combinations_review.php` (1-based). Con `--format=json` devuelve el mismo contenido estructurado para uso programatico.

### Pendiente para fases posteriores

- Esta herramienta responde 6 de los 7 puntos del "Criterio de exito" de esta auditoria (que camara mando, que mundo madre, que reglas de escala/omitidas, prompt final exacto, que instrucciones son legacy). El unico pendiente es "si hubo precomposicion" **por generacion especifica** (aqui se reporta el estado global de los flags, no un log por request); eso requeriria instrumentar `vertex_bridge.py` o `GeminiImageClient.php` para loguear los env vars efectivos en cada llamada real, lo cual es mas propio de la Fase 5.
- Con el hallazgo de `mockup_contexts` desconectada, conviene decidir explicitamente (Fase 4/7) si esa tabla y todo lo que la alimenta se apaga, se re-conecta, o se declara oficialmente legacy-solo-para-analisis-y-display antes de seguir tocando `MockupContextEngine.php` o la capa Fase 2.9.

---

## Fase 3: proteger lo que funciona (resultado, 2026-07-01)

El proyecto no tiene PHPUnit ni composer (verificado: no existe `composer.json`, `vendor/`, ni `phpunit.xml`). Siguiendo la convencion ya establecida de scripts PHP standalone (`scratch/verify*.php`, etc.), se creo un directorio `tests/` nuevo y persistente (a diferencia de `scratch/`, que el propio proyecto trata como descartable) con un arnes minimo propio y 3 suites de no-regresion, ninguna de las cuales llama a Gemini, genera mockups, ni escribe en tablas de produccion:

- [tests/TestHarness.php](../tests/TestHarness.php) — aserciones + comparacion de snapshots JSON en `tests/fixtures/`.
- [tests/regression/camera_slots_test.php](../tests/regression/camera_slots_test.php) — zona protegida "Slots de camara" + "Geometria base de las camaras". Valida integridad de `app/Config/mockup_camera_slots.php` (sets vs. catalogo, campos obligatorios) y llama al metodo real `MockupCombinationEngine::activeCameraSlots()` (el mismo que usa `mockup_combinations_review.php`) para congelar en un snapshot los 14 slots activos con su `camera_slot_geometry` ya compuesta (texto completo, no un hash, para que un diff futuro sea legible).
- [tests/regression/root_artwork_test.php](../tests/regression/root_artwork_test.php) — zona protegida "Generacion de obra raiz". Cubre `PromptSettings` (los 4 prompts de vista de obra raiz nunca vacios, y una guarda directa sobre el placeholder `{{MOCKUP_CONTEXT_PROPOSAL}}` que ya rompio la generacion de mockups una vez segun el hallazgo de la Fase 2) y los metodos privados de normalizacion/fallback de `CoreArtworkJsonBuilder` (puros, invocados via Reflection, sin tocar DB ni disco).
- [tests/regression/uploaded_root_test.php](../tests/regression/uploaded_root_test.php) — zona protegida "Flujo de obra raiz provista por el usuario". `upload_existing_root.php` es un script que ejecuta codigo real (`Auth::requireUser()`, insert en BD) apenas se hace `require`, asi que no se puede ejecutar de forma segura en un test; en su lugar es un test de **caracterizacion por inspeccion de codigo fuente** (verifica que ciertas cadenas literales criticas — whitelist de extensiones, columnas del INSERT, contrato `root_source=uploaded_final` + `generation_skipped=true`, redirect — sigan presentes en el archivo).
- [tests/run_regression_tests.php](../tests/run_regression_tests.php) — corredor: `php tests/run_regression_tests.php`, exit code 0/1.

Corrida actual: **67/67 aserciones pasan**. Se verifico que el mecanismo de snapshot detecta regresiones de verdad (no solo pasa siempre): se corrompio a proposito `tests/fixtures/camera_slots_snapshot.json`, la corrida fallo senalando exactamente la clave que cambio (`detalle_textura_lienzo.slot_name`) con exit code 1, y al restaurar el snapshot volvio a pasar limpio.

### Hallazgo adicional: `CoreArtworkJsonBuilder` es codigo muerto

Al escribir el test aparecio un `Fatal error: Class "CoreArtworkJsonBuilder" not found` porque esta clase no esta en `app/bootstrap.php`. Verificado con grep en todo el repo: **ningun archivo la instancia**, ni siquiera los que consumen su salida. Los 10 archivos que leen `analysis/{id}.core.json` desde disco (`AdminPromptComposerPreview.php`, `core_review.php`, `MockupCameraArchetypeResolver.php`, `MockupBranchContextBuilder.php`, etc.) solo hacen `file_get_contents`/`is_file`, nunca `new CoreArtworkJsonBuilder()`. Esto confirma y resuelve la duda que habia quedado abierta en la Fase 1 ("se referencia en documentacion pero evidencia de uso limitada"): la clase que **genera** los CORE JSON esta huerfana; los archivos `.core.json` que existen hoy en disco quedaron de un proceso anterior (o se crearon manualmente), y para una obra nueva simplemente no existe uno — de ahi que `AdminPromptComposerPreview::compose()` caiga tan seguido a su fallback de base de datos (`artworks.width/height/depth`) o a los defaults 120/80/4, como ya se vio en vivo en la Fase 2 con la obra 400. Candidato claro para Fase 4/7: o se reconecta `CoreArtworkJsonBuilder` a algun punto del flujo de analisis, o se declara legacy y se documenta que el fallback de BD es hoy la ruta principal, no la de respaldo.

### Limitaciones honestas de esta Fase 3

- No se prueba la generacion real via Gemini (obra raiz, analisis, mockups): eso requeriria llamadas de API reales, con costo y no determinismo, fuera del alcance de un test de regresion automatico y rapido. Estas suites protegen la logica determinista alrededor de esas llamadas (prompts, normalizacion, config), no la calidad del resultado de IA.
- El test de obra raiz subida es caracterizacion de texto, no ejecucion real; si alguien reescribe la logica preservando el comportamiento pero cambiando la redaccion exacta del codigo, el test puede dar falso negativo. Se documento explicitamente en el propio archivo.

---

## Fase 4: limpiar flujo de mockups actual (resultado, 2026-07-01)

Se confirmaron, con evidencia de codigo y verificacion empirica (no solo lectura), los 5 puntos que esta fase pedia confirmar para el flujo actual (`mockup_combinations_review.php` → `generate_mockup_combination.php`):

1. **Analisis curatorial**: NO participa. `grep` de `GeminiArtworkAnalyzer` en `MockupCombinationEngine.php`, `GeminiMockupGenerator.php` y `generate_mockup_combination.php` no arroja resultados. El propio motor documenta esto en su codigo (`MockupCombinationEngine.php:261-262`): "No artwork analysis or curatorial ranking was used".
2. **Titulos generados**: participan, pero solo para el nombre de archivo, no para el contenido del prompt. `generate_mockup_combination.php:114-126` arma `$seoParams['artworkTitle']` desde `artworks.final_title` y lo pasa a `GeminiMockupGenerator::generate()`, que lo usa unicamente para `Display::generateSeoImageFilename()` (nombre de archivo SEO). No aparece en ningun bloque de texto enviado a Gemini.
3. **Descripciones curatoriales**: NO participan. `curatorial_reason`/`commercial_reason` se leen de `context_json` (`AdminPromptComposerPreview.php:228-229`) pero en el modo "mundo madre fijo" que usa el flujo actual, `MockupCombinationEngine::fixedSceneMotherContextJson()` los fuerza explicitamente a cadena vacia (`MockupCombinationEngine.php:697-698`) — es una decision de diseño deliberada, no un descuido.
4. **Seleccion de contexto desde imagen raiz**: NO participa. Ya confirmado en la Fase 2: `MockupCombinationEngine.php` no contiene ninguna referencia a `mockup_contexts`.
5. **Compatibilidades antiguas de contexto**: tecnicamente SE LLAMAN en cada generacion real, pero resultan no-op. Este es el hallazgo nuevo de esta fase.

### Hallazgo: el "prompt final" de la Fase 2 no era el texto 100% exacto — faltaba un paso

Se detecto revisando `generate_mockup_combination.php` que el texto que efectivamente llega a Gemini no es directamente `AdminPromptComposerPreview::compose()` (`final_prompt_preview`). Hay un paso mas:

- `generate_mockup_combination.php:128-136` arma `$contextId = 'combination_' . $combinationIndex` (un id **sintetico**, ej. `"combination_1"`, no un id real de `mockup_contexts`) y pasa `final_prompt_preview` como `metadata['prompt_passthrough_mode']` a `ServiceFactory::mockupGenerator()->generate(...)`.
- `GeminiMockupGenerator::finalPrompt()` (`GeminiMockupGenerator.php:99-113`), al ver `prompt_passthrough_mode`, llama a `MockupWorldVisualPromptEnhancer::enhancePromptForContextId($prompt, $contextId)` — es decir, **el sistema de "compatibilidades antiguas de contexto" (`MockupContextWorldRegistry`, `mockup_camera_context_compatibility.php`, `mockup_context_worlds.php`, etc.) SI se invoca en cada generacion real**, no solo en el preview.
- `GeminiMockupGenerator::generate()` (linea 33-38) antepone ademas un bloque fijo `IMAGE ROLE CONTRACT` que la Fase 2 tampoco mostraba.

Verificado empiricamente (no solo leido) con `php -r`, instanciando `MockupWorldVisualPromptEnhancer` real:
- Con los contextId sinteticos que usa el flujo real (`combination_1`, `combination_2`, `combination_14`), el enhancer devuelve el prompt **sin cambios** (`unchanged=true` en los 3 casos), porque `SELECT * FROM mockup_contexts WHERE id = :id` con un id no numerico no matchea ninguna fila.
- Como control negativo, se probo con un id real de `mockup_contexts` (2031, uno de los huerfanos de la Fase 2): el enhancer **si modifico el prompt**, agregando 2003 caracteres. Esto prueba que el sistema legacy sigue vivo y funcional, solo inerte por el desacople del id sintetico.

**Esto es una mina de tierra latente**: si en el futuro alguien "reconecta" el id sintetico a un id real de `mockup_contexts` (por ejemplo, al intentar arreglar el hallazgo de la Fase 2), este sistema completo de compatibilidades de contexto/mundo se reactivaria de golpe, sin revision, inyectando ~2000 caracteres no auditados en cada prompt.

`scratch/mockup_prompt_trace.php` (herramienta de la Fase 2) se actualizo para reflejar esto con exactitud: ahora reporta `true_final_prompt_sent_to_gemini` (IMAGE ROLE CONTRACT + salida real de `enhancePromptForContextId()`) ademas de `final_prompt_preview_from_admin_composer`, y verifica en cada corrida si el enhancer fue no-op. Re-verificado en los indices 1, 6 y 12 de la obra 400: autoverificacion de secciones OK, enhancer no-op confirmado, y el prompt verdadero empieza con `IMAGE ROLE CONTRACT:` como se espera.

### Conclusion de la Fase 4

El flujo actual esta mas limpio de lo que la auditoria original temia: 4 de los 5 riesgos listados simplemente no participan, y el quinto (compatibilidades de contexto) participa en el sentido de "se ejecuta" pero no en el de "afecta el resultado" — siempre que el id siga siendo sintetico. La recomendacion practica para Fase 7 es: en vez de solo "confirmar que no participa", habria que **desactivar explicitamente** la llamada a `MockupWorldVisualPromptEnhancer` en el flujo de combinacion directa (o documentar con un comentario fuerte en el codigo por que el id es sintetico a proposito), para que ese riesgo deje de depender de un accidente de tipos en una comparacion SQL.

### Correccion importante: existe una SEGUNDA via de generacion, activa hoy, donde el enhancer si actua

Antes de proponer como neutralizar la mina de tierra, se busco quien mas usa el mismo mecanismo (`prompt_passthrough_mode`) para no romper otro camino por accidente. Resultado: **hay dos flujos de generacion de mockups completamente distintos coexistiendo**, no solo el auditado en Fases 1-4:

1. **Combinacion directa** (`mockup_combinations_review.php` → `generate_mockup_combination.php` → `MockupCombinationEngine`): contextId sintetico (`combination_N`), enhancer dormido (confirmado). Este es el flujo que las Fases 1-4 auditaron.
2. **Batch/borrador desde contextos de analisis** (`report.php` boton "batch" en linea 2661 → `generate_mockup_batch.php` → `MockupBatchQueue::enqueueAndClaimContexts()` → tabla `mockup_generation_jobs` → `process_mockup_queue.php`; y `mockup_prompt_drafts_review.php` botones en lineas 812 y 911 → `generate_one_mockup_from_composed_admin_prompt.php`): usa **ids reales** de `mockup_contexts` (los mismos huerfanos que la Fase 2 encontro desconectados del flujo de combinacion). Aca `MockupWorldVisualPromptEnhancer` **si modifica el prompt de verdad** — es el mismo mecanismo, pero activo, no dormido.

Ambos botones (`report.php:2661`, `mockup_prompt_drafts_review.php:812,911`) estan conectados con `fetch()` real, no son codigo muerto en si mismos.

**Correccion 2026-07-01 (durante Fase 5):** `report.php` tiene su propio header `// LEGACY / DO NOT USE IN PHASE 2.3 FLOW` y, en su linea 9, si `LEGACY_MOCKUP_FLOW_ENABLED` no esta activo, **redirige** a `mockup_prompt_drafts_review.php` antes de renderizar nada — el usuario nunca ve el boton de batch de la linea 2661. Verificado: `LEGACY_MOCKUP_FLOW_ENABLED` no esta seteado en `.env` (default `false` en `config.php:73`). `generate_mockup_batch.php` y `process_mockup_queue.php` tienen el mismo gate (bloquean con error si el flag esta apagado); `process_mockup_queue.php` ademas es CLI-only. Es decir, **la cadena `report.php` → `generate_mockup_batch.php` → `MockupBatchQueue` → `process_mockup_queue.php` esta deshabilitada hoy completa**, no solo dormida por un accidente de tipos como el enhancer de la Fase 4. El unico llamador de la "segunda via" que sigue genuinamente vivo es `generate_one_mockup_from_composed_admin_prompt.php`, alcanzado desde `mockup_prompt_drafts_review.php` (que no tiene el gate `LEGACY_MOCKUP_FLOW_ENABLED`).

**Bug adicional encontrado en `generate_one_mockup_from_composed_admin_prompt.php` (lineas 72-79):** el script tiene un comentario "In passthrough mode, the final prompt sent to Vertex will be exactly $composedPrompt" y una "verificacion de seguridad" `$promptExactMatch = ($composedPrompt === $finalPromptSentToVertex)` donde `$finalPromptSentToVertex = $composedPrompt` — es una tautologia, compara la variable contra si misma. Como este script SI usa un `context_id` real, el enhancer real que corre despues (`GeminiMockupGenerator::finalPrompt()`) modifica el prompt sin que este chequeo lo detecte. El admin que usa esta herramienta cree que esta viendo "el prompt exacto" y no es asi.

**Consecuencia para la recomendacion:** no se puede "apagar" `MockupWorldVisualPromptEnhancer` de forma generica en `GeminiMockupGenerator::finalPrompt()` / `OpenAIMockupGenerator::finalPrompt()`, porque esa funcion es compartida por los 3 llamadores y en 2 de ellos (batch, admin single-generate) el enhancer es logica activa y con efecto real hoy, no una mina de tierra. Neutralizarlo ahi seria un cambio de comportamiento de produccion, no una limpieza.

### Recomendacion concreta (acotada al flujo de combinacion directa unicamente)

En vez de tocar la logica compartida, agregar un flag explicito nuevo que solo el llamador de combinacion directa activa, y que hace bypass total (ni siquiera corre el `SELECT` contra `mockup_contexts`):

1. **`generate_mockup_combination.php`** (dentro del array de opciones que ya arma en linea ~130-137): agregar `'skip_world_visual_enhancer' => true,`.
2. **`app/Services/GeminiMockupGenerator.php::finalPrompt()`**: al inicio del metodo, si `!empty($metadata['skip_world_visual_enhancer'])`, devolver `(string)($metadata['prompt_passthrough_mode'] ?? $contextPrompt)` directamente, sin instanciar `MockupWorldVisualPromptEnhancer` ni tocar la base de datos.
3. **`app/Services/OpenAIMockupGenerator.php::finalPrompt()`**: mismo patron, por si `ServiceFactory::mockupGenerator()` resuelve a OpenAI en vez de Gemini para esta misma combinacion (el flujo de combinacion es agnostico de proveedor).

Por que este diseño y no otro:

- **No cambia el resultado del flujo auditado**: hoy el enhancer ya es no-op ahi (verificado empiricamente en la Fase 4); despues del cambio seguira siendo no-op, pero de forma explicita y garantizada, no dependiente de que una comparacion SQL entre un id sintetico y una columna INT siga fallando por accidente.
- **No toca los otros dos llamadores** (`generate_one_mockup_from_composed_admin_prompt.php`, `process_mockup_queue.php`): al no activar el flag nuevo, su comportamiento actual (enhancer activo) queda intacto. Corregir el bug de esos dos es un trabajo aparte, con su propia decision de producto (¿el batch/borrador DEBERIA seguir usando compatibilidades antiguas de mundo, o tambien se quiere migrar a "solo camara + mundo madre"?).
- **Auto-documentado**: el nombre del flag explica la intencion (evitar el enhancer) en vez de depender de que alguien entienda por que un id con formato `"combination_1"` nunca matchea una columna `INT`.
- **Bajo riesgo / reversible**: son 3 cambios pequenos y localizados; revertir es trivial (quitar la clave del array de opciones).

Esta recomendacion queda documentada aqui pero **no aplicada** — implica editar codigo de produccion (`generate_mockup_combination.php`, `GeminiMockupGenerator.php`, `OpenAIMockupGenerator.php`), a diferencia de las Fases 1-4 que fueron estrictamente de lectura/diagnostico. Aplicarla requiere confirmacion explicita.

---

## Aplicado 2026-07-01: fix urgente + fix de la mina de tierra

Con confirmacion explicita del usuario, se aplicaron dos cambios de codigo de produccion, ambos chicos, acotados y verificados antes y despues del cambio.

### 1. Fix urgente: chequeo de seguridad tautologico en `generate_one_mockup_from_composed_admin_prompt.php`

El chequeo original (`$finalPromptSentToVertex = $composedPrompt; $promptExactMatch = ($composedPrompt === $finalPromptSentToVertex);`) comparaba una variable contra si misma — siempre `true`, nunca detecto nada. Se corrigio para calcular el valor real que va a recibir el generador (llamando a `MockupWorldVisualPromptEnhancer::enhancePromptForContextId()` con el mismo `context_id` real que usa `GeminiMockupGenerator::finalPrompt()` internamente), y se agrego una advertencia no bloqueante en el JSON de auditoria (`warnings`) cuando hay mismatch, en vez de lanzar una excepcion que podria romper impredeciblemente un flujo que ya "funcionaba" (aunque mintiendo sobre el prompt real). No se toco la llamada real al generador: lo unico que cambia es que la auditoria ahora dice la verdad.

Verificado con el mismo contexto real usado en la Fase 4 (`mockup_contexts.id=2031`): el chequeo corregido ahora reporta `exact_match=false`, `diff_chars=2003` — coincide exactamente con lo medido en la Fase 4.

### 2. Fix de la mina de tierra: bypass explicito en el flujo de combinacion directa

- `generate_mockup_combination.php`: se agrego `'skip_world_visual_enhancer' => true` a las opciones pasadas al generador, con comentario explicando por que.
- `app/Services/GeminiMockupGenerator.php::finalPrompt()`: si `skip_world_visual_enhancer` esta presente, devuelve el prompt directamente sin instanciar `MockupWorldVisualPromptEnhancer` ni tocar la base de datos.
- `app/Services/OpenAIMockupGenerator.php::finalPrompt()`: mismo patron, por si el proveedor activo es OpenAI para esta combinacion.

**No se toco** `generate_one_mockup_from_composed_admin_prompt.php` ni `process_mockup_queue.php` — ahi el enhancer sigue activo exactamente como antes, porque no envian el flag nuevo.

Verificacion de que el comportamiento del flujo de combinacion no cambio (via Reflection sobre `finalPrompt()`, sin llamar a Gemini): la salida del codigo nuevo (`skip_world_visual_enhancer=true`) es **identica byte a byte** a la salida del codigo viejo, tanto en `GeminiMockupGenerator` como en `OpenAIMockupGenerator`, para la combinacion 1 de la obra 400. `scratch/mockup_prompt_trace.php` se actualizo para reflejar el bypass explicito (en vez de simular la llamada al enhancer) y se re-corrio en los indices 1, 6, 12 y 14 de la obra 400: autoverificacion de secciones OK en los 4, bypass confirmado, y el chequeo defensivo (que confirma que el enhancer *habria sido* no-op de todos modos) tambien OK en los 4.

Se re-corrio ademas la suite completa de la Fase 3 (`php tests/run_regression_tests.php`) despues de estos cambios: **67/67 siguen pasando**, sin tocar ningun snapshot.

Los 3 archivos de produccion pasan `php -l` sin errores de sintaxis.

---

## Fase 5: unificar escala y dominancia (resultado, 2026-07-01)

### Escala/dominancia en texto: ya unificada en politica fotografica

Verificado (Fase 1/2 + esta fase): `ArtworkScalePolicy` y `ArtworkDominancePolicy` ya usan lenguaje fotografico explicito ("Do not enlarge the artwork... only because the camera is closer, the crop is tighter, or the lens compresses the view"), no porcentajes rigidos. Se revisaron ademas los archivos de config activos en el flujo batch/admin (`mockup_context_families.php`, `mockup_scene_variants.php`, los mismos que alimenta `MockupWorldVisualPromptEnhancer`): ningun `fill_ratio`, porcentaje de relleno de pared/canvas, ni regla de escala rigida. La parte textual de esta fase ya estaba resuelta antes de esta auditoria.

### `vertex_bridge.py`: hallazgo estructural mayor sobre precomposicion/escala fisica

Se leyo el archivo completo (805 lineas). El bloque que impone escala fisica por codigo (calculo de `fill_ratio` segun tamano real en cm, canvas gris de composicion, correccion matematica por `ARTWORK SIZE CORRECTION`, multiplicador de escala humana — lineas 285-469) esta condicionado a:

```python
if not gemini_reference_images and is_mockup and MOCKUP_USE_PRECOMPOSITION:
```

`gemini_reference_images` se llena cuando el modelo es un modelo Gemini de imagen (`is_gemini_image`) y se mandan 2 o mas imagenes (`len(args.image) > 1`). Esto importa porque:

- El flujo de combinacion directa (`generate_mockup_combination.php` → `GeminiMockupGenerator::generate()`) **siempre** manda al menos 2 imagenes: la obra raiz y la referencia de mundo madre (`generate_mockup_combination.php` valida `is_file($worldMotherPath)` antes de generar; `GeminiMockupGenerator.php` arma `$parts` con la obra + el mundo madre).
- El modelo por defecto es `gemini-3.1-flash-image` (`ProviderSettings::geminiImageModel()`), que cumple `is_gemini_image = True`.
- Por lo tanto, para este flujo, `gemini_reference_images` **siempre** esta poblado → el bloque de precomposicion/fill_ratio es **estructuralmente inalcanzable**, sin importar el valor de `MOCKUP_USE_PRECOMPOSITION`. No es que el flag este en `false` hoy (que tambien): es que ni siquiera se llega a leer el flag para este flujo. Esto es una garantia mas fuerte que un simple flag, y responde de forma definitiva a la pregunta que dejaba abierta la auditoria original ("puede estar imponiendo escala aunque el prompt sea correcto"): no, no puede, para este flujo, por estructura de codigo.

**Pero hay una asimetria**: el flujo batch/admin-single-generate (`generate_one_mockup_from_composed_admin_prompt.php`, encontrado activo en la Fase 4) **no manda `world_mother_reference_path`** — solo la obra raiz, una sola imagen. Ahi `gemini_reference_images` queda vacio, y el bloque de precomposicion/fill_ratio **si es estructuralmente alcanzable**. Hoy no se ejecuta porque `MOCKUP_USE_PRECOMPOSITION=false` en `.env` y ademas `MOCKUP_PROMPT_FIRST_MODE=true` lo fuerza a `false` — pero, a diferencia del flujo de combinacion, aca la proteccion depende **solo del flag**, sin respaldo estructural. Es el mismo patron de "mina de tierra" que la Fase 4 encontro con `MockupWorldVisualPromptEnhancer`, pero para escala fisica: si algun dia se reactiva `MOCKUP_USE_PRECOMPOSITION` (por ejemplo al apagar `MOCKUP_PROMPT_FIRST_MODE` para otra prueba), este flujo especifico volveria a imponer un `fill_ratio` fisico duro sobre la imagen, exactamente el comportamiento que esta auditoria quiere eliminar.

### Hallazgo secundario: el log de auditoria de escala nunca se escribe para el flujo activo hoy

`handle_generate_image()` tiene un `return` en la linea 585 apenas termina de guardar la imagen generada con un modelo Gemini (`is_gemini_image = True`, el caso real hoy). El bloque que escribe `logs/vertex_bridge.log` con `use_precomposition=`, `grey_canvas_used=`, `generation_mode=`, etc. (lineas 693-752) esta **despues** de ese `return` — es codigo alcanzable solo por las ramas viejas basadas en Imagen (`edit_image`/`generate_images`), no por el path Gemini multimodal activo hoy.

Verificado empiricamente: `logs/vertex_bridge.log` no tiene entradas desde **2026-06-25 09:31**, y las ultimas entradas registradas usan `model_used=imagen-3.0-capability-001` (el modelo Imagen viejo, no el Gemini actual). Osea: aunque el archivo existe y en apariencia "audita" la escala, esta desactualizado y no refleja ninguna generacion reciente. Cualquiera que lo consulte hoy para confirmar "hubo precomposicion en esta generacion" va a encontrar silencio, no una confirmacion de que no la hubo.

### Conclusion de la Fase 5

- La politica de escala/dominancia en texto ya cumple el objetivo de esta fase (lenguaje fotografico, no porcentajes) tanto en el flujo auditado como en el batch/admin.
- `vertex_bridge.py` **no** impone escala fisica en el flujo de combinacion directa, por garantia estructural (no por flag). Confirma y cierra la sospecha central de la auditoria original para ese flujo.
- Persiste un riesgo real y no neutralizado en el flujo batch/admin-single-generate: ahi la proteccion es solo el flag `MOCKUP_USE_PRECOMPOSITION`, sin respaldo estructural. Candidato a Fase 7: si ese flujo tambien deberia mandar una imagen de mundo madre (ganando la misma proteccion estructural), o si se prefiere neutralizar con el mismo patron de flag explicito usado en la Fase 4.
- El log de escala (`logs/vertex_bridge.log`) es observabilidad muerta para el path Gemini activo; si se quiere trazabilidad real de precomposicion por generacion (el punto pendiente que dejo abierto la Fase 2), habria que mover ese logging a un lugar que se ejecute siempre, no solo en las ramas Imagen legacy.

### Aplicado 2026-07-01: neutralizar la mina de tierra de precomposicion en `generate_one_mockup_from_composed_admin_prompt.php`

Con confirmacion del usuario, se aplico el mismo patron de flag explicito de la Fase 4, adaptado a que esta vez el punto de riesgo esta del lado Python (variables de entorno del subproceso), no en una tabla de base de datos:

1. **`app/Services/GeminiImageClient.php`**: `generateImage()` ahora acepta un tercer parametro opcional `array $envOverrides = []`, propagado a `runCommand()`, que lo mergea sobre el entorno del proceso (`array_merge($this->pythonProcessEnv(), $envOverrides)`) justo antes de `proc_open()`. Si no se pasan overrides, el comportamiento es identico al anterior (verificado).
2. **`app/Services/GeminiMockupGenerator.php::generate()`**: si `metadata['force_disable_precomposition']` esta presente, arma `['MOCKUP_USE_PRECOMPOSITION' => 'false']` y lo pasa como override a `generateImage()`. Sin ese flag, no se pasa ningun override (mismo comportamiento de siempre).
3. **`generate_one_mockup_from_composed_admin_prompt.php`**: agrega `'force_disable_precomposition' => true` a las opciones — es el unico llamador vivo hoy que manda una sola imagen de referencia (root artwork, sin `world_mother_reference_path`), por lo que es el unico expuesto a que el bloque de precomposicion de `vertex_bridge.py` se vuelva alcanzable si alguna vez se reactiva `MOCKUP_USE_PRECOMPOSITION` globalmente.

No se toco `OpenAIMockupGenerator.php` (no usa `vertex_bridge.py` ni ese flag) ni `process_mockup_queue.php`/`generate_mockup_batch.php` (ya deshabilitados por `LEGACY_MOCKUP_FLOW_ENABLED=false`, ver correccion mas arriba).

Verificacion: `pythonProcessEnv()` base confirma `MOCKUP_USE_PRECOMPOSITION=false` hoy; se probo la logica de merge exacta (`$envOverrides ? array_merge(...) : $this->pythonProcessEnv()`) via Reflection sin lanzar el subproceso real — sin overrides, el resultado es identico byte a byte al comportamiento anterior; con un valor de prueba distinto, el override efectivamente lo pisa, confirmando que el mecanismo funciona. Los 3 archivos pasan `php -l`. Se re-corrio la suite completa de Fase 3 (67/67) y el diagnostico de Fase 2/4 sobre la obra 400 (autoverificacion OK) despues de este cambio, sin regresiones.

---

## Fase 6: revisar mundo madre (resultado, 2026-07-01)

### Mundo madre congelado vs. referencia flexible: limpio

Se releyo `MockupCombinationEngine::cameraReferenceMode($slotId)` (linea 717-729): asigna `'reconstructed_view'` a 8 camera slots (los 4 de detalle + nadir/contrapicado/aereo — los angulos mas extremos) y `'literal_scene_view'` a los 6 restantes (diagonales, contrapicado, obra apoyada, reflejo dorado, pasillo, recovecos). Esto es exactamente la dualidad que la auditoria original sospechaba como problema ("layout rigido en algunas camaras, flexible en otras"). Pero al leer el texto real que produce cada rama (`contextProposalForComposer()`, lineas 691-696), **ninguna de las dos congela la composicion**:

- `reconstructed_view`: "the room must be rebuilt from the selected camera viewpoint rather than preserving the source photo layout" — divergencia fuerte permitida, esperable para camaras extremas que ninguna foto de habitacion podria mostrar literalmente.
- `literal_scene_view`: "the scene keeps the supplied world mother visual identity without freezing the source photo composition" — pide reconocibilidad visual, pero explicitamente **tambien** rechaza congelar la composicion.

Es un diseno graduado e intencional (mas permiso de divergencia para camaras mas extremas), no una inconsistencia. Reforzado por `WorldMotherCameraAuthorityPolicy.php` (leida en Fase 1): sus 3 variantes (DETAIL/ENVIRONMENT/BALANCED) coinciden en que "The selected camera slot remains the highest authority for viewpoint, crop, lens behavior, camera height, tilt, distance, and perspective" — ninguna variante le pide a Gemini copiar el layout de la foto madre. Este punto de la Fase 6 esta resuelto; no requiere cambios.

### `WorldMotherGenerator.php`: mejor de lo que la auditoria original sospechaba

Se leyo el archivo completo (665 lineas). El sintoma original ("Mundos madre repetitivos... prompt con poca exigencia de variacion compositiva") no se sostiene al leer el prompt actual:

- `buildGenerationPrompt()` (linea 514-544, prompt base compartido) ya incluye: "Favor environments with real perspective and spatial travel: diagonal room axes, receding floor or ceiling lines, side planes, corridors, mezzanines, openings, stairs, windows, columns... Avoid static flat-on symmetrical room records."
- `generateOriginalWorldMotherSet()` (la que genera el set de 4, linea 147-239) agrega instrucciones anti-clonado explicitas: "Across the set, avoid repeating the same camera, wall, ceiling, window, furniture, and object layout. The variants must feel related, not cloned." + "Avoid a set dominated by flat frontal rooms."
- Ademas usa 4 **roles de variante distintos** (`worldMotherVariantRoles()`, linea 549-577: `primary_wall`, `oblique_depth`, `light_drama`, `architectural_context`), cada uno con su propia composicion pedida (una explicitamente diagonal/profunda, otra dirigida por luz/sombra, otra arquitectonica-ancha), inyectados por separado en cada una de las 4 generaciones del set.

Esto ya cumple lo que pedia esta fase a nivel de texto. No se puede verificar si las imagenes generadas realmente salen variadas sin gastar llamadas reales a Gemini (fuera de alcance de una auditoria de lectura); si el sintoma de "cuatro generaciones muy similares" persiste hoy, ya no es por falta de exigencia en el prompt, habria que mirar el comportamiento del modelo en si.

### Hallazgo: la misma mina de tierra de la Fase 5, una tercera vez, sin ninguna proteccion

El prompt de `buildGenerationPrompt()` empieza con "Create one original WORLD MOTHER reference image for future artwork **mockups**." — verificado con `str_contains(strtolower($prompt), 'mockup')` = `true`. Esto importa porque `vertex_bridge.py` clasifica `is_mockup = "mockup" in args.prompt.lower()`, sin distinguir "esto es una generacion de mockup" de "esto es una generacion de mundo madre que menciona la palabra mockup una vez en su proposito".

`generateOriginalWorldMother()` (linea 77-139, el caso de referencia unica) llama a `$this->client->generateImage([textPart($prompt), imagePart($referencePath)])` — **una sola imagen**. `generateOriginalWorldMotherSet()` tambien manda una sola imagen por llamada cuando el admin sube una unica referencia (`foreach ($referencePaths as $referencePath) { $parts[] = ...imagePart... }` con `count($referencePaths) === 1`). En ambos casos, `len(args.image) == 1` en `vertex_bridge.py`, por lo que `gemini_reference_images` queda vacio y el bloque de precomposicion/fill_ratio (lineas 285-469) **es estructuralmente alcanzable** si `MOCKUP_USE_PRECOMPOSITION` se reactivara — exactamente el mismo patron que en `generate_one_mockup_from_composed_admin_prompt.php` (Fase 5), pero aca seria conceptualmente peor: el regex de escala (`(\d+) cm wide x (\d+) cm high`) nunca va a matchear nada en un prompt de mundo madre, asi que caeria al `mockup_fill_default` (0.35) y compondria la foto de referencia pegada en un cuadrado gris dentro de un canvas de 1024x1024 antes de generar — una operacion que no tiene ningun sentido para una imagen de ambiente/habitacion, solo para una obra de arte sobre pared.

Ademas, a diferencia de `generate_one_mockup_from_composed_admin_prompt.php`, esta llamada usa `GeminiImageClient::generateImage()` **directamente** (`WorldMotherGenerator` no pasa por `GeminiMockupGenerator`), asi que el fix de la Fase 5 (`force_disable_precomposition` en `GeminiMockupGenerator::generate()`) no la cubre en absoluto.

Hoy no causa dano porque `MOCKUP_USE_PRECOMPOSITION=false` globalmente (mismo respaldo de siempre, sin garantia estructural). Queda documentado como candidato directo a Fase 7: aplicar el mismo patron de override explicito (`$envOverrides` ya existe en `GeminiImageClient::generateImage()`, agregado en la Fase 5) a las 2-3 llamadas de `WorldMotherGenerator.php` que usan una sola imagen de referencia.

### Conclusion de la Fase 6

- Mundo madre como referencia flexible vs. layout congelado: **limpio**, diseno intencional y coherente, no requiere cambios.
- Calidad y variacion del prompt de generacion de mundos madre: **ya resuelto** a nivel de texto (roles de variante + lenguaje anti-clonado explicito); pendiente de validar con generaciones reales si el sintoma persiste.
- **No limpio del todo**: `WorldMotherGenerator.php` comparte la misma vulnerabilidad de precomposicion que las Fases 4-5 encontraron en otros dos lugares, sin ninguna proteccion aplicada todavia. Es la tercera aparicion del mismo patron.

### Aplicado 2026-07-01: neutralizar la mina de tierra de precomposicion en `WorldMotherGenerator.php`

Con confirmacion del usuario, se agrego un metodo privado `precompositionOverride(): array { return ['MOCKUP_USE_PRECOMPOSITION' => 'false']; }` y se paso como tercer argumento (`$envOverrides`, el mecanismo agregado en la Fase 5) a las **3** llamadas a `GeminiImageClient::generateImage()` de la clase: `generateOriginalWorldMother()` (linea ~99), `generateOriginalWorldMotherSet()` (linea ~194) y `generateOriginalWorldMotherForCategory()` (linea ~266).

A diferencia del fix de la Fase 5 (condicional, solo para el unico llamador expuesto), aca se aplico **a las 3 llamadas sin condicion**, incluida `generateOriginalWorldMotherForCategory()` que hoy ya es inmune (manda 0 imagenes, por lo que `vertex_bridge.py` ni siquiera entra al bloque `if args.image:`). Se opto por esto porque la premisa de fondo es mas simple y mas dificil de romper por accidente: *la generacion de mundos madre nunca deberia pasar por precomposicion de mockup, sin importar cuantas imagenes de referencia se manden* — en vez de tener que recordar, cada vez que alguien toque este archivo en el futuro, cuales llamadas especificas son "las que mandan 1 imagen y por lo tanto estan expuestas".

No se toco `GeminiMockupGenerator.php` ni `generate_mockup_combination.php` ni `generate_one_mockup_from_composed_admin_prompt.php` (fixes de fases anteriores, sin cambios).

Verificado: `precompositionOverride()` devuelve `{"MOCKUP_USE_PRECOMPOSITION":"false"}` via Reflection; la firma de `generateImage()` acepta el tercer parametro `$envOverrides` con default `[]` (confirmado por Reflection, sin romper compatibilidad con otros llamadores que no lo pasan). `php -l` limpio. Suite de Fase 3 completa (67/67) y `scratch/mockup_prompt_trace.php` sobre la obra 400 (autoverificacion OK) re-corridos despues del cambio, sin regresiones.

---

## Fase 7: reducir, no parchear (resultado, 2026-07-01)

Esta fase no tenia una lista fija de tareas en la propuesta original — su objetivo era consolidar todo lo encontrado en Fases 1-6 en un plan de reduccion concreto, en vez de seguir agregando capas. Este es ese consolidado.

### A. Ya aplicado en esta auditoria (codigo de produccion, verificado antes/despues de cada cambio)

1. Corregido el chequeo de seguridad tautologico en `generate_one_mockup_from_composed_admin_prompt.php` (ahora calcula el valor real en vez de compararse contra si mismo).
2. Bypass explicito `skip_world_visual_enhancer` en el flujo de combinacion directa (`generate_mockup_combination.php` + `GeminiMockupGenerator`/`OpenAIMockupGenerator`).
3. Bypass explicito `force_disable_precomposition` en `generate_one_mockup_from_composed_admin_prompt.php` (mecanismo `$envOverrides` nuevo en `GeminiImageClient`).
4. Mismo bypass, sin condicion, en las 3 llamadas de generacion de `WorldMotherGenerator.php`.

Los 4 cambios son aditivos/de bypass, no eliminaron nada, y los 4 se verificaron como no-op de comportamiento (mismo resultado que antes, solo con garantia explicita en vez de accidental).

### B. Confirmado limpio, no requiere cambios

- Escala/dominancia en texto: ya en lenguaje fotografico (Fase 1/5).
- Precomposicion en `vertex_bridge.py` para el flujo de combinacion directa: estructuralmente inalcanzable, no solo apagada por flag (Fase 5).
- Dualidad de referencia de mundo madre por camara (`reconstructed_view`/`literal_scene_view`): diseno intencional, ninguna rama congela la composicion (Fase 6).
- Prompt de generacion de mundos madre: ya tiene lenguaje anti-repeticion explicito y roles de variante distintos (Fase 6).

### C. Seguro eliminar ahora — candidato concreto, no ejecutado todavia

**`app/Services/CoreArtworkJsonBuilder.php`**: confirmado en la Fase 3 con `grep` exhaustivo en todo el repositorio que **ningun archivo la instancia** (no esta en `app/bootstrap.php`, no aparece en ningun `new CoreArtworkJsonBuilder()` fuera de si misma). Los archivos que leen `analysis/{id}.core.json` (`AdminPromptComposerPreview.php`, `core_review.php`, `MockupCameraArchetypeResolver.php`, `MockupBranchContextBuilder.php`, etc.) solo hacen `file_get_contents`/`is_file` sobre el archivo ya existente en disco — nunca reconstruyen uno nuevo llamando a esta clase. Eliminar el archivo no cambiaria ningun comportamiento observable hoy: los `.core.json` existentes en disco seguirian siendo leidos igual; simplemente dejaria de existir codigo muerto que nadie va a volver a ejecutar. Es el candidato de reduccion mas seguro y directo de toda la auditoria. **Aplicado 2026-07-01, con confirmacion explicita del usuario**: se re-verifico con `grep` justo antes de borrar (mismo resultado: cero llamadores fuera de si misma y de la suite de tests), se elimino `app/Services/CoreArtworkJsonBuilder.php`, y se ajusto `tests/regression/root_artwork_test.php` quitando la seccion que probaba sus metodos privados via Reflection (18 aserciones) — testear metodos de una clase eliminada no protege nada. La suite de Fase 3 bajo de 67 a **49/49** aserciones, todas pasando. Se re-corrio ademas `scratch/mockup_prompt_trace.php` sobre la obra 400 despues del borrado: autoverificacion sigue en `true` (confirma que `AdminPromptComposerPreview::compose()`, que lee `analysis/{id}.core.json` directamente del disco con `file_get_contents`, no se vio afectado — nunca dependio de esta clase para funcionar). `grep` final en todo el repo: la unica mencion restante de `CoreArtworkJsonBuilder` es el comentario explicativo en el test y esta misma auditoria.

### D. Requiere decision de producto antes de tocar — no son limpieza, son arquitectura

1. **El fork de dos sistemas de generacion de mockup.** `mockup_combinations_review.php` (mundo madre directo, sintetico, auditado a fondo en Fases 1-5) y `mockup_prompt_drafts_review.php` → `generate_one_mockup_from_composed_admin_prompt.php` (contextos reales de `mockup_contexts`, con analisis y compatibilidades de mundo) son dos flujos completos y vivos que componen el prompt de forma distinta, para el mismo problema. Antes de "reducir" cualquiera de los dos hace falta decidir: ¿ambos se quedan (uno para revision rapida, otro para casos con analisis curatorial completo)? ¿se migra el segundo al modelo de mundo madre directo y se deprecia `mockup_contexts`? ¿se documenta la diferencia y se deja asi? Sin esa decision, cualquier "limpieza" de uno de los dos corre el riesgo de romper el otro.
2. **Todo el pipeline que alimenta ese segundo flujo**: `MockupContextEngine.php`, `MockPromptBuilder.php` (en su rol de fallback de analisis, no en el de mockups), `MockContextSelector.php`, `context_library.php`, `MockupCameraArchetypeResolver.php`, `mockup_camera_archetypes.php`, y la capa Fase 2.9 completa (`MockupContextIdentity.php`, `MockupVitalPresenceResolver.php`). Ninguno de estos es codigo muerto — todos siguen corriendo y siendo consumidos por el flujo D.1 y por paginas de display admin (`artwork.php`, `admin_mockup_prompts_status.php`, `mockup_prompt_drafts_review.php`). No se pueden tocar sin resolver primero la decision de D.1.
3. **La cadena deshabilitada por flag**: `report.php`, `generate_mockup_batch.php`, `process_mockup_queue.php`, `mockup_batch_wait.php`, `generate_mockup.php` — todos gateados por `LEGACY_MOCKUP_FLOW_ENABLED` (false hoy) y varios con su propio header `// LEGACY / DO NOT USE IN PHASE 2.3 FLOW`. El codigo mismo ya declara la intencion de deprecarlos; falta decidir si se archivan/eliminan definitivamente o se mantienen apagados como fallback de emergencia.

### E. Baja prioridad / observabilidad

- `logs/vertex_bridge.log`: el bloque que escribe `use_precomposition=`, `grey_canvas_used=`, etc. es inalcanzable para el modelo Gemini activo hoy (Fase 5). Si se quiere trazabilidad real de precomposicion por generacion, mover ese logging a un punto que se ejecute siempre en `handle_generate_image()`, no solo en las ramas Imagen legacy.

### Resumen para decidir el proximo paso

De las 5 categorias, la unica accion inmediata, de bajo riesgo y sin dependencias es la seccion C (borrar `CoreArtworkJsonBuilder.php`). Todo lo demas en "reducir" (seccion D) requiere primero una decision de producto sobre si el sistema debe tener uno o dos caminos de generacion de mockup — decision que esta auditoria puede informar con evidencia, pero no puede tomar por su cuenta.

---

## Decision de producto (2026-07-01): un solo flujo de mockup, el analisis curatorial se reemplaza por un modulo nuevo

El usuario confirmo dos cosas: (1) `mockup_prompt_drafts_review.php` (flujo 2) ya no se usa en la operacion real, y (2) el analisis curatorial (titulos, descripcion, razonamiento de contexto) tampoco aporta valor hoy — se planea construir un modulo nuevo para eso mas adelante, no reutilizar el actual.

Investigando el limite exacto antes de apagar nada se encontro que `GeminiArtworkAnalyzer::analyze()` (el unico llamador es `analyze.php`) hace en una sola funcion tanto el perfil curatorial de la obra como la seleccion de contextos de mockup — no estan separados en metodos ni archivos distintos, asi que no se podia apagar "solo la parte de mockups" sin tocar tambien el perfil. Ademas se confirmo que `mockup_prompt_drafts_review.php` depende en cascada de `MockupBranchPromptDraftBuilder`/`MockupBranchContextBuilder`, que a su vez requieren `analysis/{id}.core.json` — el archivo que generaba `CoreArtworkJsonBuilder`, ya eliminado (y que, segun la Fase 3, no tenia llamadores desde antes de esta auditoria). Es decir, esta cadena ya estaba efectivamente rota para cualquier obra nueva, y solo "funcionaba" para obras viejas con archivos cacheados en disco.

Con esa confirmacion, se ejecuto el **Nivel 1** del plan de reduccion (ver seccion D mas arriba): apagar los puntos de entrada sin borrar nada todavia, reutilizando el mismo flag `LEGACY_MOCKUP_FLOW_ENABLED` que ya bloqueaba `report.php`/`generate_mockup_batch.php`/`process_mockup_queue.php` (consistencia: un solo flag = "el sistema legacy de analisis/contexto de mockup esta apagado").

### Aplicado: Nivel 1 — puntos de entrada bloqueados

Se agrego el mismo gate (`if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) { ... }`) a:

- `analyze.php`, `reanalyze.php`, `regenerate_mockup_proposals.php` — responden JSON `{"ok": false, "error": "..."}` con status 400, antes de tocar Auth o la base de datos.
- `generate_one_mockup_from_composed_admin_prompt.php` — mismo patron JSON.
- `mockup_prompt_drafts_review.php` — despues de confirmar que la obra existe y pertenece al usuario (para poder armar el redirect), redirige a `mockup_combinations_review.php?id=<id>` en vez de mostrar la pagina.

Se actualizo ademas `report.php` (linea ~21): su propio redirect cuando el flag esta apagado apuntaba a `mockup_prompt_drafts_review.php?id=`, lo que ahora hubiera generado una doble redireccion (a `mockup_combinations_review.php`). Se cambio para apuntar directo al destino final.

**No se borro ningun archivo en este paso.** Es 100% reversible: alcanza con poner `LEGACY_MOCKUP_FLOW_ENABLED=true` en `.env` para reactivar todo exactamente como estaba.

Verificacion: los 6 archivos pasan `php -l`. Se confirmo con `php -r` que `LEGACY_MOCKUP_FLOW_ENABLED` esta definida como `false` en este entorno y que la condicion del gate evalua a "bloqueado" tal como se espera hoy. Se re-corrio la suite de Fase 3: **49/49** siguen pasando (estos 6 archivos no tienen cobertura de tests directa, pero comparten `bootstrap.php`/clases con lo que si se prueba, y no se detecto ninguna regresion colateral).

### Aplicado: Nivel 2 — borrado real de las clases huerfanas (2026-07-01, con confirmacion del usuario)

Antes de borrar se investigo el grafo de dependencias completo con `grep` dirigido (no solo el listado original), lo que encontro dos cosas que cambiaron el alcance:

1. **`mockup_branches_review.php`** era un sexto punto de entrada sin gatear (se me habia pasado en el Nivel 1): pagina que muestra `analysis/{id}.branches.json`, generandolo on-demand via `MockupBranchContextBuilder::buildForArtwork()` si no existe. Esa clase requiere `analysis/{id}.core.json` — el archivo que generaba `CoreArtworkJsonBuilder`, ya eliminado y sin llamadores desde antes de esta auditoria (Fase 3). Es decir, esta pagina ya estaba rota para cualquier obra nueva. Se agrego a la lista de borrado.
2. **`social_video.php`, `social_video_timeline.php` y `artwork.php`** tambien leen la tabla `artwork_analysis` (la que llenaba `analyze.php`). Se investigo el detalle: `social_video.php` tiene un `require .../social_video_simple.php'; exit;` en la linea 4-5 — toda su logica real vive en `social_video_simple.php`, que **no** referencia `artwork_analysis` en absoluto (la consulta que se veia en `social_video.php` es codigo muerto inalcanzable). `social_video_timeline.php` si hace una consulta real a `artwork_analysis`, pero el resultado solo enriquece el contexto de un prompt de IA (`SocialVideoService::conceptFromTimeline()`) con degradacion graceful si viene vacio — no es un requisito duro. Ninguna de las tres paginas llama a ninguna de las clases que se estaban por borrar (solo hacen `SELECT` directo contra la tabla). Conclusion: seguro borrar las clases; el unico efecto colateral (que la tabla `artwork_analysis` no reciba filas nuevas) ya era consecuencia del Nivel 1, no de este paso.

Se verifico ademas que `ServiceFactory::artworkAnalyzer()` era el unico punto que instanciaba los 3 analizadores, y que su unico llamador era el ya-gateado `analyze.php`; y que `ArtworkAnalyzerInterface`/`ContextSelectorInterface` solo las implementaban las clases que se iban a borrar.

**Archivos eliminados (16):**

`app/Services/MockupContextEngine.php`, `app/Services/MockPromptBuilder.php`, `app/Services/MockContextSelector.php`, `app/Data/context_library.php`, `app/Services/MockupCameraArchetypeResolver.php`, `app/Data/mockup_camera_archetypes.php`, `app/Services/MockupContextIdentity.php`, `app/Services/MockupVitalPresenceResolver.php`, `app/Services/GeminiArtworkAnalyzer.php`, `app/Services/OpenAIArtworkAnalyzer.php`, `app/Services/MockArtworkAnalyzer.php`, `app/Services/MockupBranchContextBuilder.php`, `app/Services/MockupBranchPromptDraftBuilder.php`, `app/Contracts/ArtworkAnalyzerInterface.php`, `app/Contracts/ContextSelectorInterface.php`, `mockup_branches_review.php`.

**Archivos editados para no dejar referencias colgando:**

- `app/bootstrap.php`: se quitaron las 10 lineas `require_once` correspondientes a los archivos borrados.
- `app/Services/ServiceFactory.php`: se elimino el metodo `artworkAnalyzer()` completo (sin llamador vivo).
- `core_review.php`: se quito el boton "View Mockup Branch Contexts" que apuntaba a la pagina borrada.

**Deliberadamente NO tocado**, y por que:

- `app/Services/MockupContextWorldRegistry.php` y sus 4 configs (`mockup_context_worlds.php`, `mockup_context_families.php`, `mockup_scene_variants.php`, `mockup_camera_context_compatibility.php`): siguen siendo `require`idos por `MockupWorldVisualPromptEnhancer.php`, que a su vez sigue siendo llamado (aunque bypasseado por flag) desde `GeminiMockupGenerator.php`/`OpenAIMockupGenerator.php` en el flujo de combinacion directa activo. Borrar esto rompería la carga de clases de esos generadores. **Nota para una pasada futura**: con `generate_one_mockup_from_composed_admin_prompt.php` ahora gateado (Nivel 1), este enhancer perdio su unico llamador que lo activaba de verdad — es candidato a quedar tambien completamente huerfano, pero se dejo fuera de esta pasada para no expandir el alcance ya verificado.
- La tabla `mockup_contexts` y `artwork_analysis` en la base de datos: se dejan intactas con sus datos historicos. Nada las borra ni las modifica; simplemente nada vuelve a escribirles.
- Los 10 archivos ya gateados en el Nivel 1 (`analyze.php`, `reanalyze.php`, `regenerate_mockup_proposals.php`, `mockup_prompt_drafts_review.php`, `generate_one_mockup_from_composed_admin_prompt.php`, `report.php`, `generate_mockup_batch.php`, `process_mockup_queue.php`, `mockup_batch_wait.php`, `generate_mockup.php`): quedan como estaban, con su codigo muerto (ahora referenciando clases inexistentes) inalcanzable detras del gate — verificado que esto no causa errores de PHP porque las referencias a clases dentro de un cuerpo de funcion no se resuelven hasta que esa linea se ejecuta, y esas lineas ya no se ejecutan nunca.

**Verificacion:**

- `php -l` limpio en los 3 archivos editados y en los 6 archivos que aun mencionan (como texto muerto o codigo inalcanzable) los nombres de las clases borradas.
- `php -r "require 'app/bootstrap.php';"` corre sin errores — la aplicacion entera sigue cargando.
- `grep` final en todo el repo por los 15 nombres de clase/interfaz borrados: los unicos matches restantes son (a) los 4 archivos ya gateados donde la instanciacion quedo inalcanzable, (b) un comentario de texto en `compare_mockup_prompt_composition.php` (`'code' => 'MockupContextEngine.php:151'`, un string de diagnostico, no codigo ejecutable), y (c) una comparacion de string inofensiva en `sidebar.php` (`$currentPage === 'mockup_branches_review.php'`, nunca vuelve a ser true).
- Suite de Fase 3: **49/49** siguen pasando.
- `scratch/mockup_prompt_trace.php` sobre la obra 400: autoverificacion sigue en `true` — el flujo de combinacion directa, que es el unico que queda activo, no se vio afectado en absoluto por este borrado.

### Resumen final del estado del sistema post-auditoria

Un solo flujo de generacion de mockup activo (combinacion directa con mundo madre), con un solo compositor de prompt (`AdminPromptComposerPreview`), sin compatibilidades legacy activas por accidente, sin precomposicion fisica alcanzable, sin analisis curatorial corriendo de fondo, y sin ~2500 lineas de codigo huerfano repartidas en 17 archivos que ya no le servian a nadie. Lo que queda pendiente para una decision futura (no urgente): si tambien se quiere apagar `MockupWorldVisualPromptEnhancer`/`MockupContextWorldRegistry`, que quedo sin ningun llamador que lo active de verdad tras el Nivel 1.


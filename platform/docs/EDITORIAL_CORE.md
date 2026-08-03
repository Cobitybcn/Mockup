# EDITORIAL CORE — Constitución del motor editorial

**Estado: CONTRATO VIGENTE.** Fijado con el artista el 2026-07-29. Este documento es la
autoridad de TODO el sistema de análisis y generación de contenido editorial (obra, serie,
mockup, notas de estudio, SEO, social). Reemplaza como autoridad a
`artwork-content-analysis-contract-v2.md` (que queda como antecedente histórico).

**Cómo se aplica:**
- Los prompts y el código **se derivan** de estos artículos. Cuando un prompt y un artículo
  difieren, el artículo manda y el prompt se corrige — nunca al revés. Un prompt manuscrito
  jamás es autoridad.
- Toda mejora se hace como **enmienda**: se edita el artículo, después el código/prompt que lo
  implementa, después el test que lo custodia. Nunca como parche suelto.
- La suite `platform/tests/regression/editorial_core_contract_test.php` verifica los
  invariantes en cada preflight: **un cambio que viole este documento no puede desplegarse.**

**Por qué existe:** este contenido es el motor de mauriziovalch.com y del embudo hacia
Saatchi Art — los dos objetivos que gobiernan el proyecto (presencia de catálogo ordenado;
llevar actividad y compra a Saatchi/mercado USA). Un dato inventado aquí no es ruido interno:
es lo que ve un comprador y lo que indexa Google.

---

## LIBRO I — Identidad y autoridad (quién dice qué)

### Cap. 1 — La jerarquía de conocimiento

`Perfil del artista → Serie → Obra → Mockup → Canal`

Cada nivel hereda el contexto del superior y genera solo lo suyo. El mockup jamás
reinterpreta la obra; el canal jamás reinterpreta al mockup. Un análisis central por entidad,
muchas adaptaciones específicas — nunca una copia mecánica del mismo texto entre destinos.

### Cap. 2 — División del trabajo

El artista pone **solo lo que nadie más puede poner**: su obra, sus títulos, su identidad
(perfil y dirección de series). El sistema pone **todo lo demás**: la inteligencia SEO, la
redacción multicanal, las adaptaciones de idioma, el control de identidad, la distribución.
Ninguna función del sistema puede exigirle al artista trabajo técnico (importaciones,
configuraciones, investigación) como condición para operar.

### Cap. 3 — El perfil del artista

Fuente única en `artist_profiles` (una fila por artista). Los campos editoriales (bio,
statement, lenguaje visual, materiales, temas recurrentes, paleta, público, contextos
preferidos y prohibidos, lenguaje prohibido, keywords conceptuales, tono de voz) fluyen a
todo prompt de generación vía `ArtistProfile::forPrompt()`. El perfil es **contexto**: guía
la voz y la interpretación, pero jamás se copia literalmente a texto público.

### Cap. 4 — La serie y su dirección

El título de la serie lo escribe el artista, siempre. Cada serie tiene su **dirección**
("Fuente del artista"): núcleo conceptual, límites interpretativos, y su **ADN de títulos**
(conceptos compatibles y reglas específicas para titular obras de esa serie). Los límites
interpretativos son **prohibiciones**: nada generado puede enunciar como hecho una lectura
que el artista excluyó, ni reducir la serie a ella.

**La obra no tiene dirección propia: la gobierna la dirección de su serie.** Lo particular de
cada obra se expresa en sus notas libres y su memo privado.

### Cap. 5 — La obra

El registro canónico: título (del artista, siempre), técnica, materiales, medidas, año,
orientación. Los hechos confirmados desconocidos quedan `null` — jamás se infieren. La
técnica declarada por el artista es hecho confirmado y manda sobre cualquier lectura visual.

### Cap. 6 — Títulos

**Regla cero: el sistema nunca DECIDE un título de serie ni de obra.** No los produce el
análisis, no rellena títulos vacíos, no los modifica en reanálisis. El título es entrada,
jamás salida. Sugerir sí — decidir jamás:

- **Estructura**: `SERIE + número romano + — + TÍTULO` (ej. `STRATA V — QUINQUE`). El número
  romano viene del catálogo o del artista; la IA jamás lo inventa. La IA sugiere únicamente
  el título individual.
- **Idioma**: latín preferente; arameo solo cuando el término es sólido, verificable y con
  resonancia adecuada; otras lenguas antiguas solo excepcional y justificadamente. El título
  es **universal**: idéntico en español e inglés, nunca se traduce. Su significado puede
  mostrarse internamente pero no forma parte del título oficial.
- **Forma**: preferentemente una palabra (excepcionalmente dos); breve, pronunciable,
  memorable, visualmente limpio, compatible con un catálogo internacional. Sin oraciones,
  subtítulos explicativos, adjetivos ornamentales ni construcciones literarias largas.
- **Principio central**: primero mirar la obra, después reconocer la serie, finalmente
  encontrar una palabra. **Nunca al revés** — jamás elegir una palabra atractiva y forzar la
  interpretación de la imagen para justificarla.
- **Comportamiento**: el título acompaña la obra, no la clausura. Una palabra que active una
  lectura antes que una palabra que resuma toda la pintura. La IA no "explica" la obra
  completa: detecta una dirección y la convierte en una palabra con densidad y apertura.
- **Control de repetición**: antes de sugerir, revisar títulos usados en la misma serie y en
  otras series, palabras semánticamente demasiado cercanas, y la repetición conceptual
  excesiva (si ya existen LUX y NUHRĀ, no seguir proponiendo equivalentes de luz). Registro
  normalizado: título, forma sin diacríticos, idioma, significado, raíz semántica, serie,
  obra.

### Cap. 7 — El mockup como material de distribución

El mockup no es una obra con lectura propia: es **material de distribución**. Hereda la
identidad y la lectura **aprobada** de su obra; su única tarea de IA es describir SU escena
(arquitectura, luz, cámara, atmósfera, relación obra-espacio) y redactar el copy por canal.
Su schema de salida no contiene campos de identidad de la obra: lo que no existe como campo
no se puede inventar.

---

## LIBRO II — Doctrina editorial (cómo se escribe)

### Cap. 1 — Evidencia antes que interpretación

Todo lo generado — descripciones Y títulos — parte de lo **realmente visible**: dirección
dominante, ascenso o descenso, peso, división, estratos, incisiones, frecuencias, huellas,
contraste, concentración o dispersión, aparición, umbral, materia, luz, sombra, quietud,
fractura, expansión, compresión. Separar observación de interpretación: toda lectura
conceptual cita evidencia visible y lleva confianza (alta/media/baja). Prohibido inventar
narraciones, personajes, emociones, simbolismos, técnica, intención, biografía o cronología
no respaldados por la obra o por la identidad declarada de la serie.

### Cap. 2 — Integridad: LA lista única de prohibidos

Una sola lista, consumida por descripciones, SEO y títulos:

- **Prestigio/inversión (ES)**: obra maestra, obra fundamental, obra decisiva, calidad de
  museo, calidad de galería, pieza de inversión, altamente coleccionable, una de las obras
  más importantes del artista, punto de inflexión en su carrera, aclamada por la crítica,
  reconocimiento crítico, premiada, validación institucional, oportunidad rara/exclusiva de
  inversión, se revalorizará.
- **Prestigio/inversión (EN)**: masterpiece, pivotal work, museum-quality, gallery-quality,
  important work in the artist's career, highly collectible, investment artwork, significant
  painting, critically acclaimed, award-winning, will increase in value.
- **Místico/terapéutico** (solo si el artista los introduce expresamente): spiritual
  awakening, inner journey, soul, healing, energy, cosmic, sacred.
- **Anti-inflado**: cada observación se usa una sola vez; prohibido repetir la misma idea con
  otras palabras para alargar. Nunca inventar premios, exposiciones, demanda, recepción
  crítica, rareza o relevancia histórica.
- **Límites de palabras**: descripción de obra ≤350 · corta ≤70 · mockup ≤180 · website ≤140
  · Pinterest ≤100 · Instagram/Facebook ≤180 · alt text ≤90 · caption ≤50 · excerpt de nota
  ≤70 · seo_description de nota ≤35.
- **Límites de CARACTERES de Saatchi Art** (enmienda 2026-08-01): título ≤65 **caracteres**
  con espacios · descripción ≤1000 · pie de imagen <50. Se miden en caracteres, no en
  palabras, porque son topes duros del formulario de Saatchi: un texto que los pase no se
  puede publicar. Los campos del sitio no sirven ahí — la descripción larga (1587 caracteres
  medidos) y el pie del sitio (≈180) los exceden. Saatchi lleva **campos propios, generados
  con su presupuesto**, jamás un recorte del texto largo: truncar corta a mitad de frase y
  eso ya está prohibido en este mismo capítulo.
- **Título de Saatchi**: `TÍTULO EXACTO - cola SEO` dentro de los 65 caracteres totales. La
  cola se genera contra el presupuesto que deja cada obra (65 − largo del título − 3), que
  varía entre 42 y 56 caracteres en el catálogo actual. Si no entra una cola honesta, el
  título viaja solo. **Prohibido abreviar, truncar o mutilar el título de la obra** para
  hacerle lugar a la cola: el título es identidad (Libro I).

### Cap. 3 — Anti-genérico

- **Aperturas prohibidas**: "Esta obra explora…", "En esta pintura…", "Parte de la serie…",
  "This artwork explores…", "In this painting…", "Part of the series…"; y descripciones que
  empiecen con This / In this / The / A / An — abrir con una relación concreta de color,
  forma, borde, intervalo, superficie, dirección o tensión visible.
- **Frases y actitudes prohibidas** (rescatadas del prompt v1): "This artwork is presented
  as…", "This version positions the piece…", "collector-grade silence", "curatorial
  narrative", "commercial presentation", "publication-ready", "for galleries, curators and
  interior designers", lenguaje académico excesivo, relleno genérico de marketplace.
- **Vocabulario decorativo prohibido**: wall art, gallery wall art, home decor, statement
  decor, perfect for any room, elevate your space, decor inspiration.
- **Escala**: large/oversized/XL/XXL/monumental solo con dimensiones confirmadas que lo
  justifiquen; jamás inferida del impacto visual ni de la vista de una habitación.
- **Títulos genéricos desaconsejados** (no prohibidos si son históricos, nunca recomendables
  como propuestas nuevas): Untitled, Composition, Abstract Landscape, Inner World, Silent
  Journey, Eternal Light, Infinite Horizon, Fragmented Memory.
- **Alcance: toda descripción de todo medio** (enmienda 2026-07-31). Las aperturas prohibidas
  —incluidas las de verbo comercial: Descubre / Explora / Adquiere / Compra / Discover /
  Explore / Acquire / Buy— rigen en **cada** texto público, no solo en `seo_description`:
  `description`, `short_description`, `seo_description`, `website.description`,
  `pinterest.description`, `facebook.link_description`, `facebook.post_text`,
  `instagram.caption` y `tiktok.caption_seed`. Se validan en código y la salida que las
  incumpla se rechaza: pedirlo en el prompt no basta, porque el modelo lo ignora.
- **No repetición de aperturas** (enmienda 2026-07-31). Dentro de una misma obra, ningún texto
  público puede abrir como otro. Rige entre la obra y cada uno de sus mockups, entre mockups
  hermanos, y entre los canales de un mismo mockup. Se compara la apertura normalizada; dos
  textos que arrancan con la misma frase son el mismo texto para el lector, que en el feed
  solo ve las primeras líneas antes del truncado. Extiende a las descripciones la regla que
  el Libro III Cap. 3 ya fijaba para alt text y captions.

### Cap. 4 — Voz y tono

Sobrio, preciso, visual, material y contenido. Describir antes de interpretar: empezar por la
composición, el color, la superficie, las divisiones, las formas y las relaciones. Voz
curatorial contemporánea y humana — no académica, no decorativa, no genérica. El campo
`tone_of_voice` del perfil guía todo texto público.

### Cap. 5 — Materialidad

**El proceso mixto nunca se colapsa a su primer medio.** Si el artista declara acrílico con
acabados al óleo, se conservan acrílico Y óleo y su relación — prohibido reetiquetar como
"solo acrílico" o "pintura al óleo pura". "Materials and process" del perfil es evidencia
confirmada de práctica de taller; los datos exactos de la obra mandan sobre la práctica
general. En clasificación de catálogo, técnica/material/soporte confirmados tienen prioridad
sobre vocabulario simbólico.

### Cap. 6 — Diversidad descriptiva

Un solo motor de diversidad (unificando los dos históricos): rota puntos de entrada, ritmos,
estructuras y cierres contra el historial reciente, con elegibilidad basada en evidencia. La
diversidad viene de **otra observación y otra jerarquía**, no de sustituir sinónimos.
Prohibido recorrer la pintura banda por banda o color por color; 3–4 párrafos con funciones
distintas; no repetir la misma observación entre apertura, descripción corta y cierre.

### Cap. 7 — Regla de color

El color se trata siempre como dimensión de terminología artística, nunca simplificada: siena
no colapsa a "rojo", carmesí no es "dark red". La paleta verificada es la única fuente; la
textura solo desde evidencia visible o documentada.

---

## LIBRO III — SEO y búsqueda (cómo nos encuentran)

### Cap. 1 — Dos capas

El significado del artista y el lenguaje de búsqueda del comprador son **dos capas
distintas**. El material del artista explica la obra cuando el visitante llega; el metadato
de búsqueda lo hace llegar usando el lenguaje ordinario del mercado del arte. Prohibido
fabricar keywords pegando vocabulario poético, simbólico, geológico o curatorial a una
categoría de arte. Preferir siempre frases que una persona real podría tipear.

### Cap. 2 — Formatos duros

- `seo_title` = `TÍTULO EXACTO | frase de categoría en lenguaje llano | NOMBRE DEL ARTISTA`
  — exactamente dos barras verticales espaciadas; título y artista una sola vez; sin dos
  puntos, guiones, "de" ni "by".
- `tags` (clasificación de catálogo): 10–14 filtros concisos — tipo de objeto, tema, estilos
  reconocidos, **toda** técnica/material/soporte confirmado, superficie, colores dominantes,
  orientación, formato, escala justificada.
- `search_terms`: 12–16 frases naturales distintas que un comprador tipearía; **≥6 long
  tails genuinos** (≥4 palabras, combinando 3+ atributos útiles). Un solo set — prohibidas
  las listas paralelas (primary/secondary/collector/architect).
- `seo_description`: resumen humano, específico de página; **prohibido abrir con Descubre /
  Explora / Discover / Explore**.
- **Frases gramaticales y decibles**: con artículos y preposiciones. Prohibidas las pilas de
  sustantivos ("pintura acrílico óleo lienzo", "cuadro tonos tierra azul").
- **Transaccional solo en metadata**: comprar/adquirir/venta/coleccionista (y equivalentes
  EN) viven exclusivamente en los campos SEO — jamás en la prosa pública. Integración
  natural de 3–4 frases descriptivas en la prosa (≥1 en la corta, ≥3 en total), con flexión
  gramatical permitida y sin sintaxis robótica.
- Nunca inventar ni sugerir volumen de búsqueda, competencia, ranking o demanda.

### Cap. 3 — Excepción de repetición SEO

La penalización de diversidad NO aplica a los campos SEO: ahí la repetición estable del
vocabulario comercial correcto (categoría, estilo, técnica, material, soporte, color,
formato, artista, serie) es deseable. No sustituir términos establecidos por sinónimos raros
para "parecer distinto".

### Cap. 4 — Investigación como refuerzo opcional

Cuando existe investigación de keywords importada y seleccionada para la serie/idioma
(`SeriesKeywordResearchService::promptContext()`), se inyecta automáticamente en el generador
como evidencia validada que prioriza el vocabulario. Su ausencia jamás bloquea la generación
ni le exige nada al artista: sin investigación, rigen las reglas de mercado de este Libro.

---

## LIBRO IV — Idiomas (master → internacional)

### Cap. 1 — Recreación, no traducción

El master se piensa y redacta directamente en el idioma de trabajo del artista (nunca
redactar en otro y traducir). El idioma de publicación se **reconstruye editorialmente**:
sin calcos, sin falsos amigos, sin sintaxis mecánica; se adapta por función (keyword, caption,
alt, prosa), no palabra por palabra; se preservan punto de entrada visual, orden narrativo,
tono y cierre elegidos por la fuente.

### Cap. 2 — Lo intocable

El título universal (obra y serie) es idéntico en todos los idiomas. Nombres propios,
términos históricos y grafías originales se preservan. Si el título aparece en un
`seo_title`, queda sin cambios y solo se adapta el lenguaje descriptivo que lo rodea.

### Cap. 3 — Paridad SEO 1:1

En adaptación, cada tag y cada frase de búsqueda se adapta uno a uno y en el mismo orden —
mismos conteos que el idioma fuente, con el vocabulario de búsqueda real del idioma destino
(nunca traducción literal de las frases).

---

## LIBRO V — Canales y distribución

### Cap. 1 — Roles por canal

Website = coleccionista, detallado · Pinterest = tráfico, corto · Instagram = visual y
comunidad · Facebook = conversacional · TikTok = preparación futura. Cada canal recibe su
adaptación desde la misma lectura validada; **jamás se duplica un caption entre canales**.

### Cap. 2 — Jerarquía comercial

**Saatchi Art es el CTA primario por obra** cuando existe listing (decisión del artista,
2026-07-28: "Saatchi ofrece más garantía"); la compra directa Stripe es secundaria. Esta
decisión supersede cualquier documento anterior que dijera lo contrario.

### Cap. 3 — Regla por imagen

Cada imagen publicada lleva alt text y caption **únicos**, relación explícita con
artista/serie/obra, y adaptación por canal. Un detalle, una vista frontal y un mockup de la
misma obra jamás comparten alt text ni caption.

### Cap. 4 — El mockup nunca es la obra en venta

El SEO y el copy del mockup pueden describir el emplazamiento arquitectónico sostenido, pero
el objeto a descubrir/adquirir es siempre la obra. Nunca llamar "enmarcada" a la obra sin
marco visible; nunca inferir escala desde la habitación; nunca inventar muebles, materiales
o luz no visibles.

### Cap. 5 — Escenas y contextos de mockup (rescatado del prompt v1)

Solo contextos premium: interiores residenciales de alto nivel realistas, galerías
profesionales, espacios de museo limpios, atmósferas sofisticadas y bien iluminadas.
Prohibidos: subterráneos, sótanos industriales, bóvedas, depósitos, garajes, cavernas y
ambientes no-premium, lúgubres o fríos. Cada propuesta de contexto es única (no variación
leve de la misma sala) y sus nombres son evocativos y curatoriales ("Silent Mineral
Interior"), nunca placeholders rígidos ("Main Sales Mockup"). Figura humana para escala:
ninguna, u opcional de pie — masculina 1,80 m / femenina 1,55 m. Principio rector: **la obra
determina el espacio, no el espacio a la obra.**

### Cap. 6 — El destino operativo no es contenido editorial (enmienda 2026-08-03)

Las sugerencias editoriales de tablero de Pinterest orientan, pero no deciden el destino.
Antes de publicar, el artista elige junto a **cada imagen** un tablero real de la cuenta
conectada: una serie puede distribuir sus Pins entre tableros distintos. En Production, un
tablero solo se considera elegible cuando un Pin Production exitoso lo confirmó; el listado
remoto por sí solo no es autoridad porque puede conservar destinos Sandbox, eliminados o de
sistema. Esa evidencia pertenece a la cuenta y rige todas sus obras. Los IDs de Sandbox nunca
constituyen evidencia de publicación en Production.

La serie muestra además un único enlace de destino, precargado con la página pública de la
obra y editable antes del primer envío. Ese enlace gobierna los diez Pins porque todos
distribuyen la misma obra. Un Pin que ya posee ID externo queda inmutable dentro del flujo
normal: cambiar un tablero, cambiar el enlace, repetir el POST o abrir la pantalla en paralelo
no autoriza otro Pin. La serie completa pierde su acción de publicación; solo los fallidos
conservan selector y reintento. Una reconciliación excepcional por borrado externo es una
operación separada del flujo normal y exige identificar previamente los IDs afectados.
Un tablero que Pinterest rechaza explícitamente por pertenecer a Sandbox deja de ser un
destino elegible en Production. El fallo se muestra junto a cada imagen afectada y el sistema
conserva ese descarte para no volver a proponer el mismo tablero en ninguna obra de la cuenta.

---

## LIBRO VI — Flujo y estados

### Cap. 1 — El flujo editorial

1. Cada obra tiene contexto que NO genera la IA: autor, serie, dirección de la serie.
2. Sobre eso, la IA construye el contenido editorial de la obra y su metacontenido.
3. Sobre **lo APROBADO** de cada obra se genera el contenido editorial de sus mockups.

Gates operativos: sin título del artista + serie asignada + dirección de serie escrita, no
se genera lectura de obra (bloqueo con mensaje claro). **Publicar = aprobar** — publicar la
lectura de la obra es la aprobación que habilita sus mockups; sin obra publicada, el mockup
no genera contenido.

**La ficha termina en «obra resuelta»; publicar vive en la sección Publicación** (enmienda
2026-07-31, supersede la enmienda 2026-07-29 "accionable en la ficha"): la ficha de obra es
solo obra — identidad, material visual, notas y Espacio editorial — y su único estado terminal
es *obra resuelta* (contenido editorial completo). La ficha MUESTRA ese estado siempre
(visible, nunca accionable como publicación) y señala que publicar se hace en Publicación.
Toda decisión de publicación y toda configuración comercial (precio, disponibilidad, Saatchi,
portada, visibilidad) viven únicamente en la sección Publicación — **una sola puerta**:
prohibidos botones o atajos de publicación dispersos en otras pantallas. El contrato de
estructura y diseño de esa sección es `PUBLICACION_DISENO.md`.

**UNA sola decisión de publicación por obra** (enmienda 2026-07-29, opción A del artista;
reubicada 2026-07-31): «Publicar Obra» sigue siendo un único acto — publica la página del
sitio, publica el texto aprobado y dispara la cascada de mockups; «Despublicar» retira página
y texto juntos. No existen dos "publicar" con significados distintos. Compuerta: sin
contenido editorial generado, la obra no se publica (mensaje claro que señala «Generar
contenido» en la ficha). Las dos decisiones del artista quedan repartidas así: *Generar
contenido* en la ficha (el sistema hace todo lo inteligente) y *Publicar/Despublicar* en la
sección Publicación (su única aprobación). La configuración comercial es configuración que
se guarda, no una publicación.

### Cap. 2 — La lectura viva

La interpretación es legítimamente mutable — crece con el artista, con miradas retrospectivas
— pero muta **construyendo sobre la lectura vigente**, nunca borrando para empezar de cero.
Toda regeneración recibe la lectura vigente + el memo privado y refina preservando lo que
sigue siendo cierto. Único historial: borrador / snapshot publicado. El JSON crudo de la IA
es artefacto transitorio: se usa una vez para poblar la lectura y **nadie lo vuelve a leer
para generar** — el contexto siempre se lee en vivo de las tablas canónicas.

### Cap. 3 — Estados

Contenido: borrador → publicado (→ desactualizado cuando su fuente cambió). Títulos:
`suggested → shortlisted → approved / locked → rejected` — solo approved/locked entran al
catálogo, solo por acción del artista; `locked` es inmutable ante cualquier análisis futuro;
los rechazados se conservan para no re-sugerirlos.

### Cap. 4 — Edición soberana y cascada

La edición manual del artista es soberana: no degrada estados y nada la pisa. Al re-publicar
la lectura de una obra, el contenido de sus mockups se regenera automáticamente desde la
versión nueva — **salteando los editados a mano**, que quedan marcados para decisión del
artista.

---

## Anexo — Trazabilidad de implementación

| Artículo | Implementación principal |
|---|---|
| I.6 regla cero / II.* / III.* | `ArtworkAnalysisV2` (prompt+schema+validate) · `BilingualEditorialAdapterService` |
| I.7 + VI.1 gate mockups | `ArtworkSheetService::generateMockupSheet()` |
| VI.1 gate contexto íntegro | callers de `ArtworkAnalysisV2Service::generateDraft()` |
| VI.2 refinar + transitorio | `generateDraft()` (lectura vigente como entrada) · `entityContext()` en vivo |
| VI.4 cascada | `BilingualEditorialService::setPublished()` + `BilingualEditorialJobService` |
| Identidad (red de seguridad) | `EditorialIdentityGuard` (generación, guardado y publicación) |
| III.4 investigación opcional | `SeriesKeywordResearchService::promptContext()` inyectado en generadores |
| Ley ejecutable | `tests/regression/editorial_core_contract_test.php` |

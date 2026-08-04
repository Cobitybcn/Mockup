# Generación del listing de Saatchi — especificación

Estado al 2026-08-04: **en curso, sin desplegar**. La rama es
`codex/saatchi-generacion-en-curso`. Lo que hay construido funciona y genera, pero
la especificación de abajo está aplicada solo en parte.

## Lo verificado contra Saatchi, no contra documentos

Del formulario de carga real, con captura del artista:

- **Keywords**: 5 a 12, cada una entre **2 y 20 caracteres**, en inglés.
- **Título**: máximo **65 caracteres**. Su guía oficial pide no meter keywords,
  técnica, materiales ni medidas en el título.
- **Pie de imagen**: el campo existe por imagen adicional (`Enter Caption`) y
  **no declara ningún límite**.

De la guía pública: las keywords deben describir técnica, tema, colores
predominantes, atmósfera, materiales, **artistas que inspiraron la obra** y
elementos distintivos; no repetir palabras del título ni lo ya cargado en los
campos estructurados.

## Decisiones editoriales propias — no son reglas de Saatchi

- Pies: 4 a 7 palabras, **máximo 50 caracteres**. Ambos límites duros.
- Descripción: **850 a 1000 caracteres**. Política de EDITORIAL_CORE.
- Cuotas de long tail: al menos 8 de las 12 keywords con dos o más palabras, como
  máximo 2 de una sola palabra, al menos 6 entre 14 y 20 caracteres.

## El reparto de responsabilidades

El modelo mira, interpreta con prudencia y redacta. **El código cuenta, compara,
valida y decide el estado final.** El modelo no devuelve conteos, puntajes,
candidatas rechazadas ni su propio estado de validación: un número que se inventa
a sí mismo no prueba nada.

Salida mínima del modelo:

```json
{
  "analysis": {"visible_basis": [], "confirmed_facts_used": [], "inferences_used": [], "uncertainties": []},
  "title": {"subtitle": "", "full_title": ""},
  "keywords": [],
  "description_en": "",
  "image_captions": [{"file": "", "caption": ""}]
}
```

La aplicación agrega después el objeto `validation` con su estado —`ok`,
`requires_input` o `requires_review`— y sus métricas calculadas.

Si la validación determinista falla: **una** llamada de reparación con los errores
exactos y el JSON previo, pidiendo corregir solo los campos inválidos. Si la
segunda pasada sigue fallando, `requires_review` y nunca se publica solo.

## El contexto de listing

Los campos estructurados de Saatchi viven aparte del núcleo conceptual de la obra:

```
saatchi_listing_context = {category, subject, mediums, materials, styles, authorised_artist_affinities}
```

Precedencia: **obra → serie → artista**. La migración
`20260804_000003_saatchi_listing_context.php` agrega las columnas. Sin estos datos
no se generan keywords finales: se devuelve `requires_input` con los campos que
faltan, nunca un paquete válido con placeholders vacíos.

La técnica de la base solo se convierte en Medium por un mapa determinista en
código (`"Acrylic and oil"` → `["Acrylic", "Oil"]`). El modelo no inventa ese mapeo
ni infiere estos campos desde la imagen.

## Las imágenes tienen que llegar al modelo

La principal y **cada imagen adicional** viajan como contenido multimodal real,
identificadas por archivo y nombre de cámara. No alcanza con mandar el nombre del
archivo ni la clasificación interna: sin ver la imagen, el modelo reescribe un
texto anterior, y de ahí salían contradicciones como "bloques suspendidos
anclando". Si una imagen no se puede inspeccionar, su pie queda vacío y se
registra en `uncertainties`.

## Lo aplicado (2026-08-04, leído del código y verificado por tests, sin desplegar)

- El prompt nuevo con el contrato mínimo de salida: el modelo ya no devuelve
  conteos, puntajes, candidatas rechazadas ni estado de validación
  (`app/Services/saatchi_listing_rules.txt`).
- La resolución del contexto con precedencia obra → serie → artista
  (`SaatchiListingService::listingContext`), con `requires_input` cuando falta
  alguno de los cinco campos estructurados y el mapa determinista técnica →
  Medium (`mediumsFromTechnique`).
- Todas las imágenes viajan al modelo como contenido multimodal; la que no se
  puede inspeccionar queda listada como no disponible, su pie se fuerza vacío y
  se registra el aviso.
- La validación determinista en código (`validateListing`: topes de Saatchi,
  rango 850–1000 de la descripción, cuotas de long tail, pies de 4–7 palabras y
  <50, deduplicación normalizada contra título y campos estructurados) y la
  llamada única de reparación con los errores exactos y el JSON previo.
- El objeto `validation` lo construye la aplicación (`ok`, `requires_input`,
  `requires_review` + métricas). `save()` rechaza todo paquete que no esté en
  `ok`: nunca se publica solo.
- La ley ejecutable: `tests/regression/saatchi_listing_generation_test.php`.

## El giro del 2026-08-04: derivar en vez de reinterpretar

El listing dejó de generarse mirando la obra otra vez. Ahora **deriva de la
lectura editorial ya aprobada**: misma voz entre el sitio y el listing, y ningún
hecho visual que la página publicada no diga. La fuente se elige **por estado
antes que por idioma** —una copia publicada gana sobre cualquier borrador— y el
destino es siempre el idioma de publicación, porque Saatchi se carga en inglés.

Los pies van en una segunda pasada, esa sí con las imágenes adjuntas: un pie
describe una imagen concreta y no se deriva de un texto.

Y el vocabulario de descubrimiento del sitio (`discovery_keywords`) se deriva por
idioma desde la lectura de **ese** idioma. No se traduce: una keyword traducida
deja de ser una búsqueda.

### Dónde vive cada cosa

- **Escribir** es de la ficha de la obra. Los textos de canal son un ítem más de
  «Preparar el paquete editorial», en la etapa 40, y solo se ofrecen cuando la
  lectura está aprobada — derivar de un borrador fue el error corregido ese día.
- **Decidir qué sale** es de Publicación: muestra el paquete, permite copiarlo y
  descargarlo, y tiene «Aprobar estos campos», que aprueba solo los derivados sin
  tocar la lectura ni regenerar ningún mockup.
- La escritura pasa por `BilingualEditorialService::mergeDerivedFields()` y toca
  **solo el borrador**. Publicar es aprobar, y eso lo hace el artista.

## Lo que falta aplicar

- **Rellenar las 20 obras restantes.** Solo DECLIVIS tiene listing y vocabulario.
- **Código huérfano:** las columnas `saatchi_defaults` y `saatchi_overrides` —el
  contexto estructurado con categoría, subject, materiales y estilos— existen con
  su migración y no las lee nadie desde que el servicio pasó a derivar. Hay que
  conectarlas o retirarlas.
- Los pies cubren las **5 primeras** imágenes de la composición, no todas.
- Nada de esto está desplegado: ni el código ni las cinco columnas nuevas.
- **El contrato bilingüe sigue abierto** en un punto nuevo: el sitio sirve
  siempre el borrador en inglés, sin pedir aprobación, sobre el supuesto de que
  el inglés es una adaptación de un español ya aprobado. El listing de Saatchi no
  lo es, así que se cuela por una puerta que da por cierto algo que en su caso no
  lo es. El paquete en sí no se publica en ningún lado —se copia a mano— pero las
  keywords en inglés sí llegan al sitio sin revisión.

## Lo que quedó abierto

**El contrato bilingüe.** El idioma de trabajo del artista es español y el sistema
manda español como fuente e inglés como adaptación. La especificación de hoy
contempla solo `description_en`. Falta decidir cómo se revisa en español lo que se
publica en inglés — para las keywords, mostrando su sentido en términos de obra y
no su traducción literal, porque `Layered Red Surface` traducido no ayuda a
verificar si la obra tiene capas visibles.

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

## Lo que falta aplicar

- El prompt nuevo (sección 6 de la especificación del artista).
- La resolución del contexto con precedencia obra → serie → artista.
- Mandar **todas** las imágenes al modelo; hoy solo viaja la principal.
- La validación determinista en código y la llamada única de reparación.
- El objeto `validation` construido por la aplicación.

## Lo que quedó abierto

**El contrato bilingüe.** El idioma de trabajo del artista es español y el sistema
manda español como fuente e inglés como adaptación. La especificación de hoy
contempla solo `description_en`. Falta decidir cómo se revisa en español lo que se
publica en inglés — para las keywords, mostrando su sentido en términos de obra y
no su traducción literal, porque `Layered Red Surface` traducido no ayuda a
verificar si la obra tiene capas visibles.

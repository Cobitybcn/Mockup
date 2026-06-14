Estamos trabajando en el proyecto **Smart Mockups Project**, ubicado en:

```text
C:\laragon\www\mockups
```

Antes de modificar código, necesito que leas el archivo:

```text
INSTRUCCIONES_CODEX.md
```

Ese archivo contiene una guía técnica previa del sistema: arquitectura PHP, SQLite, servicios en `app/Services`, puente Python con Gemini/Vertex, generación desacoplada en Windows, reglas de escala, perspectiva, presencia humana y generación de mockups.

Pero necesito que lo revises críticamente porque puede no estar completamente actualizado con la nueva dirección del proyecto.

## Nueva dirección conceptual del proyecto

El sistema no debe limitarse a colocar una obra en espacios físicos repetitivos o genéricos.

La idea central es:

**La obra determina el espacio, no el espacio a la obra.**

Cada obra raíz tiene:

* un lenguaje visual
* una energía emocional
* una paleta cromática
* una composición
* una escala física
* una posible lectura simbólica, decorativa, conceptual o comercial
* un público objetivo potencial

El sistema debe analizar primero la obra y, a partir de ese análisis, generar propuestas contextuales únicas para mockups.

No queremos que el sistema aplique siempre los mismos moldes.

No queremos que el usuario sienta que existen 5, 7 o 10 plantillas fijas.

Queremos que el usuario sienta que la obra fue leída, interpretada y traducida en contextos visuales posibles.

## Decisión técnica para la Beta

Para esta versión Beta usaremos **Gemini solamente**.

No implementar soporte OpenAI por ahora.

Motivos:

* control de costos
* simplificación técnica
* ya existe integración con Gemini/Vertex
* queremos validar primero el flujo contextual

El sistema actual debe mantenerse como fallback, no eliminarse.

## Objetivo del nuevo motor

Necesitamos crear o preparar un módulo tipo:

```text
MockupContextEngine
```

o nombre similar.

Este motor debe:

1. Recibir la obra raíz y sus metadatos.
2. Analizar lenguaje visual, emoción, color, composición, escala y potencial comercial.
3. Generar un JSON de análisis contextual.
4. Proponer entre 5 y 10 contextos de mockup.
5. Usar como promedio ideal 7 propuestas.
6. Evitar categorías rígidas visibles.
7. Generar nombres contextuales dinámicos para cada propuesta.
8. Crear prompts finales listos para enviar a Gemini Image / Vertex.
9. Mantener reglas estrictas de preservación de la obra.

## Cantidad de propuestas

Regla:

* mínimo: 5 propuestas contextuales
* promedio ideal: 7 propuestas
* máximo: 10 propuestas

Pero esto no significa 7 plantillas.

Significa:

**7 lecturas posibles de una misma obra.**

Cada propuesta debe diferenciarse por alguno de estos aspectos:

* tipo de espacio
* atmósfera
* materiales
* iluminación
* ángulo de cámara
* presencia humana o ausencia humana
* público objetivo
* relación cromática con la obra
* función comercial o curatorial

## Evitar etiquetas rígidas

No queremos que las propuestas se llamen siempre:

```text
Main Sales Mockup
Human Scale Mockup
Architectural Mockup
Emotional Mockup
Commercial Alternative
```

Esas categorías pueden existir solo como lógica interna opcional, pero no deben aparecer como moldes visibles.

En cambio, la IA debe generar nombres naturales según la obra.

Ejemplos:

```text
Silent Mineral Interior
Warm Mediterranean Threshold
Nocturnal Collector Room
Architectural Light Study
Soft Domestic Scale
Parisian Afternoon Presence
Gallery Distance and Silence
Brutalist Stone Context
Linen and Morning Light Interior
```

## Estructura JSON deseada

La salida debería parecerse a esto:

```json
{
  "provider": "gemini",
  "engine_version": "beta_contextual_mockups_v1",
  "recommended_number_of_contexts": 7,
  "artwork_analysis": {
    "visual_language": [],
    "emotional_energy": [],
    "dominant_colors": [],
    "secondary_colors": [],
    "color_temperature": "",
    "contrast_level": "",
    "composition_type": "",
    "spatial_presence": "",
    "artwork_function": "",
    "suggested_audience": [],
    "commercial_positioning": ""
  },
  "contextual_proposals": [
    {
      "context_name": "Silent Mineral Interior",
      "context_role": "primary presentation",
      "space_type": "minimal architectural interior",
      "atmosphere": "silent, contemplative, mineral, spacious",
      "materials": ["stone", "lime plaster", "neutral textile"],
      "lighting": "soft lateral afternoon light",
      "camera_angle": "three-quarter view",
      "human_presence": "none",
      "curatorial_reason": "The artwork has a silent architectural presence and needs a clean mineral space that reinforces its contemplative tension.",
      "commercial_reason": "This context positions the artwork for collectors, architects and interior designers looking for a strong but sober contemporary piece.",
      "prompt": ""
    }
  ]
}
```

## Reglas de preservación de la obra

Cada prompt generado debe incluir reglas estrictas:

* Preserve the original artwork exactly.
* Do not change the painting.
* Do not modify colors, composition, symbols or texture.
* Do not mirror the artwork.
* Keep the correct proportions.
* Respect the real dimensions.
* Respect the canvas depth.
* Keep the artwork fully visible.
* Do not crop important parts of the painting.
* Do not add text, logos or watermarks.
* The mockup must look realistic, premium and professionally photographed.

## Tarea inicial

Primero quiero que hagas solo esto:

1. Lee `INSTRUCCIONES_CODEX.md`.
2. Lee la estructura actual del proyecto.
3. Revisa especialmente:

   * `app/Services/GeminiArtworkAnalyzer.php`
   * `app/Services/GeminiImageClient.php`
   * `app/Services/MockPromptBuilder.php`
   * `app/Services/MockContextSelector.php`
   * `app/Services/vertex_bridge.py`
   * `implementation_plan.md`
   * `task.md`
4. No modifiques código todavía.
5. Resume qué partes del archivo `INSTRUCCIONES_CODEX.md` siguen vigentes.
6. Indica qué partes deberían actualizarse para la nueva Beta contextual.
7. Propón qué archivos habría que modificar o crear para implementar `MockupContextEngine`.
8. No toques la base de datos todavía sin autorización.

## Límite de trabajo

Trabaja únicamente dentro de:

```text
C:\laragon\www\mockups
```

No modifiques archivos fuera de este proyecto.

## Principio final

Este sistema debe comportarse como un asistente curatorial y comercial para artistas.

No debe simplemente colocar una pintura en una pared.

Debe leer la obra, entender qué tipo de espacio necesita, y generar contextos de mockup que ayuden a comunicar, vender y posicionar mejor cada obra.

# Continuidad y referencias en Video Studio

Fecha de verificación: 2026-07-16.

Esta implementación usa Gemini Omni Flash como proveedor principal y conserva
Veo 3.1 como proveedor alternativo. Las decisiones siguientes se limitan a
capacidades confirmadas en la documentación oficial.

## Límites aplicados en la interfaz

- Hasta 10 imágenes por solicitud. La imagen de continuidad automática también
  cuenta dentro de este límite.
- Un video base opcional de hasta 10 segundos para editar con Omni.
- Duración Omni seleccionable entre 3 y 10 segundos; valor inicial: 4 segundos.
- Una imagen inicial y una imagen final objetivo opcionales.
- Texto de propósito opcional para cada imagen. No es un campo nativo de la API:
  Video Studio lo incorpora al prompt junto a su etiqueta `<IMAGE_REF_N>`.
- El video base se administra en una sección independiente y no consume uno de
  los 10 espacios de imagen.

Aunque el esquema del modelo contempla hasta tres entradas de video, la guía
oficial no confirma un flujo fiable de razonamiento con varios videos y advierte
limitaciones para referencias de video genéricas. Por eso Video Studio permite
un solo video base y lo usa únicamente mediante el flujo confirmado de edición.

## Prioridad permanente de referencias

El request y el prompt siempre ordenan las referencias así:

1. Obra de arte.
2. Personaje.
3. Vestuario.
4. Imagen final objetivo y demás referencias visuales.

Una referencia de menor prioridad no debe modificar una de mayor prioridad. Las
instrucciones predeterminadas exigen conservar identidad, proporciones, colores,
textura y detalles de la obra; identidad y rasgos del personaje; y prendas,
colores y accesorios del vestuario. El usuario puede precisar el propósito de
cualquier imagen y ese texto sí llega a Omni dentro del prompt.

## Continuidad entre escenas

1. Si no se adjunta una imagen inicial y existe una escena anterior generada, se
   extrae su último fotograma y se envía automáticamente como `<FIRST_FRAME>`.
2. Las demás imágenes se envían como `<IMAGE_REF_N>`, en el orden de prioridad
   anterior y con su propósito escrito en el prompt.
3. La imagen final se envía como objetivo visual. Omni no confirma interpolación
   entre primer y último fotograma, por lo que no se presenta como garantía.
4. El prompt añade continuidad de obra, personaje, vestuario, ambiente,
   iluminación, ritmo, movimiento, espacio y tono, salvo que el usuario solicite
   explícitamente un cambio.
5. Una semilla no se usa como garantía de continuidad.

## Video base y ajuste de un resultado

- **Editar video base:** el archivo MP4, MOV o WebM se envía a Omni junto con el
  prompt y hasta 10 referencias de imagen. Video Studio valida que dure como
  máximo 10 segundos.
- **Ajustar resultado:** se crea una nueva interacción usando el
  `previous_interaction_id` del resultado activo. Omni devuelve un video completo
  nuevo; no extiende temporalmente el anterior.
- **Extender video:** Omni no lo confirma como capacidad. Video Studio no simula
  extensión ni diseña el flujo alrededor de ella.

## Comportamiento de interfaz

- Cada secuencia conserva el lenguaje visual y los bloques drag and drop previos.
- `Imagen inicial` y `Imagen final objetivo` siguen siendo opcionales.
- El panel `Prompt, referencias y duración` está plegado por defecto.
- Dentro del panel aparecen los tres espacios prioritarios, las referencias
  adicionales, el video base separado, el prompt amplio y la duración.
- Se puede arrastrar desde el catálogo o añadir archivos desde el ordenador.
- Las escenas se pueden generar, ajustar, regenerar, duplicar, eliminar y ordenar.

## Veo 3.1

Veo conserva su integración existente: imagen a video y, cuando corresponda,
primer/último fotograma. Sus límites y modos se validan por separado. El flujo de
video base y el ajuste mediante `previous_interaction_id` requieren Omni.

## Fuentes oficiales

- Gemini Omni Flash: https://ai.google.dev/gemini-api/docs/omni
- Modelo y límites de Gemini Omni Flash:
  https://ai.google.dev/gemini-api/docs/models/gemini-omni-flash
- Interactions API de Vertex AI:
  https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/models/interactions-api
- Veo 3.1:
  https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/veo/3-1-generate
- Extensión con Veo:
  https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/video/extend-a-veo-video
- Primer y último fotograma con Veo:
  https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/video/generate-videos-from-first-and-last-frames

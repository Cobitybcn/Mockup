# Style Guide

Reglas de estilo y diseño, derivadas de las capturas aprobadas en
`design-system/references/` y del código real donde aplica. Reemplaza como punto único de
consulta a `UI_RULES.md`, `UI_PREFERENCES.md` y `UI_INTERACTION_PATTERNS.md`. No reemplaza
`MASTER_PATTERNS/`, `references/` ni el CSS citado — esos siguen siendo la fuente detallada
detrás de cada punto. Sin autoridad sobre el código: ante duda, mirar la captura citada,
el archivo de código citado, o producción.

## Decision Block

Es una acción muy importante, o varias acciones agrupadas en un solo bloque/botón. No todo
botón es un Decision Block — la mayoría son acciones comunes. Una pantalla puede tener uno
(ej. Explore Scenes: una sola acción de compromiso) o varios (ej. Series: fila de
categorías).

Forma: cuadrado o casi-cuadrado, un pastel plano por sección, etiqueta corta centrada.
Nunca se confunde con una Thumbnail Card (rectangular) ni se vuelve un botón SaaS
genérico. El bloque "+" de crear nuevo tiene el mismo tamaño y peso que los demás.

## Header

Banda plana, color pastel por sección, **con transparencia** — suave, nunca cargada.
Título editorial (serif) + una línea de instrucción corta. Acciones secundarias más
livianas que el título.

## Contenido (Thumbnail Cards)

Rectangular, la imagen ocupa casi toda la tarjeta. Caption compacto: título + id/badge.
Nunca metadata expandida. Grilla con gutters generosos, nunca comprimida para caber más
por fila.

## Glass Actions — punto que hoy más falla, converger acá

Botones circulares perfectamente alineados sobre la foto a la que afectan (favorito,
eliminar, refresh, enlace, etc.).

- Favorito: arriba-izquierda. Acciones secundarias: arriba-derecha.
- Estado normal: esmerilado/translúcido, peso mínimo.
- Estado activo (ej. favorito marcado): pastel **sólido** (dorado), no translúcido — así
  está implementado en `platform/media-controls.css` (`.is-favorite` / `.is-active`).
- La implementación real vive en esa clase (`.media-icon-button`); reusarla siempre, nunca
  inventar blur, sombra o tamaño nuevo por pantalla.
- Hoy está fragmentado en 7 pantallas (Scene Mockups, ArtWorks, Mockup Album, Videos,
  Social Media Board, Video Lab, Scene Studio) — Scene Studio es el peor caso. Toda
  pantalla nueva o tocada debe reusar la clase existente tal cual, no una variante propia.

## Color

Cada sección, familia de flujo o tablero mantiene su propio pastel, siempre el mismo donde
aparezca — el color funciona como **índice** de pertenencia, no como decoración. Nunca
saturado, nunca luminoso o sintético; nunca la única señal de significado (siempre
acompañado de label o ícono, nunca "solo color"). El color nunca compite con la obra.

## Formularios y paneles

Áreas de escritura amplias y cómodas, sin `textarea` con borde nativo visible en
superficies de escritura del artista. Paneles secundarios plegados por defecto cuando su
contenido no es inmediatamente necesario.

## CSS obligatorio

Toda sección nueva carga `style.css` y después `ui-catalog.css`. Reusar clases `catalog-*`
existentes (`.catalog-heading`, `.catalog-panel`, `.catalog-thumbnail-grid`, etc.); nada de
bloques `<style>` grandes embebidos ni estilos inline para algo reutilizable.

## Antes de tocar una pantalla

Buscar la captura más parecida en `design-system/references/` y copiar su estructura.
Nunca rediseñar desde cero.

## Nunca

Look Material/Bootstrap/dashboard; metadata compitiendo con la imagen; estilo de Glass
Action inventado por pantalla; gradiente, glow o sombra fuerte como decoración; rediseñar
una pantalla cuando la tarea era mantenerla.

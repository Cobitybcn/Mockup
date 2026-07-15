# Artwork Mockups UI Rules

Estas reglas son obligatorias para nuevas secciones de la app.

## Referencias visuales

- `Website Catalog` es la referencia para secciones de catalogo administrable: header sobrio, panel con borde, tarjetas compactas, poco texto visible.
- `Social Media Campaigns` debe seguir el estilo de `Website Catalog`.
- `Series` usa el mismo lenguaje visual de catalogo administrable: compacta, con asignacion clara y sin gigantismo.
- `ui-catalog.css` es la fuente visual compartida para Website Catalog, Social Media Campaigns, boards y calendario.
- `Mockup Album` es referencia para archivo/galeria masiva de mockups, no para paneles de publicacion.
- `ArtWorks` es referencia para catalogos de obras.

## CSS obligatorio

- Toda nueva seccion de publicacion debe cargar `style.css` y despues `ui-catalog.css`.
- Evitar bloques `<style>` grandes dentro de las paginas.
- Si una pantalla necesita un ajuste nuevo reutilizable, agregarlo a `ui-catalog.css`.
- Si el ajuste es unico y pequeno, usar una clase especifica, no estilos inline.

## Clases base

- Contenedor: `.website-catalog`, `.social-catalog`, `.publishing-catalog` o `.series-catalog`.
- Encabezado: `.catalog-heading`.
- Caja visual: `.catalog-panel`.
- Tabs/filtros: `.catalog-tabs`, `.catalog-filters`, `.social-tabs`, `.social-filters`.
- Busqueda: `.catalog-search`.
- Grilla de thumbnails: `.catalog-thumbnail-grid` o `.social-grid`.
- Cards: `.website-card` o `.social-card`.
- Estados: `.status-pill` + clase de estado.
- Drag and drop futuro: `.planner-workbench`, `.planner-pool`, `.planner-board`, `.planner-calendar`, `.planner-token`, `.planner-dropzone`.

## Desktop

- Usar el estilo de la seccion equivalente existente antes de inventar una nueva composicion.
- Header compacto con titulo y subtitulo corto.
- Search estilo `Mockup Album`: input ancho `minmax(0, 1fr)` + boton a la derecha, misma linea, misma altura, prolijo y a nivel.
- Nunca dejar un search como pieza suelta, boton desalineado, barra monumental o bloque horizontal tosco.
- Tarjetas compactas dentro de un panel, sin marcos pesados ni imagenes gigantes.
- Mostrar solo lo necesario en la tarjeta: imagen, titulo, estado principal.
- Cuando un titulo lleva serie: `Titulo <serie suave>`. Si es `NO SERIE`, no mostrar nada.
- Los detalles van al abrir la ficha.

## Mobile

- Menos es mas.
- Encabezados reducidos o sin protagonismo.
- La pantalla debe ir rapido a lo visual: thumbnails/mockups primero.
- Filtros compactos.
- Evitar textos largos y cards enormes.

## Estados

- Los estados deben ser escaneables y consistentes.
- No mezclar estados de website con estados de redes.
- Website Catalog refleja publicacion en el sitio.
- Social Media no se organiza desde mockups sueltos.
- Social Media se organiza desde campañas editoriales: objetivo, fuente, obra/serie/catalogo, visuales, canales y calendario.
- Los mockups son material visual de una campaña, no el punto de partida principal.

## Antes de tocar una pantalla

Preguntar primero: que pantalla existente cumple la misma funcion?

Copiar esa estructura visual y adaptarla. No redisenar desde cero.

Para secciones de redes, website blog, boards o calendario, partir siempre de `ui-catalog.css`.

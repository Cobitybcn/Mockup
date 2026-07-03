# Reporte de Inconsistencias de Patrones de UI

Este documento reúne las inconsistencias más notorias en el diseño, comportamiento, terminología y patrones visuales identificados en las distintas pantallas de la aplicación.

---

## 1. Localización e Idiomas Mezclados (Falta de Consistencia en Idioma)
Es la inconsistencia más grave a nivel de experiencia de usuario. La plataforma carece de un idioma base claro:
*   **En Inglés**: Las pantallas del núcleo de onboarding y curaduría (`artwork_new.php`, `waiting.php`, `root_select.php`, `core_review.php`, `mockup_combinations_review.php`) tienen sus encabezados, instrucciones y etiquetas principales en inglés.
*   **En Español**: El flujo de video (`social_video_timeline.php` y `social_video_simple.php`) está escrito completamente en español (ej. "Arrastra un hito", "Volver a la obra", "Guardar timeline").
*   **Híbrido (Mezcla en la misma página)**: La ficha de la obra (`artwork.php`) mezcla ambos idiomas en los encabezados de sus paneles colapsables:
    *   `<summary>Lectura Curatorial</summary>` (Español)
    *   `<summary>Sheet Metadata</summary>` (Inglés)
    *   `<summary>View Raw AI Analysis (JSON)</summary>` (Inglés)

---

## 2. Hacks de Escalado en CSS (`zoom: 0.7`)
*   **El problema**: El archivo `social_video_simple.php` (que es el generador de video activo) aplica una propiedad `zoom: 0.7` en la etiqueta `body`.
*   **Inconsistencia**: Ninguna otra página del sistema utiliza este tipo de escalado manual. Al navegar desde la ficha de obra (`artwork.php`) hacia el generador de video, toda la interfaz (fuentes, botones, barra de navegación) se achica repentinamente un 30%, dando la impresión de que el sitio se rompió o cambió de dominio. Además, el zoom mediante CSS no es un estándar responsivo y causa renderizado borroso en algunos navegadores.

---

## 3. Patrones de Botones e Interacciones
*   **Botones Microscópicos**: En `mockup_combinations_review.php`, los botones del encabezado de la página (ej. "Generate All", "Review Mockup Combinations") tienen un estilo con un tamaño de fuente de **9px** y una altura reducida a **28px**. Esto rompe con el estándar de botones del resto de la aplicación, los cuales tienen tamaños de fuente legibles (entre 12px y 14px) y alturas cómodas para hacer clic.
*   **Estilos de Copiado**:
    *   En `artwork.php`, la acción de copiar al portapapeles se realiza mediante botones puramente visuales (iconos SVG de carpetas superpuestas) colocados al lado de cada campo.
    *   En `waiting.php` (panel de administración), la acción se realiza mediante botones tradicionales con el texto `"Copy prompt"`.

---

## 4. Diseño de Tarjetas y Visualización de Imágenes
*   **Tarjetas de Candidatas (`root_select.php`)**: Utilizan un diseño elegante con bordes limpios, fondos blancos, sombras suaves y un efecto interactivo de zoom al pasar el cursor (`transform: translateY(-4px)`).
*   **Tarjetas de Vistas (`core_review.php`)**: Utilizan bordes grises simples, a veces discontinuos (dashed) si el elemento falta, y etiquetas de estado con tipografías sans-serif e insignias de colores muy contrastantes (`danger` rojo, `info` azul).
*   **Tarjetas de Ficha Técnica (`artwork.php`)**: Emplean un contenedor de estilo editorial clásico (`.pin-card`) con fondos de lino suave, bordes definidos y botones de acción apilados verticalmente abajo.

---

## 5. Exposición de Etiquetas Técnicas y Slugs
*   **Ficha del Core (`core_review.php`)**: Muestra etiquetas de vistas traducidas y formateadas de forma amigable (ej. "Frontal", "Three-quarter Left", "Three-quarter Right").
*   **Laboratorio de Mockups (`mockup_combinations_review.php`)**: Expone a nivel de interfaz de usuario los nombres de archivos físicos (slugs) de las tomas de cámara de la base de datos (ej. `borde-de-canvas-close-up-loft-rootartuploadeduploadedroot...`) en lugar de nombres entendibles para un artista.

---

## 6. Disposición de Formularios y Campos
*   **Inputs Estilizados**: En `artwork_new.php`, los campos numéricos de dimensiones físicas utilizan una estructura moderna con grillas flex, etiquetas pequeñas en mayúscula (`label`) y clases especializadas (`dim-input-group`).
*   **Inputs Estándar**: En `artwork.php` (dentro del formulario "Sheet Metadata"), los inputs de dimensiones e información física son simples cajas apiladas de manera vertical tradicional, sin el diseño o el espaciado estilizado del flujo de carga inicial.

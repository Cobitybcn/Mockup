# Informe: catálogo integrado Artwork Mockups → sitio del artista

Fecha: 12 de julio de 2026

## Decisión central

Artwork Mockups será la fuente de verdad de la identidad artística y editorial de cada obra. El sitio del artista será la fuente de verdad comercial. Mauriziovalch.com es la primera implementación, pero el diseño debe poder reutilizarse en el sitio de otro artista.

## Diagnóstico actual

- El website conserva obras en archivos individuales dentro de `data/artworks/`, pero aún mantiene rutas y herramientas heredadas asociadas a `content.json`.
- Inventario leído el 12 de julio de 2026: 28 archivos de obra en el website; Artwork Mockups contiene 135 registros de obra, 49 fichas de obra, 2.248 mockups y 1.260 fichas de mockup. Estas cantidades no representan todavía 135 obras publicables: la auditoría deberá distinguir raíces, variantes, fichas validadas y registros antiguos.
- La sincronización local ya valida una obra, su Editorial Core y varios mockups.
- El publicador experimental actual mezcla contenido editorial con valores comerciales por defecto (`available`, `Inquire`, `EUR`). Una resincronización podría sobrescribir decisiones comerciales del sitio.
- El catálogo real nunca terminó de organizarse, por lo que conviene construir una estructura paralela y migrar únicamente después de auditar y vincular las obras.
- La identidad estable no puede depender del título o del slug. Debe usarse un `source_artwork_id` permanente emitido por Artwork Mockups.

## Propiedad de los datos

### Artwork Mockups

- Identificador permanente de origen.
- Título, subtítulo y análisis V2.
- Año, serie, técnica, dimensiones y orientación declaradas.
- Resumen, concepto, captions, alt text, keywords y tags.
- Imagen raíz, detalles y mockups seleccionados.
- Versión editorial y hashes de medios.

### Sitio del artista

- Precio, moneda y modalidad de venta.
- Estado comercial: `available`, `reserved`, `sold`, `not_for_sale`, `archived`.
- Visibilidad en catálogo.
- Orden y destacados propios del website.
- Ubicación pública, tipo de ubicación y presencia en el mapa.
- Consultas, reservas, carrito, checkout, pedidos y pagos futuros.

## Regla de seguridad de datos

Una sincronización de Artwork Mockups puede crear o reemplazar el documento editorial, pero nunca puede escribir el documento comercial. El website combina ambos únicamente al leer. Esta separación debe existir también físicamente en disco.

## Estructura paralela

```text
data/catalog-v2/
  editorial/{source_artwork_id}.json
  commerce/{source_artwork_id}.json
  sync-state/{source_artwork_id}.json
```

- `editorial`: contenido recibido, versionado e idempotente.
- `commerce`: decisiones privadas del sitio del artista.
- `sync-state`: última versión recibida, hash, fecha y URL pública.

El catálogo público actual continuará funcionando durante la construcción. No se modifica `content.json` y no se migran obras automáticamente.

## Identidad y URLs

- Clave técnica: `source_artwork_id`, por ejemplo `amw:10002`.
- El slug es una propiedad editable de presentación.
- Cambiar el título o slug no crea otra obra.
- El sitio debe conservar redirecciones si una URL pública cambia.

## Estados independientes

El estado comercial y el mapa no deben confundirse:

- Una obra vendida puede tener ubicación real aproximada.
- Una obra no vendida puede tener ubicación asignada para el mapa.
- Una obra disponible puede no mostrarse en el mapa.
- El mapa comunica presencia de obra, no necesariamente una venta.

## Contrato 2.0

La solicitud contiene `identity`, `editorial`, `artwork_facts` y `assets`. No contiene `price`, `currency`, `sale_status`, datos de comprador, pedidos ni pagos. El receptor debe rechazar cualquier intento de modificar campos comerciales a través de la sincronización editorial.

## Migración del catálogo existente

1. Inventariar sin escribir: website frente a Artwork Mockups.
2. Vincular por imagen, título y revisión humana; guardar el `source_artwork_id`.
3. Clasificar: vinculada, solo website, solo Artwork Mockups, posible duplicado.
4. Crear documentos editoriales V2.
5. Crear documentos comerciales únicamente con decisiones confirmadas.
6. Renderizar una vista paralela privada.
7. Comparar y aprobar por grupos.
8. Cambiar la lectura pública al catálogo V2.
9. Conservar el catálogo anterior durante una ventana de reversión.

## Etapas de implementación

1. Base paralela y pruebas de separación de datos.
2. API privada firmada con HMAC, caducidad e idempotencia.
3. Vista administrativa comercial en mauriziovalch.com.
4. Auditor de correspondencias del catálogo existente.
5. Vista previa privada del catálogo V2.
6. Publicación gradual y reversión.
7. Preparación posterior de reserva, carrito y checkout.

## Criterio de finalización

La integración estará completa cuando una obra pueda crearse o actualizarse desde Artwork Mockups, conservar intactos sus datos comerciales en el website, publicarse o despublicarse desde el sitio del artista y registrar su estado de sincronización sin duplicados.

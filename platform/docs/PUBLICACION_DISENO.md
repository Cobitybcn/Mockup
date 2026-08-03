# PUBLICACIÓN — Contrato de estructura y diseño de la sección

**Estado: VIGENTE.** Aprobado por el artista el 2026-07-31 (aprobación del plan de la Etapa 1).
Gobierna la **estructura y la interfaz** de la sección Publicación. El **contenido** lo sigue
gobernando `EDITORIAL_CORE.md`; este documento no lo pisa.

**Enmienda aplicada:** EDITORIAL_CORE VI.1 fue enmendado el 2026-07-31 — la decisión
«Publicar Obra» y la configuración comercial viven en esta sección; la ficha termina en
«obra resuelta».

**Nomenclatura:** arquitectura en inglés universal (archivos, clases, columnas, scopes, CSS:
`publication.php`, no `publicacion.php`); el español del artista va solo en la interfaz vía
`t('English', 'Español')`.

---

## I — Estructura

1. **Una sola sección, dos niveles.** Nivel 1: el **índice** — catálogo de obras con estado de
   distribución por destino (web ✓ · redes · Saatchi). Nivel 2: el **documento de trabajo por
   obra** — una sola página. Prohibido el wizard de pantallas separadas.
2. **El documento de trabajo son CINCO pasos ordenados por importancia** (enmienda
   2026-07-31, jerarquía del artista): 1 **Sitio web** — datos comerciales + composición de
   media + publicar la página (el más importante) · 2 **Saatchi Art** — el paquete manual y su
   estado (CTA primario por constitución) · 3 **Pinterest** — la serie completa de pins ·
   4 **Social** — Facebook, Instagram y X, series 3×3 con cadencia · 5 **TikTok** — naturaleza
   narrativa propia. Cada paso conserva UN acto con SU estado. Como atajo de orquestación,
   el cierre puede ofrecer **«Distribuir a todo lo conectado»**: no constituye un sexto paso,
   no mezcla estados y no reemplaza los actos individuales; ejecuta en orden solamente los
   destinos conectados que estén disponibles y conserva el resultado honesto de cada uno.
   El molde visual es el Espacio editorial de la ficha de obra. La media se selecciona UNA
   sola vez, en Sitio web; los pasos siguientes toman de esa composición — jamás re-eligen.

   **El producto es motor, no paso** (misma enmienda): la proyección congelada
   (`publication_products`) se genera y regenera SOLA cuando la página está publicada y las
   fuentes cambian — el artista no la opera ni la ve como decisión. Sigue siendo de solo
   lectura (projection-never-source), sigue trazándose por fingerprint, y todos los pasos de
   distribución leen SIEMPRE el producto al día. Generar la proyección no es aprobar nada:
   publicar=aprobar ocurre únicamente en el Paso 1.

   **Pinterest: destino explícito por Pin** (enmienda corregida 2026-08-03): el producto
   puede sugerir un tablero, pero el artista elige junto a cada Thumbnail Card uno de los
   tableros reales de la cuenta antes del envío. Como la cuenta puede tener muchos tableros y
   la decisión se repite por imagen, se usa un `<select>` compacto dentro de cada tarjeta —
   excepción explícita al art. III.13 solicitada por el artista. Cada resultado queda ligado
   al entorno API, a la imagen y a su tablero: Sandbox no cierra Production, y cambiar el
   tablero de una imagen reenvía solo ese Pin sin duplicar los demás.

   **Cadencia de las series sociales** (misma enmienda): «Publicar serie espaciada» es un solo
   acto — la parte 1 sale al momento y las siguientes se programan con un lapso default de
   **12 horas** (cubre husos horarios), ajustable UNA vez por usuario, nunca por obra. El
   estado es siempre honesto y visible: «PROGRAMADO · sale d/m H:i». Cada post de una serie
   lleva el copy editorial de su imagen líder — jamás el mismo caption repetido dentro del
   canal.
3. **Compuertas, no navegación.** Un panel se habilita cuando el anterior está resuelto. Los
   paneles no habilitados se ven inertes — nunca se ocultan: la progresión completa es visible
   siempre.
4. **La ficha de obra queda limpia: solo obra.** Identidad (título, serie, técnica, medidas,
   año), material visual, notas/memo y Espacio editorial. Termina en un único estado: **obra
   resuelta**. Se MUDAN a la sección Publicación todas las partes de website y e-commerce que
   hoy la contaminan: publicar/despublicar página, precio, disponibilidad, `purchase_url`
   (Saatchi), portada y estados de página.
5. **Una sola puerta.** Se publica únicamente desde la sección Publicación (grupo Publicar del
   menú). Prohibidos atajos, botones o ventanas de publicación dispersos por otras pantallas —
   incluida la ficha. El índice de la sección muestra qué obras resueltas están listas.
   Prohibido editar contenido de publicación en dos lugares (lección del TikTok Studio
   revertido: un caption, una pantalla).
6. **Fase 2 contemplada, no construida.** La *fuente* de una publicación admite obra individual
   (ahora) o grupo/serie/selección (después); el *ancla* admite página de obra existente (ahora)
   o landing generada (después); los adaptadores leen solo del producto terminado y jamás miran
   la fuente. Nada de fase A puede cerrar estos tres puntos de enchufe.

## II — Tipografía y espacio

7. **La escala se hereda, no se inventa.** Referencia: `artwork-editorial-package.css` —
   texto de trabajo 14px / interlineado 1.55, columna de lectura ~650px máx. El vaivén
   chico/gigante nace de que cada pantalla nueva inventa su escala; acá está prohibido.
8. **Nada editable bajo 13px.** Áreas de edición amplias, al ancho del panel, con contador
   visible cuando el destino impone límite (ej. caption TikTok 2200).
9. **Kickers y etiquetas uppercase solo para rótulos** — jamás para contenido editable ni
   para texto editorial.
10. **Contenido bilingüe en su modelo dual.** Cuando se muestra contenido editorial, va en el
   layout ES-fuente / EN-publicación del Espacio editorial. La publicación por destino elige
   UN idioma de forma explícita — nunca mezcla, nunca decide sola.

## III — Componentes

11. **Cero componentes inventados.** Solo el sistema existente: bloques de decisión,
    `.button-link` (primaria / secundaria / mini / danger), encabezados de panel kicker+título,
    tarjetas de media existentes. Un componente que no existe se boceta y se aprueba con el
    artista ANTES de escribir código.
12. **Bloques de decisión reservados a las decisiones reales:** Publicar Obra (Paso 1), un
    acto de publicación por paso de distribución (con confirmación explícita) y, al cierre,
    el atajo opcional «Distribuir a todo lo conectado». Uno primario por panel, nunca dos.
    El atajo global vive fuera de los paneles, sólo coordina los mismos actos y jamás altera
    su semántica. El producto NO es una decisión (es motor automático). Todo lo demás son
    acciones secundarias discretas.
13. **Prohibido `<select>` donde quepa elección visible.** Elegir video o mockups es con
    tarjetas visuales; elegir destinos es con fichas visibles que muestran su estado — no un
    desplegable. **Excepción Pinterest por Pin (2026-08-03):** cada Thumbnail Card conserva
    su imagen y lleva un selector compacto de tablero porque la misma elección se repite en
    una serie horizontal y la lista real puede ser extensa.
14. **TikTok: dos medios que conviven** (enmienda 2026-07-31). El Paso 5 publica **video** y
    **carrusel de imágenes** como dos publicaciones distintas de TikTok — publicar una jamás
    bloquea ni reemplaza a la otra. Sin video en la página, el carrusel sigue disponible: TikTok
    nunca es un callejón sin salida. El carrusel va por **Creator's Draft** (`MEDIA_UPLOAD`),
    porque es la única vía que le deja al artista elegir la música real; el precio es que
    termina a mano en su teléfono, y por eso su estado dice **«te espera en TikTok»** — jamás
    «publicado» hasta que TikTok confirme que el artista lo publicó desde su bandeja.
15. **Vocabulario de estado propio por destino.** Cada adaptador nombra sus estados según su
    mecánica real: Saatchi «pendiente de carga manual», TikTok según su modo de envío. Prohibido
    un «Publicado» genérico prestado entre destinos que no significan lo mismo.
16. **Meta: el video es un acto aparte de la serie** (enmienda 2026-08-02). Instagram y Facebook
    suman el video del sitio como **cuarta publicación**, con su propia fila, igual que TikTok
    separa video y carrusel: enviar el reel jamás toca la serie 3×3 ya publicada ni la reprograma.
    No entra como «una parte más» porque no lo es — en Instagram un video **es un Reel**, con su
    formato vertical y sus límites de duración, y en Facebook usa otro endpoint que el de foto.
    Aparece cuando la página tiene video, y su ausencia no bloquea nada: sin video, el Paso 4
    sigue siendo la serie de imágenes de siempre.

## IV — Verificación

16. **Vara estética por paso.** Cada panel se revisa en navegador contra la ficha de obra
    (Espacio editorial) como referencia, ANTES de construir el panel siguiente.
17. **Los errores no sobreviven.** Un error de un intento anterior jamás persiste después de un
    intento nuevo exitoso, ni contamina la tarjeta tras un refresh (lección del error TikTok
    heredado en Videos).

---

## Anexo — Decisiones de arquitectura que este contrato asume

- Flujo: la publicación NACE de la ficha de obra resuelta y SE VUELCA en Website — ahí se
  suman los datos comerciales y se compone la media (videos y mockups seleccionados). Con
  Website configurado y publicado se abren redes y marketplaces: proceso editorial universal
  servido de la memoria → producto terminado único → distribución por adaptador.
- Seis adaptadores, cada uno con su mecánica propia: Pinterest (cada imagen + su tablero elegido + link),
  Instagram (imagen/carrusel **y Reel**), Facebook (imagen/carrusel **y video**), TikTok (video
  con audio propio vía Direct Post; carrusel nativo con mecánica de audio a definir), X (lugar
  reservado, sin conexión), Saatchi Art (paquete manual: 4+ imágenes con caption, 12 keywords ≤20 caracteres
  derivadas de `tags` con dedupe semántico y sin repetir set entre obras similares, descripción;
  lazo de vuelta: pegar `purchase_url` del listing creado).
- **Saatchi tiene campos propios, no prestados** (enmienda 2026-08-01). Sus tres topes son de
  la plataforma y se miden en caracteres: título ≤65 con espacios, descripción ≤1000, pie de
  imagen <50. Ningún campo del sitio entra en ese presupuesto, así que el paso 2 no reusa los
  textos de la página: los recibe generados para Saatchi. El título se compone
  `TÍTULO - cola SEO` con la cola calculada por obra, y si no entra, viaja el título solo.
- Campañas y estudios anteriores: descartados como base. Solo se asumen las conexiones y el
  CORE editorial. Los tableros reales de Pinterest se leen desde la cuenta conectada para que
  el artista elija el destino; no se reutiliza el tablero como fuente editorial.

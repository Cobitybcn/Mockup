# Estrategia de keywords — mauriziovalch.com

Investigación, clasificación y asignación. **No se modificó nada del sitio**: ni títulos SEO, ni
meta descriptions, ni H1, ni canonical, ni hreflang, ni schema, ni sitemap, ni robots, ni URLs, ni
contenido, ni títulos de obras o series.

Generado el 2026-08-04 a partir del catálogo real de producción, leído sin escribir.
Los archivos los produce `build_keywords.php`, de modo que cada término asignado a una obra se
puede rastrear hasta la regla que se lo dio y hasta el dato que la justifica.

## Lo que hay, medido

| | |
|---|---|
| Series definidas | 8 |
| Series con obra publicada | 6 — STRATA (10), SITUS (4), EMERSIO (2), MARE SOMNIORUM (2), LIMINA (2), PRIMORDIUM (1) |
| Series sin obra publicada | VORTEX (2017-2019), FACIES (2019-2023) |
| Obras publicadas | 21 |
| Mockups con página y contenido propio | **223** |
| Términos del diccionario | 247 — 124 en inglés, 123 en castellano |

Dos correcciones al enunciado, con la evidencia delante: existe la serie **SITUS**, que no estaba en
tu lista y tiene cuatro obras publicadas; y **VORTEX y FACIES no tienen ninguna obra publicada**, así
que sus keywords quedan definidas pero sin obra a la que aplicarse.

## 1. Qué búsquedas puede cubrir el sitio

Cinco entradas, de más amplia a más estrecha:

**Categoría** — *contemporary abstract painting*, *pintura abstracta contemporánea*. Amplias, muy
competidas, prioridad baja o media: describen el género, no esta obra.

**Estilo propio** — *territorial abstraction*, *structural abstraction*, *brutalist abstract
painting*. Aquí empieza el terreno defendible: son términos que el propio catálogo ya declara en sus
tags y que poca gente disputa.

**Rasgo visible** — *layered abstract painting*, *abstract painting with incised lines*,
*color field painting*. Describen lo que se ve y solo se asignan cuando el texto publicado lo afirma.

**Color, formato y orientación** — *large red abstract painting*, *vertical abstract painting*.
Es como busca quien compra: por lo que quiere colgar.

**Intención de compra** — *original abstract paintings for sale*, *buy art directly from the
artist*. Pocas, pero son las de mayor intención.

## 2. Territorios semánticos

Cada serie tiene el suyo, y no se pisan:

| Serie | Keyword principal EN | Keyword principal ES | Rasgos diferenciales |
|---|---|---|---|
| STRATA | stratified abstract painting | pintura abstracta estratificada | capas, incisiones, campos cromáticos densos, bloques endurecidos, erosión |
| SITUS | minimalist abstract painting with negative space | pintura abstracta minimalista con espacio negativo | posición, distancia, formas elementales situadas, vacío activo |
| LIMINA | abstract painting of thresholds and passage | pintura abstracta de umbrales y tránsito | planos superpuestos, horizontes, recintos, escaleras, bloques suspendidos |
| MARE SOMNIORUM | oneiric abstract landscape painting | pintura de paisaje abstracto onírico | horizontes amplios, luz, planos abiertos, bloques aislados |
| EMERSIO | abstract painting of emerging forms | pintura abstracta de formas emergentes | aparición de la forma, separación del territorio |
| PRIMORDIUM | primordial abstract painting | pintura abstracta primordial | suelo originario, monolitos, estructuras elementales |
| VORTEX | abstract painting with concentric forms | pintura abstracta de formas concéntricas | torsión, circulación, dinámica centrífuga |
| FACIES | abstract face painting | pintura abstracta de rostros | rostro como superficie construida y fragmentada |

**STRATA no usa escaleras** — esa regla se respetó, y las escaleras quedaron donde el material dice
que están: en LIMINA, que las declara entre sus elementos.

## 3. Categorías creadas

| Categoría | Términos |
|---|---|
| long_tail | 74 |
| concept | 30 |
| visual_characteristic | 26 |
| color | 25 |
| artistic_category | 24 |
| style | 24 |
| purchase_intent | 14 |
| technique_material | 12 |
| format_size_orientation | 10 |
| navigational | 8 |

Por intención: 119 comerciales, 72 de descubrimiento, 36 informativas, 14 transaccionales, 6 de
navegación. Por amplitud: 78 long-tail, 69 medias, 58 específicas, 42 amplias.

## 4. Qué series se diferencian mejor

**SITUS** es la más nítida: el vacío activo y la distancia entre formas no aparecen en ninguna otra
serie, y sus obras se apoyan en azules y negros donde STRATA se apoya en rojos y ocres.

**MARE SOMNIORUM** también: horizonte y luz abierta la separan del resto.

**STRATA** es la más difícil, y no por falta de identidad sino por volumen: diez de veintiuna obras
son suyas, así que sus rasgos —capas, incisiones, bloques— aparecen en la mitad del catálogo y dejan
de distinguir dentro de él.

**EMERSIO y PRIMORDIUM** comparten vocabulario —territorio originario, aparición de la forma— y con
una y dos obras respectivamente no hay material suficiente para separarlas bien. Confianza media.

## 5. Términos demasiado amplios

`abstract art`, `contemporary art`, `contemporary painting`, `abstract painter`. Están en el
diccionario porque el sitio debe poder ser encontrado por ellos, pero con **prioridad baja**: la
competencia es enorme y no describen nada propio. No deben usarse como keyword principal de una obra.

## 6. Los de mayor intención de compra

En orden: `buy art directly from the artist` (specific, sin intermediarios), `original abstract
paintings for sale`, `large original painting for sale`, `buy original abstract painting`, y las
navegacionales con tu nombre —`Maurizio Valch original artwork`—, que son las de mayor intención de
todas porque quien las escribe ya te conoce.

## 7. Los que se repiten demasiado

**53 términos aparecen en 18 o más de las 21 obras.** Están en `keywordConflicts`. Los peores casos
llegan a las 21: `contemporary abstract painting`, `acrylic and oil on canvas`, `textured surface`,
`large abstract painting`, `territorial abstraction`.

Eso no los invalida —son ciertos en las 21— pero significa que **no sirven para distinguir una obra
de otra**. Su lugar es la página del artista y las de serie. En la ficha de una obra deben ir
después de lo que la diferencia, nunca primero.

## 8. Qué datos faltan

**El campo técnica está vacío en las 21 obras.** La técnica —acrílico y óleo sobre lienzo— aparece
en el texto de cada descripción, pero no en el dato estructurado. Por eso las keywords de técnica se
tomaron del texto, y por eso el `artMedium` del schema sale vacío.

**No hay dato de color dominante.** Se leyó la apertura de cada descripción publicada y se anotó la
frase que lo declara. Buscar el color por expresión regular no servía: marcaba "rojo" en las 21
obras porque casi todas lo mencionan en algún lado.

**Los 223 mockups tienen sus propias keywords** —todos, sin excepción— pero **no viajan al JSON-LD de
su página**: van al `<meta name="keywords">`, que los buscadores ignoran desde 2009. Ese es el hueco
más grande que encontré, y es de implementación, no de contenido.

## 9. Obras de baja confianza

Ninguna quedó en baja. Diecinueve en **alta** —su descripción declara explícitamente el color
dominante— y dos en **media**:

- **SUBMERSA**: el color dominante se tomó de los tags (`Indigo Blue, Red`), no de la apertura del
  texto.
- **INTERVALLUM**: la descripción habla de "campos cromáticos en bandas horizontales" sin nombrar un
  dominante, así que se clasificó como multicolor.

## 10. Vocabulario a evitar

Excluido de todas las obras: `investment art`, `guaranteed investment`, `museum quality`,
`masterpiece`, `highly collectible`, `important artist`.

Y los nombres de otros pintores como keyword de una ficha comercial: `Rothko style painting`,
`painting like Rothko`, `inspired by Newman`. Las afinidades existen y están documentadas en el
perfil del artista, con el fundamento de cada una, pero pertenecen a la página del artista y al
listing de Saatchi, no a la keyword principal de una obra.

Además, cada serie excluye lo que sus propios límites interpretativos prohíben: STRATA excluye la
lectura geológica, SITUS que sus rectángulos sean casas o puertas, MARE SOMNIORUM el mar literal y
la lectura surrealista, EMERSIO y PRIMORDIUM el relato religioso del origen.

## Obras indexables

| Obra | Serie | Keyword principal EN | Keyword principal ES | Confianza |
|---|---|---|---|---|
| CUSTODES | EMERSIO | large orange abstract painting with incised lines | pintura abstracta naranja de gran formato con líneas incisas | alta |
| LIMEN | EMERSIO | large blue abstract painting with incised lines | pintura abstracta azul de gran formato con líneas incisas | alta |
| SUBMERSA | STRATA | large layered blue abstract painting | pintura abstracta azul de gran formato en capas | media |
| ASCENSUS | STRATA | large layered green abstract painting | pintura abstracta verde de gran formato en capas | alta |
| SIGNUM | STRATA | large blue abstract painting with geometric blocks | pintura abstracta azul de gran formato con bloques geométricos | alta |
| VESTIGIA | STRATA | large red abstract painting with incised lines | pintura abstracta roja de gran formato con líneas incisas | alta |
| FISSURA | STRATA | large earth tone abstract painting with incised lines | pintura abstracta en tonos tierra de gran formato con líneas incisas | alta |
| LUX REMOTA | MARE SOMNIORUM | large blue abstract painting with horizon lines | pintura abstracta azul de gran formato con líneas de horizonte | alta |
| SOL DIVISUS | MARE SOMNIORUM | large layered orange abstract painting | pintura abstracta naranja de gran formato en capas | alta |
| VIA SOLIS | PRIMORDIUM | large orange abstract painting with geometric blocks | pintura abstracta naranja de gran formato con bloques geométricos | alta |
| INTERVALLUM | LIMINA | large multicolor abstract painting with incised lines | pintura abstracta multicolor de gran formato con líneas incisas | media |
| ADITUS | LIMINA | large blue and earth tone abstract painting with incised lines | pintura abstracta en azul y tierra de gran formato con líneas incisas | alta |
| TRIA | STRATA | large teal abstract painting with incised lines | pintura abstracta turquesa de gran formato con líneas incisas | alta |
| NEXUS | STRATA | large green abstract painting with geometric blocks | pintura abstracta verde de gran formato con bloques geométricos | alta |
| DISPERSA | STRATA | large layered red abstract painting | pintura abstracta roja de gran formato en capas | alta |
| QUINQUE | STRATA | large ochre abstract painting with incised lines | pintura abstracta ocre de gran formato con líneas incisas | alta |
| DECLIVIS | STRATA | large red abstract painting with geometric blocks | pintura abstracta roja de gran formato con bloques geométricos | alta |
| GRADUS | SITUS | large textured blue abstract painting | pintura abstracta azul de gran formato matérica | alta |
| COACTUS | SITUS | large blue and red abstract painting with incised lines | pintura abstracta en azul y rojo de gran formato con líneas incisas | alta |
| CLIVUS | SITUS | large layered blue and red abstract painting | pintura abstracta en azul y rojo de gran formato en capas | alta |
| SINGULA | SITUS | large blue and black abstract painting with incised lines | pintura abstracta en azul y negro de gran formato con líneas incisas | alta |

Las 21 principales son distintas entre sí. Cuando dos obras coincidían —tres rojas de STRATA daban
la misma frase— la que colisionaba se profundizó con su segundo rasgo y, si hacía falta, con su
combinación de dos colores. Ninguna se resolvió inventando un rasgo que la obra no tenga.

## Los mockups

Cada obra abre entre 5 y 18 páginas de mockup con su propio texto: 223 en total, y **las 223 ya
tienen descripción, tags y keywords propias**. No hay que generarlas.

Lo que falta es que **lleguen al canal que se lee**. Hoy el bloque de datos estructurados de un
mockup declara `ImageObject` con nombre, descripción, URL y su obra, pero **sin `keywords`**. Las que
existen van al meta tag que nadie lee.

Es el mismo hueco que tenían las fichas de obra hasta hoy, y se arregla igual: llevarlas al JSON-LD.
Queda señalado, no implementado, porque esta tarea era de investigación.

## Lo que este trabajo no puede darte

Ninguna de estas 247 keywords está validada contra búsquedas reales. Son términos plausibles,
derivados de lo que las obras efectivamente son, pero **nadie midió si alguien los escribe**.

Los datos reales existen y son tuyos: **Google Search Console** te dice qué consultas ya muestran tu
sitio y cuáles reciben clics. Y tu propio buscador interno registra lo que escriben los visitantes.
Cruzar este diccionario con esas dos fuentes es lo que lo convertiría de hipótesis en estrategia.

## Archivos

- `keywords-master.json` — 247 términos clasificados, con `keywordConflicts`
- `keywords-by-series.json` — 8 series con su territorio y sus exclusiones
- `keywords-by-artwork.json` — 21 obras con su asignación, fuentes y confianza
- `build_keywords.php` — el generador, para que la asignación sea reproducible y auditable

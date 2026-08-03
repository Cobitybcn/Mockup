# Acta de cimientos — Artwork Mockups

**Qué es este documento.** El inventario de lo que el sistema *hace* en producción, levantado
el 2026-08-03 leyendo únicamente código desplegado, migraciones, tests y configuración de
deploy. Ningún documento previo se usó como fuente.

**Qué autoridad tiene.** Ninguna por sí mismo. La autoridad es producción; la autoridad
ejecutable son los tests que gatean cada deploy. Este documento es el índice legible de eso.
Si el acta y el código discrepan, **manda el código**. Si el acta y un test discrepan, manda
el test.

**Regla 1.** Lo que hoy funciona en la interfaz del artista y del admin son los cimientos.
Ningún cambio puede romperlos.

---

## 1. Qué corre en producción

Cuatro piezas en Google Cloud Run, proyecto `project-ff549db7-4f7f-4b0c-9a5`:

| Servicio | Qué es | Exposición |
|---|---|---|
| `mockups-web` | La plataforma (`platform/`) **más** `site-admin/` montado en `/site-admin` dentro del mismo contenedor | Público |
| `mockups-worker` | Mismo código, sin site-admin. Ejecuta trabajos asincrónicos | **Privado** (IAM; el CI aborta si alguien lo abre a `allUsers`) |
| `mockups-db-migrate` | Job efímero. Única pieza autorizada a tocar el esquema | Interno |
| `mockups-artist-site` | mauriziovalch.com (`artist-site/`), contenedor propio | Público |

Datos: un solo MySQL en Cloud SQL, compartido por las cuatro piezas. El sitio público **no
tiene base propia**: lee el catálogo publicado del mismo MySQL de la plataforma.

## 2. Cómo llega el código a producción

Push a `main` → dispara Cloud Build. Dos triggers separados por ruta (`platform/**` +
`site-admin/**` por un lado, `artist-site/**` por el otro). Un tercer trigger corre preflight
en ramas `codex/*` sin desplegar.

El pipeline, en orden: verifica proyecto/rama/service accounts y que el worker no sea público
→ calcula el alcance por diff contra la revisión viva → construye → **corre la suite de
regresión dentro de la imagen exacta** → si el diff toca `migrations/schema/`, ejecuta el job
de migración y espera → despliega sin tráfico → smoke HTTP contra la revisión candidata →
recién ahí mueve el 100% del tráfico.

**Los documentos no participan:** los triggers ignoran explícitamente `**/*.md` y
`platform/docs/**`. Se pueden borrar todos los markdown del repo y no se dispara ni un build.

## 3. Los cimientos, por superficie

### Interfaz del artista (~50 pantallas en `platform/`)

El flujo vivo: alta y onboarding obligatorio → **Create Art** (elegir escena, subir obra con
medidas) → generación de imagen raíz → selección de candidato → **ArtWorks** (álbum) → **ficha
de obra** (la pantalla mayor del sistema: título, serie, análisis, mockups, video, paquete
editorial) → **Scenes** (combinaciones escena × slots de cámara) → **Art Mockups**
(resultados) → **Publicación**.

Guardias activas: onboarding, política de idiomas, matriz de planes, orden de favoritos,
ranking de escenas, doble referencia world-mother por mockup, importación de mockups externos,
showcase público sin repetición.

### Publicación — la puerta única

`publication.php` es el hub por obra. Exige contenido editorial generado antes de publicar,
congela un **producto terminado** por publicación (con huella de origen para detectar
desactualización) y recién entonces distribuye. Destinos: Pinterest, Instagram (+video),
Facebook (+video), TikTok (+carrusel), X (+video) y **Saatchi manual** — Saatchi no tiene API
usable, se genera un paquete ZIP descargable (lado corto 2200px + textos) para subida a mano.

Guardias activas: los adaptadores publican leyendo **solo** el producto congelado; compuertas
de dominio antes de cualquier transporte; un idioma por envío; un error no sobrevive a un
reintento exitoso; el formato del ZIP (escrito a mano porque el runtime no trae `ext-zip`).

### Motor editorial bilingüe

Español es la fuente, inglés se adapta. La edición del artista es soberana: lo editado a mano
nunca se pisa. Regenerar **refina** sobre la lectura vigente, no arranca de cero.

Guardias activas, y son literalmente las reglas que los documentos decían custodiar: **el
sistema nunca produce títulos** (el título entra como hecho inmutable; el esquema de salida no
tiene campo título), la compuerta de integridad rechaza afirmaciones de prestigio o inversión
sin sustento en ambos idiomas, y el producto es proyección y nunca fuente.

### Interfaz admin

Usuarios y créditos (planes `artist_studio` / `artist_pro`, overrides por usuario, auditoría
inmutable de accesos), prompts del sistema, proveedores de IA y credenciales, biblioteca de
escenas (world mothers) y Camera Boards.

Hecho estructural: **toda la configuración de runtime vive en la tabla `app_settings`**, no en
el código. Los prompts maestros y las API keys se editan desde el admin; `.env` es solo
respaldo.

### Sitio público (mauriziovalch.com)

Multi-tenant real: resuelve el artista por dominio verificado o subdominio. Prefijo de idioma
obligatorio (`/es/`, `/en/`) con canónicas y hreflang, sitemap bilingüe cacheado, JSON-LD.
Venta directa con Stripe por artista (credenciales cifradas, propias de cada uno), formulario
de contacto por SMTP con límite de 5/hora, y métricas propias sin cookies ni IP.

El admin legado sobre JSON **está apagado en producción**: `/admin` y `/admin-v2` redirigen
siempre al site-admin de la plataforma.

### Trabajos asincrónicos

Siete handlers en el worker privado, todo encolado por Cloud Tasks con OIDC: mockups, imagen
raíz, publicación social, distribución de publicación, editorial, generación de video y export
de video. No hay cron ni scheduler: la temporización es `scheduleTime` de Cloud Tasks o
reencolado propio.

Patrón uniforme: el estado terminal se persiste y se responde 200 para que Cloud Tasks no
reintente a ciegas. Un fallo de mockup **devuelve el crédito**.

### Esquema de datos

Ledger inmutable de migraciones con checksum verificado en cada arranque: **editar una
migración ya aplicada rompe todos los requests**. En producción el runtime nunca migra; solo
el job de deploy lo hace. Toda migración nueva debe sobrevivir una base vacía — hay un test
que corre la cadena completa sobre SQLite en memoria, dos veces.

## 4. La constitución ejecutable

La suite de regresión que gatea cada deploy. Al 2026-08-03: **1626 aserciones, 0 fallos**.
Cubre desde el pipeline mismo (se auto-custodia: verifica sus propias reglas de deploy) hasta
el hardening de Apache, la matriz de planes y las reglas editoriales.

En esta sesión se sumaron 9 tests que existían pero no gateaban nada, y ahora sí: límite de 3
boards por lote en Pinterest, `appsecret_proof` de Meta, idempotencia de los jobs sociales,
puentes de campañas, aislamiento entre la app Pinterest del artista y la de plataforma, e
invariantes de publicación/series.

## 5. Riesgos abiertos, confirmados

1. **Los overrides de cámaras se pierden en cada deploy.** Camera Boards escribe
   `app/Config/mockup_camera_slots_custom.php` en el disco del contenedor; no hay sincronización
   a GCS ni a la base. La imagen trae la versión de git, del 2026-07-19: toda edición de
   cámaras hecha en producción desde entonces se perdió en el siguiente deploy.
2. **El video casi no tiene red de seguridad.** Sus ~17 endpoints y servicios dependen de dos
   tests manuales que exigen base de datos y FFmpeg reales; en CI solo corre el cálculo de
   rangos de bytes.
3. **Los snapshots se auto-crean.** Si falta un fixture, el arnés lo genera y el test pasa:
   borrar un fixture convierte su test en un no-op silencioso.
4. **Dos generaciones de flujo social conviven** (pantallas de batch heredadas junto al camino
   nuevo de Publicación) y ambas llaman APIs reales.

## 6. Lo que no es cimiento

Verificado sin consumidores: `dashboard.php` (redirect con ~400 líneas inalcanzables debajo),
la cadena legacy de publicación (`publishing_studio.php` no lo referencia nadie), las 9
secciones muertas de site-admin cuyos POST siguen vivos, las `views/` del sitio público que
ningún include carga, `NextPlatformSync` (invoca un script que no existe en ningún entorno),
lápidas (`get_users.php`, `update_snapshot.php`, `run_migration_*.php`), y los dos tests de
cámara desactivados a propósito el 2026-07-16 por obsoletos.

Retirar esto es seguro, pero **nada de esto molesta a producción hoy**: es orden, no urgencia.

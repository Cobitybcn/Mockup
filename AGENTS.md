# Artwork Mockups — reglas de trabajo

## 1. La fuente de verdad es producción

Lo que corre y funciona hoy en la interfaz del artista y del admin son los cimientos.
Ningún cambio puede romperlos.

Cuando una fuente contradiga a otra, este es el orden: **producción → tests → código →
documentos**. Un documento nunca gana. Si un `.md` de este repo dice algo distinto de lo
que hace el código, el documento está viejo.

`ACTA_DE_CIMIENTOS.md` describe qué hace el sistema hoy y dónde está cada guardia. Es el
punto de partida para entender el proyecto, no una orden.

## 2. La ley ejecutable son los tests

`platform/tests/run_regression_tests.php` gatea cada despliegue y es lo único que
verifica de verdad las reglas del sistema (identidad editorial, distribución, planes,
migraciones, hardening). Debe quedar en verde.

Una regla que importa se escribe como test, no como párrafo. Si una regla solo existe en
prosa, no existe.

Local sirve para diagnosticar; producción es obligatoria para afirmar. Ejecutar algo en
localhost, leer el código o mirar un mensaje de commit alcanza para investigar. En el
momento en que una frase describe lo que el sistema *hace*, hay que haberlo verificado
contra producción — y decir de dónde salió el dato.

`CLAUDE.md` repite estas reglas porque Claude Code carga ese archivo y no este. Si los dos
difieren, manda este.

## 3. Alcance de una tarea

Hacé lo que se pidió y nada más. En particular, **ninguna auditoría, diagnóstico o
reparación local autoriza por sí sola** commit, push, migración, despliegue, borrado ni
escritura hacia servicios externos. Cada una de esas acciones se pide explícitamente.

Antes de dar algo por roto, verificalo contra producción o contra git. Deducir del código
que algo falla no alcanza: en este repo esa deducción ya falló varias veces.

## 4. Cómo llega un cambio a producción

Rama `codex/*` → preflight → revisión → squash merge a `main`. El push a `main` dispara
Cloud Build, que corre los tests, aplica migraciones si las hay, despliega sin tráfico,
hace smoke y recién entonces promueve.

Nunca commits sueltos a `main`: cada commit aceptado ahí es una release inmutable.

## 5. Cosas que rompen todo

- Editar una migración ya aplicada: el checksum falla y **cae cada request**. Las
  migraciones son inmutables; los cambios van en una nueva.
- Tocar `app/Config/mockup_camera_slots_custom.php` esperando que persista: se reescribe
  en cada despliegue.
- Borrar un fixture de `tests/fixtures/`: el arnés lo regenera y el test pasa vacío.

## 6. Documentación histórica

El resto de los `.md` del repo (`platform/docs/`, `design-system/`, handoffs) es material
de referencia y contexto. Puede estar desactualizado o contradecirse entre sí: no tiene
autoridad y no hay obligación de leerlo antes de trabajar. Consultalo si ayuda; verificá
contra el código antes de actuar sobre lo que diga.

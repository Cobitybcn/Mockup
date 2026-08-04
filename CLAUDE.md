# Artwork Mockups

**Las reglas completas están en `AGENTS.md`. Leelo.** Este archivo existe solo porque
Claude Code carga `CLAUDE.md` y no `AGENTS.md`; si los dos dijeran cosas distintas, manda
`AGENTS.md`.

Lo esencial, para que rija desde el primer momento:

1. **La fuente de verdad es producción.** Lo que hoy funciona en la interfaz del artista y
   del admin son los cimientos y no se rompen. Orden de autoridad cuando algo se
   contradice: producción → tests → código → documentos.

2. **Local sirve para diagnosticar; producción es obligatoria para afirmar.** Ejecutar algo
   en localhost, leer el código o mirar un mensaje de commit alcanza para investigar. En el
   momento en que una frase describe lo que el sistema *hace*, hay que haberlo verificado
   contra producción. Y decir de dónde salió el dato: "verificado en producción", "leído del
   código", "según el historial". Sin eso, la afirmación no vale.

3. **La ley ejecutable son los tests.** `platform/tests/run_regression_tests.php` gatea cada
   despliegue y debe quedar en verde. Una regla que importa se escribe como test, no como
   párrafo: si solo existe en prosa, no existe.

4. **Ninguna auditoría, diagnóstico o reparación autoriza por sí sola** commit, push,
   migración, despliegue, borrado ni escritura hacia servicios externos. Cada una de esas
   acciones se pide explícitamente.

5. **Antes de dar algo por roto, verificalo.** Deducir del código que algo falla no alcanza:
   en este repo esa deducción ya fue falsa varias veces (defensas que ya estaban puestas,
   tests desactivados a propósito, tráfico fijado a revisiones con nombre).

`ACTA_DE_CIMIENTOS.md` describe qué hace el sistema hoy y dónde está cada guardia. El resto
de los `.md` del repo es referencia histórica sin autoridad.

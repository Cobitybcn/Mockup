# Artwork Mockups

Antes de realizar cualquier tarea, lee este archivo completo.

Este AGENTS.md aplica a todo el repo, incluidos `platform/`, `site-admin/` y `artist-site/`. No tienen ni necesitan su propio AGENTS.md para reglas visuales (`artist-site/AGENTS.md` cubre solo estrategia de marca/SEO, no UI).

Si la tarea afecta la interfaz de usuario, lee estos documentos **en este orden, completos, sin saltarte ninguno**:

1. `design-system/00_STUDIO_PROTOCOL.md` — qué es y qué no es la app.
2. `design-system/01_VISUAL_LANGUAGE.md` — vocabulario visual conceptual.
3. `design-system/02_COMPONENTS.md` — reglas de cada componente reutilizable (Decision Block, Thumbnail Card, Workspace Panel, Toolbar, Badge, Counter, etc.). **Obligatorio antes de tocar cualquier panel.**
4. `design-system/03_INTERACTION_PATTERNS.md` — cómo se trabaja en la app (drag, boards, asignación visual).
5. `design-system/04_FORBIDDEN_PATTERNS.md` — lo que nunca debe aparecer (dashboards, KPIs, Bootstrap Admin, glassmorphism exagerado, etc.).
6. `design-system/UI_PREFERENCES.md` — preferencias heredadas (formularios, paneles, tipografía) cuando una referencia específica no dice nada.
7. Busca la referencia visual más parecida dentro de `design-system/references/`, revisa su `screenshot.png` y su `notes.md`.
8. Si el problema es un patrón recurrente (panel, tarjeta, header, carrusel, acción primaria...), revisa también `design-system/MASTER_PATTERNS/` — son las invariantes y prohibiciones específicas de ese patrón.

No diseñes una interfaz nueva cuando ya exista una referencia o Master Pattern similar. Una nueva funcionalidad no justifica un nuevo diseño.

Si una pantalla está marcada `DO NOT TOUCH` o `PASS` en `design-system/audits/VISUAL_CONSISTENCY_MATRIX.md`, no la rediseñes salvo que la tarea lo pida explícitamente.

Aplica siempre estas preferencias:

- Áreas para escribir amplias y cómodas.
- Texto principal de tamaño cómodo, similar al usado en ChatGPT.
- Paneles desplegables plegados por defecto cuando sea posible.
- Botones principales de acción con colores pastel suaves, apariencia elegante y gran presencia visual.
- Reutilizar componentes existentes antes de crear otros nuevos.
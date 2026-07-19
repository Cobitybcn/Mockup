# PREVIEW IMPLEMENTATION PLAN

## Objetivo

Definir y registrar una forma reversible y aislada de probar correcciones de consistencia visual. La implementación actual contiene los scopes `artworks-kpi` y `series-catalog`; los scopes restantes continúan siendo propuestas futuras.

La vista normal debe seguir siendo byte a byte equivalente en comportamiento y visualmente idéntica mientras la preview esté desactivada. El mecanismo no debe modificar datos, prompts, generación, navegación ni páginas públicas.

## Estado de implementación

Implementado el 19 de julio de 2026:

- puerta maestra `UI_VISUAL_CONSISTENCY_PREVIEW`, desactivada por defecto en `.env.example`;
- comprobación server-side de usuario ADMIN, con allowlist explícita solo para revisores en `APP_ENV=local`;
- registro cerrado con los scopes `artworks-kpi` y `series-catalog`;
- activación explícita mediante `?design_preview=artworks-kpi`;
- CSS independiente y completamente encapsulado en `visual-consistency-preview.css`;
- aviso visible con salida directa de la preview;
- conservación del scope al filtrar por serie;
- pruebas de regresión para flag, rol, query, alcance y aislamiento del CSS.

La implementación local tiene la puerta maestra apagada y la allowlist de revisores vacía. Sin reactivación explícita, la ruta permanece sin clase, atributo, aviso ni hoja de estilos de preview aunque la URL conserve un parámetro antiguo.

## Condiciones obligatorias

- Solo disponible para ADMIN fuera del entorno local; en local puede habilitarse un revisor explícito por email.
- Desactivada por defecto.
- Sin efecto para usuarios públicos, no ADMIN o no incluidos expresamente como revisores locales.
- Sin escritura en base de datos ni preferencias de dominio.
- Sin CSS inline.
- Sin páginas duplicadas ni segunda aplicación.
- CSS adicional separado.
- Markup mínimo, condicional y encapsulado.
- Cada ajuste activable de manera independiente cuando sea viable.
- Desactivación inmediata retirando un flag o el parámetro.

## Opciones

### A. Query parameter: `?design_preview=1`

**Ventajas**

- Activación por request, sin persistencia.
- Fácil de compartir entre ADMINs y comparar en dos pestañas.
- Desactivación inmediata al quitar el parámetro.
- Adecuado para scopes independientes, por ejemplo `?design_preview=artworks-kpi`.

**Riesgos**

- El parámetro solo no es una barrera de acceso.
- Puede propagarse accidentalmente por links si se añade globalmente.
- Necesita validación estricta contra una lista cerrada de scopes.

**Uso recomendado**

Como selector de preview por request, siempre detrás de verificación ADMIN y de un feature flag del servidor.

### B. ADMIN toggle: `Preview Visual Consistency`

**Ventajas**

- Descubrible para el equipo autorizado.
- Permite activar/desactivar sin editar URL.
- Puede mostrar claramente el estado experimental.

**Riesgos**

- Si persiste en base de datos, añade estado innecesario y complica rollback.
- Si se incorpora a navegación antes de validar el patrón, modifica la interfaz normal.
- Un toggle global dificulta probar scopes independientes.

**Uso recomendado**

No incluirlo en la primera iteración. Si se añade después, debe vivir en el panel ADMIN ya plegado y guardar estado solo en sesión, nunca en datos de usuario o negocio.

### C. Feature flag: `UI_VISUAL_CONSISTENCY_PREVIEW`

**Ventajas**

- Kill switch inmediato por entorno.
- Garantiza que la función no exista por defecto.
- Permite desplegar código inerte antes de habilitar pruebas.
- Facilita retirada completa.

**Riesgos**

- Un flag global no selecciona por sí mismo ADMIN ni scope.
- Si se usa solo, podría activar la preview para todos.

**Uso recomendado**

Como puerta maestra obligatoria, combinada con rol ADMIN y opt-in por request.

### D. CSS layer: `main.css + visual-consistency-preview.css`

**Ventajas**

- Aislamiento claro y eliminación sencilla.
- Evita contaminar estilos productivos aprobados.
- Permite scope por clase raíz y por ajuste.
- Hace visible en revisión qué reglas son experimentales.

**Riesgos**

- No resuelve cambios semánticos o duplicación de controles.
- Selectores demasiado amplios podrían escapar del modo preview.
- Sobrescribir CSS complejo puede ocultar deuda de markup.

**Uso recomendado**

Cargar el archivo solo cuando las tres condiciones anteriores se cumplan y scopear todas sus reglas bajo una clase raíz de preview.

## Recomendación

Usar una combinación de C + A + D:

1. `UI_VISUAL_CONSISTENCY_PREVIEW=false` por defecto.
2. Verificación server-side de que el usuario autenticado es ADMIN o un revisor explícito en entorno local.
3. Opt-in explícito por query parameter, con scopes permitidos.
4. Clase raíz única, por ejemplo `ui-visual-consistency-preview`, aplicada solo en el request autorizado.
5. Atributos de scope independientes, por ejemplo `data-ui-preview="artworks-kpi primary-actions"`.
6. Carga condicional de `visual-consistency-preview.css` después del CSS normal.

La condición conceptual sería:

```text
feature flag ON
    AND (authenticated ADMIN OR explicit local reviewer)
    AND valid design_preview scope
        -> add preview root class
        -> load separate preview CSS
        -> enable only minimal scoped markup branches
otherwise
        -> render current UI unchanged
```

No se recomienda el query parameter solo, ni un toggle persistente como primera implementación.

## Registro cerrado de scopes

La preview no debe aceptar nombres arbitrarios. Orden propuesto; los dos primeros scopes están implementados:

1. `artworks-kpi` — implementado
2. `series-catalog` — implementado
3. `primary-actions`
4. `mockup-lab-controls`
5. `artist-profile-writing`
6. `video-lab-flow`
7. `scene-studio-density`
8. `access-gate-shell`
9. `publishing-headers`

Cada scope debe:

- tener un único objetivo verificable;
- identificar rutas permitidas;
- declarar si usa solo CSS o markup condicional;
- poder eliminarse sin afectar otros scopes;
- no cambiar eventos, endpoints ni datos enviados.

## Markup mínimo

Los cambios de markup deben limitarse a:

- clases o `data-*` condicionales en contenedores existentes;
- wrappers necesarios para reutilizar un patrón ya existente;
- cambio semántico de heading cuando la jerarquía actual sea incorrecta;
- ocultación visual experimental sin eliminar controles del DOM cuando exista riesgo funcional.

No se deben duplicar páginas, formularios, navegaciones ni componentes completos.

## Orden de implementación

1. Añadir la puerta maestra y la comprobación ADMIN sin scopes activos. — completado
2. Añadir carga condicional del CSS separado con archivo inicialmente vacío. — completado
3. Implementar `artworks-kpi` como primer scope. — completado
4. Verificar que la ruta sin parámetro no cambia. — completado mediante regresión y navegador
5. Implementar `primary-actions` con una lista explícita de pantallas.
6. Añadir scopes P1 de uno en uno, nunca en un override global.
7. Mantener `Camera Boards` y `Account` fuera de la preview inicial.

## Verificación requerida

Para cada scope:

- ADMIN con flag OFF: interfaz actual sin cambios.
- ADMIN con flag ON y sin query: interfaz actual sin cambios.
- ADMIN con query inválida: interfaz actual sin cambios.
- Usuario no ADMIN con query válida: interfaz actual sin cambios.
- Revisor local explícito con query válida: solo el scope solicitado cambia.
- Usuario público con query válida: interfaz pública sin cambios.
- ADMIN con scope válido: solo la ruta y el ajuste autorizados cambian.
- Flujos, formularios, drag and drop, generación y acciones destructivas conservan comportamiento.
- Comparación visual desktop y mobile contra la referencia más cercana.

## Rollback

1. Poner `UI_VISUAL_CONSISTENCY_PREVIEW=false` para desactivar todo de inmediato.
2. Retirar un scope del registro para desactivar un ajuste individual.
3. Eliminar el CSS y los branches condicionales del scope cuando la prueba termine.
4. No dejar reglas preview inertes dentro de `style.css`.
5. No migrar datos ni requerir tareas de limpieza.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Fuga a usuarios no autorizados | Comprobación server-side y allowlist limitada a `APP_ENV=local` antes de añadir clase o CSS |
| Cambio productivo con preview OFF | Pruebas de salida y comparación visual sin flag/query |
| CSS que escapa de scope | Prefijo raíz obligatorio en cada selector |
| Interferencia entre ajustes | Un atributo/scope por corrección y registro cerrado |
| Divergencia de markup | Branch mínimo; no duplicar página ni formulario |
| Persistencia accidental | Query parameter o sesión; nunca base de datos |
| Rollback incompleto | Kill switch de entorno y CSS separado |

## Decisión recomendada

La fase actual implementa la infraestructura y dos scopes independientes: `artworks-kpi` y `series-catalog`. No corrige simultáneamente todas las pantallas. Después de la aprobación visual de Series puede incorporarse `primary-actions` como siguiente scope.

# Reporte Técnico de Handoff: Diagnóstico Completo y Despliegue de Vertex AI

Este documento detalla todas las modificaciones del sistema, diagnósticos y configuraciones pendientes para facilitar la transición a la siguiente fase de desarrollo con Codex o Claude Code.

---

## 1. Cambios Realizados en el Código y Scripts
Se realizaron los siguientes cambios para adaptar la infraestructura local y los contenedores de Cloud Run a la nueva arquitectura:

### A. Configuración General (`config.php`)
* **Ubicación**: [config.php](file:///c:/laragon/www/mockups/config.php)
* **Modificación**: Se actualizaron los valores por defecto de la API de Vertex para apuntar a la cuenta de AI del cliente en la región correcta:
  * `VERTEX_PROJECT_ID` = `'project-ff549db7-4f7f-4b0c-9a5'`
  * `VERTEX_LOCATION` = `'global'`

### B. Puente de Python (`app/Services/vertex_bridge.py`)
* **Ubicación**: [vertex_bridge.py](file:///c:/laragon/www/mockups/app/Services/vertex_bridge.py)
* **Modificación**: Se cambiaron los valores por defecto de inicialización del SDK de Google GenAI (`genai.Client`) para usar el proyecto operativo único (`project-ff549db7-4f7f-4b0c-9a5`) y la ubicación `global` como fallbacks.

### C. Scripts de Despliegue de PowerShell
Se alinearon los scripts de PowerShell con los nuevos parámetros por defecto para evitar configuraciones erróneas durante el despliegue automático:
* **[set_cloudrun_runtime_env.ps1](file:///c:/laragon/www/mockups/scripts/set_cloudrun_runtime_env.ps1)**: Línea 3-4, cambiados `VertexProjectId` y `VertexLocation` por defecto.
* **[create_cloudsql_and_configure_cloudrun.ps1](file:///c:/laragon/www/mockups/scripts/create_cloudsql_and_configure_cloudrun.ps1)**: Línea 5-6, cambiados `VertexProjectId` y `VertexLocation` por defecto.

### D. Docker y Dependencias en Servidores
* **Librerías Python**: Se añadieron `google-genai` y `pillow` (PIL) al entorno del worker.
* **PHP GD Extension**: Se compiló la extensión `gd` de PHP en el contenedor web/worker para habilitar funciones de redimensionado/recorte gráfico (`imagecreatefrompng` y similares).
* **RAM de los Contenedores**: Se aumentó la memoria en Cloud Run a **2 GB** (antes 512 MB) para mitigar caídas por Out Of Memory (OOM) al levantar imágenes grandes en memoria.

---

## 2. Diagnóstico del Modo Local (Comportamiento "Mock")
### El Problema:
Al cambiar el administrador local para usar la API Real, el sitio local seguía comportándose en modo simulación (generaba instantáneamente imágenes vacías).

### La Causa (Archivos Sidecar `.meta.json`):
El sistema congela el estado de la API en el archivo de metadatos de la obra cuando se sube o analiza:
* Ubicación: `results/[nombre_obra].meta.json`
* Si la obra fue analizada en modo simulado, el archivo contiene:
  ```json
  "provider_settings": {
      "app_mode": "mock",
      "allow_real_api": "0"
  }
  ```
* Al generar mockups, `generate_mockup_combination.php` ejecuta `ProviderSettings::set(ProviderSettings::readForRoot($rootPath))`, cargando estos valores y sobreescribiendo la configuración global del administrador.

### Solución en Local:
* Volver a analizar la obra (Re-analizar) o editar el campo `"provider_settings"` directamente en el archivo `.meta.json` correspondiente dentro de la carpeta `results/`.

---

## 3. Diagnóstico del Bloqueo en la Nube (GCP Vertex AI)
Al activar la generación en la nube con saldo en la cuenta, se obtienen errores 403/404 de Vertex AI.

### Pruebas de Diagnóstico Ejecutadas:
1. **[test_vertex_models.py](file:///c:/laragon/www/mockups/scratch/test_vertex_models.py)**: Listó exitosamente los modelos en la cuenta, confirmando la existencia de `publishers/google/models/gemini-3.1-flash-image` en la región `us-central1`.
2. **[test_generate.py](file:///c:/laragon/www/mockups/scratch/test_generate.py)**: Ejecutó una petición de prueba local usando la sesión `paulcotyeditor@gmail.com` y falló de inmediato con:
   `ClientError: 403 PERMISSION_DENIED. Permission 'aiplatform.endpoints.predict' denied on resource`
   *(Incluso falló para el modelo gemini-1.5-flash general).*

### Explicación del Error 404 / 403:
En la API de Vertex AI, si la cuenta autenticada no tiene el permiso `aiplatform.endpoints.predict` en el proyecto destino, Google Cloud responde con un código de error **`404 NOT_FOUND`** (con la razón interna `IAM_PERMISSION_DENIED`) por seguridad y privacidad del recurso.

Esto significa que:
* Las llamadas a la IA están bien estructuradas en el código y apuntan al destino correcto.
* La cuenta que hace la llamada (ya sea la sesión de `paulcotyeditor@gmail.com` o las cuentas de servicio de los servidores `mockups-worker-sa` y `mockups-web-sa`) debe estar autorizada para predecir/generar en el proyecto operativo único (`project-ff549db7-4f7f-4b0c-9a5`).

---

## 4. Pasos Pendientes para Codex / Claude Code
Para desbloquear la generación real en la nube, el siguiente desarrollador debe realizar lo siguiente:

1. **Confirmar la cuenta administradora de la IA**:
   * Asegurar que el usuario ingrese a Google Cloud Console con la cuenta administradora `mauriziovalch@gmail.com` (o la cuenta dueña de la organización `mauriziovalch-org`).
2. **Validar Estado de la API de Vertex**:
   * En el proyecto `project-ff549db7-4f7f-4b0c-9a5`, validar que la API `aiplatform.googleapis.com` esté habilitada en [Vertex AI API Console](https://console.cloud.google.com/apis/library/aiplatform.googleapis.com?project=project-ff549db7-4f7f-4b0c-9a5).
3. **Verificar Vinculación de Cuentas de Servicio (IAM)**:
   * Ir a la pestaña de IAM del proyecto operativo (`project-ff549db7-4f7f-4b0c-9a5`) y verificar que se haya otorgado con éxito el rol de **`Editor`** o **`Vertex AI User`** a los siguientes miembros:
     * `mockups-worker-sa@project-ff549db7-4f7f-4b0c-9a5.iam.gserviceaccount.com`
     * `mockups-web-sa@project-ff549db7-4f7f-4b0c-9a5.iam.gserviceaccount.com`
4. **Probar la Conexión**:
   * Una vez propagados los permisos de IAM en la consola web, ejecutar una nueva generación en la nube y comprobar los logs en `check_jobs.php`.

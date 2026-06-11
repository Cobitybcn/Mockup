# Guía Técnica de Replicación para Codex (o Desarrolladores)

Esta guía técnica describe de forma exacta la arquitectura, lógica, algoritmos y configuraciones necesarias para reconstruir este sistema de mockups inteligentes desde cero o migrarlo a otra plataforma usando asistentes de código (Codex, GPT, Cursor, etc.).

---

## 🏗️ 1. Arquitectura del Sistema

El sistema consta de una interfaz web en **PHP**, una base de datos local en **SQLite**, y un puente de backend en **Python** para conectar con las APIs de Vertex AI (Google Cloud).

### Estructura de Carpetas Crítica:
```text
/www/mockups/
  ├── .gitignore             # Evita subir archivos pesados e imágenes generadas.
  ├── .user.ini              # Ajustes de límites de PHP (25M subida).
  ├── app/
  │    ├── bootstrap.php     # Inicialización del sistema y conexión SQLite.
  │    ├── Services/
  │    │    ├── vertex_bridge.py        # Puente Python con SDK de Google Cloud.
  │    │    ├── GeminiImageClient.php   # Ejecutor de llamadas CLI a Python.
  │    │    ├── MockPromptBuilder.php   # Constructor dinámico de prompts.
  │    │    └── MockContextSelector.php # Selector balanceado de propuestas.
  │    └── Support/
  │         └── PromptSettings.php      # Modelo de directivas globales en DB.
  ├── start_generate.php     # Recibe Formulario 1 y arranca proceso CLI.
  ├── process_generate.php   # Script CLI que corre el flujo en segundo plano.
  └── generate_mockup.php    # Recibe Formulario 2 y corre el mockup con Python.
```

---

## ⚡ 2. Ejecución Desacoplada en Windows (Evitar Bloqueos)

**Problema:** Si Apache (módulo FastCGI en Windows) ejecuta scripts de Python que tardan más de 30-60 segundos usando `exec()` o `shell_exec()`, el servidor web se congela o cancela la petición.
**Solución:** Desacoplar el proceso en segundo plano usando `WMIC` (Windows Management Instrumentation) en PHP:

```php
// start_generate.php - Desacoplar proceso CLI
$innerCmd = sprintf(
    'cmd.exe /c %s %s %s > %s 2> %s',
    $phpPath,
    __DIR__ . '/process_generate.php',
    $jobId,
    $jobDir . '/process_out.log',
    $jobDir . '/process_err.log'
);

$cmd = sprintf(
    'wmic process call create "%s"',
    $innerCmd
);

pclose(popen($cmd, "r"));
```

---

## 🐍 3. El Puente de Python (`vertex_bridge.py`)

El puente traduce las solicitudes de PHP al SDK oficial `google-genai`. Tiene dos subcomandos:
1. `generate-text`: Análisis curatorial (Gemini).
2. `generate-image`: Edición/Generación (Imagen 3 / Gemini Image).

### Ajustes de Preprocesamiento de Imágenes (APIs Robustas)
Para evitar que imágenes de alta resolución (como 3000px) o en formato PNG con transparencias (`RGBA`) lancen un error `400 INVALID_ARGUMENT` en la API de Vertex AI:
```python
# Carga de imagen con Pillow
img = Image.open(img_path)

# 1. Redimensionamiento automático a máximo 1024px
max_dim = 1024
w, h = img.size
if w > max_dim or h > max_dim:
    ratio = min(max_dim / w, max_dim / h)
    img = img.resize((int(w * ratio), int(h * ratio)), Image.Resampling.LANCZOS)

# 2. Conversión de espacio de color de RGBA/transparente a RGB limpio
if img.mode != "RGB":
    img = img.convert("RGB")
```

### Control de Cuotas y Reintentos (429 RESOURCE_EXHAUSTED)
Cuando PHP lanza 10 procesos en paralelo (Formulario 2), Vertex AI bloquea peticiones concurrentes por cuota. Debes envolver las llamadas con **Backoff Exponencial y Jitter (ruido aleatorio)**:
```python
def call_with_retry(client_call_fn, max_retries=5):
    for attempt in range(max_retries):
        try:
            return client_call_fn()
        except ClientError as e:
            if ("429" in str(e) or "exhausted" in str(e).lower()) and attempt < max_retries - 1:
                # Retraso exponencial con jitter (ej. 5s, 10s, 20s...)
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                time.sleep(sleep_time)
                continue
            raise e
```

---

## 📐 4. Algoritmo de Escala, Perspectiva y Foco Humano

El corazón de la visualización proporcional se divide en tres partes:

### A. Cálculo del Canvas y Lado Físico (Fill Ratio)
Para evitar que cuadros pequeños parezcan murales gigantes y viceversa, se calcula la proporción visual (`fill_ratio`) que la pintura ocupará en la plantilla de la pared (`1024x1024` px):
```python
# vertex_bridge.py
# 1. Extraer dimensiones en cm del prompt mediante regex
match = re.search(r"(\d+(?:\.\d+)?)\s*cm\s+wide\s*x\s*(\d+(?:\.\d+)?)\s*cm\s+high", args.prompt)

# 2. Asignar fill_ratio según el lado más largo físico
fill_ratio = 0.35 # Fallback estándar
if match:
    width_cm = float(match.group(1))
    height_cm = float(match.group(2))
    long_side_cm = max(width_cm, height_cm)
    
    if long_side_cm <= 45:    fill_ratio = 0.18 # Cuadros pequeños
    elif long_side_cm <= 80:  fill_ratio = 0.25 # Cuadros medianos-chicos
    elif long_side_cm <= 120: fill_ratio = 0.32 # Cuadros medianos
    elif long_side_cm <= 160: fill_ratio = 0.38 # Cuadros grandes
    elif long_side_cm <= 220: fill_ratio = 0.48 # Cuadros muy grandes
    else:                     fill_ratio = 0.58 # Cuadros monumentales
```

### B. Coeficiente Humano (Evitar Perspectiva Forzada)
Cuando hay personas en la escena, la IA tiende a hacerlas ver pequeñas en el fondo para que la pintura se vea gigante.
* Si el prompt contiene figuras humanas (`discreet standing`, `standing adult`, etc.):
```python
# Reducción de escala física del cuadro al 50% (multiplicador de 0.50)
if has_human:
    fill_ratio *= 0.50
```
* **Efecto:** La pintura es más pequeña en el canvas, forzando a la IA a renderizar a la persona más grande y en primer plano.

### C. Compresión de Largo en Perspectiva 3/4
Para vistas anguladas (cámaras de 3/4), el lienzo debe acortarse visualmente en sentido horizontal (fuga de perspectiva) para no verse "estirado" en la pantalla:
```python
# Aplicar una compresión extra del 30% (fuga a 70% en lugar del 90%)
if warp_dir == "left":
    pa = [
        (0, 0), (0, h),
        (int(w * 0.70), int(h * 0.85)), # Acortado a 70% de ancho
        (int(w * 0.70), int(h * 0.15))
    ]
    target_size = (int(w * 0.70), h)
else: # right
    pa = [
        (int(w * 0.30), int(h * 0.15)), # Fuga inicial en 30%
        (int(w * 0.30), int(h * 0.85)),
        (w, h), (w, 0)
    ]
    target_size = (w, h)
```

---

## 📝 5. Directivas de Prompting Clave (Para Mockup Generator)

### Restricciones Arquitectónicas Inyectadas:
* **Altura de Techo:** `"The room or gallery in this scene must have a realistic ceiling height of approximately 3.0 meters (10 feet)... preventing giant cavernous spaces."`
* **Plano Humano:** `"The human figure must stand immediately next to the artwork (within 1 meter) on the exact same depth and floor plane... not in the background/foreground."`
* **Comparativas Físicas:** `"Para una obra de 160 x 120 cm al lado de una persona (ej. mujer de 1.55 m), la altura de la obra (120 cm) debe ser visiblemente menor que la altura de la persona, llegando aproximadamente a sus hombros o barbilla."`
* **Integración Visual:** `"Render realistic shadows, wall contact, canvas depth, and soft lighting matching the light sources."`

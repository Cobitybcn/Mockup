# Sistema de Mockups Inteligentes para Artistas

Este sistema permite a artistas visuales cargar fotografías de sus obras y generar de manera automática y fiel maquetas (mockups) comerciales en entornos de lujo (galerías de arte modernas, hogares de coleccionistas, lofts brutallistas, oficinas de alta dirección y estudios privados).

---

## 🎯 Objetivos del Proyecto

1. **Fidelidad Absoluta (Preservación de la Obra):** Evitar que la IA redibuje, reinterprete o cambie la composición, trazos, texturas y pinceladas del artista. La obra debe integrarse de forma fotorrealista manteniendo su identidad intacta.
2. **Atmosfera de Coleccionista (Curaduría Visual):** Crear escenas emotivas y sofisticadas basadas en el estilo, paleta de colores y clima de la pintura, alejándose de los fotomontajes genéricos y planos.
3. **Calibración Matemática de Escalas:** Garantizar que la obra se visualice con su proporción física y escala reales en relación con los muebles, la arquitectura y las figuras humanas de referencia.

---

## 🔄 Flujo de Trabajo del Sistema

El sistema opera mediante una arquitectura de dos pasos:

### Paso 1: Generación de la Obra Raíz (Formulario 1)
* El usuario sube la foto de su obra de arte y define sus **medidas físicas reales** (ancho, alto y profundidad).
* El sistema ejecuta un proceso en segundo plano que aísla la pintura del fondo (eliminando marcos de fotos, paredes de fondo, mesas u objetos no deseados).
* Utilizando **Gemini Multimodal (gemini-3.1-flash-image)**, se genera una **Obra Raíz limpia, frontal y con iluminación de estudio corregida (HDR)**. Esta imagen se guarda como la verdad definitiva de la obra y se le asocia un archivo de metadatos `.meta.json`.

### Paso 2: Análisis Curatorial y Generación de Mockups (Formulario 2)
* La obra raíz es analizada por la IA para extraer metadatos artísticos (paleta de colores, temperatura emocional, tags de estilo, y audiencia comercial idónea).
* Basándose en este análisis, el sistema sugiere **10 direcciones curatoriales diferentes** combinando variaciones de luz, hora del día, cámaras (frontal, primer plano, perspectiva 3/4) y presencia de figuras humanas de escala.
* Al seleccionar y enviar los entornos, el sistema monta la obra raíz sobre un lienzo inteligente, aplica transformaciones geométricas y delega en el motor de **Vertex AI (Imagen 3)** la inyección y armonización visual del entorno y sombras reales.

---

## 📐 Motor de Proporciones, Perspectiva y Escalas

Uno de los mayores retos de la generación de imágenes por IA es el control del tamaño relativo. Hemos desarrollado un motor de calibración en [vertex_bridge.py](app/Services/vertex_bridge.py) y [MockPromptBuilder.php](app/Services/MockPromptBuilder.php) que resuelve este problema:

### 1. Relación de Aspecto (Aspect Ratio)
El sistema extrae las medidas físicas (ej. $160 \times 120$ cm) para calcular la relación de aspecto matemática exacta ($1.33$ o 4:3). La imagen de la obra raíz se redimensiona en base a esta proporción con múltiplos de 8 píxeles para evitar cualquier tipo de estiramiento o pixelado en la API de Vertex AI.

### 2. Escala Dinámica del Lienzo (Fill Ratio)
La obra raíz se posiciona sobre un lienzo cuadrado base de `1024x1024` píxeles representando la pared. El porcentaje de ancho que ocupa la obra en el lienzo (`fill_ratio`) se ajusta dinámicamente según el lado físico más largo:
* **Obras Íntimas ($\le 45$ cm):** Ocupan el **$18\%$** del lienzo (se muestran pequeñas, a menudo en soportes de mesa).
* **Obras Medianas-Pequeñas ($\le 80$ cm):** Ocupan el **$25\%$** del lienzo.
* **Obras Medianas ($\le 120$ cm):** Ocupan el **$32\%$** del lienzo.
* **Obras Grandes ($\le 160$ cm):** Ocupan el **$38\%$** del lienzo.
* **Obras Muy Grandes ($\le 220$ cm):** Ocupan el **$48\%$** del lienzo.
* **Obras Monumentales ($> 220$ cm):** Ocupan el **$58\%$** del lienzo (comandando la pared de piso a techo).

### 3. Coeficiente de Escala Humana (Reducción del 50%)
Cuando el prompt requiere una figura humana (un hombre de 1.80 m o una mujer de 1.55 m como referencia), la IA tiende a generar a la persona pequeña y lejana en el fondo (perspectiva forzada) haciendo que la pintura parezca gigantesca.
* **Solución:** Si se detecta una figura humana, aplicamos automáticamente un **multiplicador de `0.50`** (reducción del **$50\%$**) sobre el `fill_ratio`. 
* Esto encoge la pintura a la mitad en la plantilla de la pared, forzando a la IA a renderizar una escala humana mucho más grande y cercana, logrando que la persona sea visiblemente más alta que la pintura y respetando la relación de aspecto física.

### 4. Compresión de Perspectiva 3/4 (Reducción del 30% en el "Largo")
Al renderizar vistas en perspectiva angular (3/4 izquierda o derecha), el lienzo plano de la pintura tendía a verse estirado horizontalmente en 2D (pareciendo medir 3 metros de largo en lugar de 1.6 metros).
* **Solución:** Incrementamos el nivel de fuga de la transformación de perspectiva en Python, encogiéndola a un **$70\%$** de proyección horizontal (un **$30\%$** de compresión adicional respecto al $100\%$ original). El cerebro lo interpreta como una inclinación de pared más realista y natural.

### 5. Restricción de Altura del Techo
Para evitar que la IA dibuje salas cavernosas de 6 metros de altura donde los humanos se ven diminutos, los prompts inyectan de forma estricta un límite de **techo de 3.0 metros (10 pies)**, calibrando la altura general de la escena.

### 6. Proximidad del Mismo Plano
El prompt exige que la figura humana se posicione **a menos de 1 metro de distancia lateral de la obra** y sobre el **mismo plano de profundidad y suelo**, inhabilitando la generación de personas en el fondo de la habitación.

---

## 🛠️ Arquitectura Técnica

* **Backend:** PHP 8.3 (Laragon local). Maneja la sesión del usuario, la base de datos de auditoría, las vistas interactivas y despacha las tareas pesadas en segundo plano mediante comandos de sistema desacoplados (`wmic` en Windows para prevenir bloqueos de Apache FastCGI).
* **Base de Datos:** SQLite (`app.sqlite`) para el almacenamiento de perfiles de artistas, obras y mockups generados.
* **Puente Python (`vertex_bridge.py`):** Un script CLI en Python 3.13 que interactúa directamente con el SDK de Google GenAI (Vertex AI).
  * Realiza las llamadas con **reintentos automáticos (Backoff Exponencial + Jitter)** para evitar errores `429 RESOURCE_EXHAUSTED` por tasa de solicitudes concurrentes.
  * Realiza **conversión de espacio de color** (RGBA a RGB) y **reducción de resolución** (máximo 1024 px de ancho) para evitar errores `400 INVALID_ARGUMENT` en imágenes PNG grandes.
  * Ejecuta las transformaciones de perspectiva y creación de máscaras de inpainting de forma nativa mediante la librería Pillow.

---

## ⚙️ Parámetros y Límites del Sistema

* **Límite de subida web:** Configurado en `25 MB` mediante `.user.ini` (`upload_max_filesize` y `post_max_size`).
* **Límite de ejecución de scripts:** 900 segundos para la creación de obra raíz (Formulario 1) y 120 segundos para mockups.
* **Límite de memoria PHP:** 768 MB en subida, 512 MB en procesamiento.
* **Límite de la API de Imagen:** Redimensionamiento automático a 1024 px máximo en Vertex AI Gemini Image.

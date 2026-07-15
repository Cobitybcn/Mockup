# Artwork Assistant en Faithful

## Alcance inicial

El asistente aparece desde `sidebar.php` en las pantallas autenticadas de Artwork Mockups Faithful. La primera versión es deliberadamente de lectura:

- consulta únicamente datos que pertenecen a la cuenta que inició sesión;
- explica el contexto activo de obras, series, mockups, generaciones y publicaciones;
- conserva conversaciones, mensajes, resúmenes, decisiones, notas, acciones internas, tareas pendientes y consumo;
- puede preparar una tarea técnica para Codex, pero no edita código ni datos de la plataforma;
- no ejecuta generaciones, publicaciones ni cambios de prompts.

Las cuentas `chiappero@gmail.com` y `mauriziovalch@gmail.com` pueden pertenecer a una misma identidad del asistente. Esto comparte historial y memoria, pero no combina usuarios, permisos ni roles de la aplicación.

## Persistencia y Cloud Run

Cloud Run se considera completamente stateless. El asistente no escribe memoria en archivos del contenedor ni depende del disco local. Después de un reinicio, escalado o deploy recupera su estado de la base persistente mediante estas tablas:

- `assistant_identities`
- `assistant_identity_members`
- `assistant_conversations`
- `assistant_messages`
- `assistant_conversation_entities`
- `assistant_memories`
- `assistant_actions`
- `assistant_usage_events`
- `assistant_technical_tasks`

`AssistantSchema` crea las tablas de forma idempotente al inicializar la conexión existente. Las tablas funcionales de obras, usuarios y roles no se alteran.

## Privacidad y seguridad

- La clave de OpenAI solo se usa en el backend y nunca llega al navegador.
- En Cloud Run debe inyectarse como `OPENAI_API_KEY` desde Secret Manager.
- Cada petición requiere sesión autenticada y token CSRF.
- El backend reconstruye y valida el contexto; no confía en IDs enviados por el navegador.
- Todas las consultas de negocio se limitan mediante el `user_id` de la cuenta activa.
- La Responses API se llama con `store: false`; la memoria durable vive exclusivamente en MySQL.
- Hay límites por minuto, límite diario y tamaño máximo de mensaje.
- El editor permite adjuntar PNG, JPG o WEBP y pegar una captura directamente con `Ctrl+V`; la imagen se redimensiona y comprime en el navegador antes de enviarse.
- Los errores públicos no exponen respuestas del proveedor, claves ni detalles internos.

## Configuración

Variables no secretas:

```dotenv
OPENAI_ASSISTANT_MODEL=gpt-5.6-terra
OPENAI_API_BASE=https://api.openai.com/v1
ASSISTANT_ENABLED=true
ASSISTANT_ADMIN_ENABLED=true
ASSISTANT_APP_ENABLED=true
ASSISTANT_PROVIDER=openai
# Para Gemini, se pueden omitir estos valores y reutilizar VERTEX_PROJECT_ID / VERTEX_LOCATION.
ASSISTANT_VERTEX_PROJECT_ID=
ASSISTANT_VERTEX_LOCATION=
ASSISTANT_GOOGLE_APPLICATION_CREDENTIALS=
ASSISTANT_MAX_OUTPUT_TOKENS=1200
ASSISTANT_HISTORY_MESSAGES=12
ASSISTANT_RATE_LIMIT_PER_MINUTE=12
ASSISTANT_DAILY_MESSAGE_LIMIT=250
```

En local la clave puede provenir del entorno del sistema. En Cloud Run debe configurarse con Secret Manager. No debe guardarse en `.env.example`, Git ni imágenes Docker.

## Operaciones y pruebas

Vincular cuentas existentes sin cambiar roles:

```powershell
php scripts/link_assistant_identity.php "chiappero@gmail.com,mauriziovalch@gmail.com" "Pablo"
```

Prueba de persistencia sin usar OpenAI:

```powershell
php tests/run_assistant_tests.php
```

Prueba real del proveedor configurado con rollback de escrituras de prueba:

```powershell
php scripts/assistant_smoke.php "chiappero@gmail.com"
```

Prueba HTTP autenticada local con sesión temporal:

```powershell
php scripts/assistant_http_smoke.php "chiappero@gmail.com"
```

## Despliegue seguro

No desplegar Faithful sobre `artwork-platform-next-staging` ni sobre `mockups-web` durante la validación inicial. El staging debe usar:

- servicio Cloud Run nuevo: `artworkmockups-faithful-staging`;
- base MySQL aislada, nunca la base de producción `mockups`;
- credencial de base en Secret Manager;
- generación y publicaciones reales desactivadas;
- una copia controlada de datos sin secretos cuando sea necesario probar continuidad.

`deploy_faithful_staging.ps1` contiene guardas que rechazan nombres de producción y exige que la base y los secretos ya existan. El script no crea ni clona la base automáticamente.

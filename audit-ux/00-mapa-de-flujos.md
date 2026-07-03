# Mapa de Flujos e Arquitectura de Información

Este documento describe la arquitectura de información actual de **The Artwork Curator**, detallando los flujos de navegación, cómo se interconectan las pantallas y los puntos de entrada y salida para el usuario.

## Diagrama de Arquitectura y Navegación (Mermaid)

El siguiente diagrama de flujo ilustra los caminos que recorre un artista dentro de la plataforma, desde el inicio de sesión hasta la obtención de entregables (mockups y videos):

```mermaid
graph TD
    %% Estilos de Nodos
    classDef entry fill:#F6F3EE,stroke:#9A7B56,stroke-width:2px;
    classDef process fill:#FFF,stroke:#E5E3DD,stroke-width:1px;
    classDef sidebar fill:#FAF9F6,stroke:#9A7B56,stroke-dasharray: 5 5;
    classDef admin fill:#FFF5F5,stroke:#A63C3C,stroke-width:1px;

    %% Entrada y Login
    Start([Inicio / Acceso]) --> Login[login.php]
    Start --> AutoLogin[autologin.php]
    Login --> |Autenticación Excitosa| Dashboard[dashboard.php]
    AutoLogin --> |Redirección Rápida| ArtworkSheet[artwork.php]

    %% Dashboard y Navegación Principal
    Dashboard --> |Paso 1: Nueva Obra| Upload[artwork_new.php]
    Dashboard --> |Seleccionar Obra Existente| ArtworkSheet
    
    %% Flujo 1: Onboarding y Carga de Obra
    Upload --> |Opción A: Generación AI (Imagen 3)| Waiting[waiting.php]
    Waiting --> |Fin de Procesamiento| RootSelect[root_select.php]
    RootSelect --> |Confirmar Candidata Frontal| MockupReview[mockup_combinations_review.php]
    
    Upload --> |Opción B: Carga Directa (Bypass)| MockupReview

    %% Flujo 2 & 4: Detalles de Obra y Metadata
    ArtworkSheet --> |Ver Core JSON Técnico| CoreReview[core_review.php]
    ArtworkSheet --> |Editar Título / Ficha Técnica| SaveSheet[artwork.php?action=save_sheet]
    ArtworkSheet --> |Paso 2: Generar Mockups| MockupReview
    ArtworkSheet --> |Generar Video Simple| VideoSimple[social_video.php / social_video_simple.php]
    ArtworkSheet --> |Generar Video Timeline (Beta)| VideoTimeline[social_video_timeline.php]

    %% Flujo 3: Mockup Lab (Scene + Cameras)
    MockupReview --> |Generar Combinaciones AI| MockupGen[Proceso en Cola Vertex]
    MockupReview --> |Filtrar Categorías / Cámaras| MockupReview
    MockupReview --> |Descargar Mockup| DownloadMockup[media.php]

    %% Configuración de Cuenta y Perfil de Artista
    Dashboard --> ArtistProfile[artist_profile.php]
    Dashboard --> Account[account.php]

    %% Vistas de Administración (Admin Only)
    Dashboard -.-> |Rol Admin| AdminUsers[admin_users.php]
    Dashboard -.-> |Rol Admin| AdminPrompts[admin_prompts.php]
    Dashboard -.-> |Rol Admin| AdminApi[admin_api_keys.php]

    %% Salida
    Logout[logout.php] --> Start

    %% Asignación de Clases
    class Start,Login,AutoLogin entry;
    class Dashboard,Upload,Waiting,RootSelect,MockupReview,ArtworkSheet,CoreReview,VideoSimple,VideoTimeline process;
    class ArtistProfile,Account,Logout sidebar;
    class AdminUsers,AdminPrompts,AdminApi admin;
```

---

## Análisis de los Puntos de Entrada y Salida

### 1. Puntos de Entrada (Access Points)
*   **Inicio de Sesión Convencional (`login.php`)**: Panel estético con preview de galería lateral. Solicita email y contraseña.
*   **Bypass de Acceso (`autologin.php`)**: Utilizado principalmente en entornos locales o de desarrollo/pruebas. Carga la sesión del primer usuario de la base de datos o de una obra hardcodeada (ID 110) y redirige a la ficha de obra (`artwork.php?id=110`). Si el ID 110 no existe, produce un estado huérfano.

### 2. Navegación Principal y Sidebar (Menu Persistent)
La barra lateral (`sidebar.php`) es el eje de la navegación y expone dos secciones según el contexto:
*   **BETA FLOW (Pasos estructurados)**:
    *   **Paso 1: Upload (Add the artwork)** -> Enlace directo a `artwork_new.php`.
    *   **Paso 2: Scene + Cameras (Pick scene, run views)** -> Enlace dinámico (`mockup_combinations_review.php` o `curated_mockups.php`) habilitado solo cuando el usuario tiene al menos una obra procesada.
*   **QUICK / MORE (Menú General)**:
    *   **Dashboard**: Historial general.
    *   **Mockups**: Galería de mockups generados.
    *   **Artwork Core**: Entrada directa a `core_review.php`.
    *   **Scene Library**: Visor de escenas interiores (`world_mother_studio.php`).
    *   **Artist Profile**: Configuración curatorial del artista.
    *   **Account / Logout**: Datos de la cuenta y cierre de sesión.

### 3. Puntos de Salida (Output / Delivery Points)
*   **Descarga de Imágenes**:
    *   **Obra Frontal aislada (Root)**: A través de `artwork.php` -> Enlace a `media.php?file=...&download=1`.
    *   **Mockups de ambientación**: A través de las tarjetas de mockup generados en `artwork.php` o en `mockup_combinations_review.php` -> Enlace a `media.php?file=...&download=1`.
*   **Descarga de Redes Sociales (Video)**:
    *   Generado mediante FFmpeg (`social_video_simple.php`) -> Guardado y descargable a través de `media.php?file=results/social-video/...`.

# Mudrais

Este proyecto es el backend de Historia Pipeline, migrado a una arquitectura DDD con Laravel, Filament para administración y Alpine.js para interactividad. Utiliza contenedores de Docker mediante Laravel Sail para facilitar el desarrollo local, garantizando un entorno idéntico y eliminando problemas de drivers en distintas máquinas.

## Requisitos Previos

Para levantar este proyecto necesitas tener instalado:
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) o Docker Engine con Docker Compose.
- PHP y Composer (solo para la instalación inicial de dependencias).
- Node.js y NPM (opcional, ya que también se pueden ejecutar mediante Sail).

## Configuración y Variables de Entorno

1. Clona el proyecto y ve a la carpeta de la aplicación:
   ```bash
   cd laravel_app
   ```

2. Crea el archivo de configuración copiando el ejemplo:
   ```bash
   cp .env.example .env
   ```

3. Asegúrate de configurar las variables de entorno para **Qdrant** (la base de datos vectorial) en tu `.env`. Los valores recomendados para desarrollo local son:
   ```env
   QDRANT_HOST=localhost
   QDRANT_PORT=6333
   QDRANT_API_KEY=
   QDRANT_COLLECTION_NAME=tu_coleccion
   ```

## Levantar el Servidor (Entorno Principal)

Todo el proyecto se levanta utilizando **Laravel Sail** para evitar problemas de compatibilidad y dependencias de drivers.

1. Instala las dependencias de PHP usando Composer localmente o usando un contenedor temporal si no tienes PHP instalado:
   ```bash
   composer install
   ```
   *(Si no tienes Composer instalado en tu máquina, puedes usar el contenedor de Docker para instalar las dependencias, consulta [la documentación de Sail](https://laravel.com/docs/sail#installing-composer-dependencies-for-existing-projects))*

2. Levanta los servicios de Docker (PostgreSQL, Qdrant, Redis y Ngrok) en segundo plano:
   ```bash
   ./vendor/bin/sail up -d
   ```

3. Genera la clave de la aplicación:
   ```bash
   ./vendor/bin/sail artisan key:generate
   ```

4. Ejecuta las migraciones de la base de datos y los seeders:
   ```bash
   ./vendor/bin/sail artisan migrate --seed
   ```

5. Instala las dependencias de Node.js:
   ```bash
   ./vendor/bin/sail npm install
   ```

## Workers Necesarios para el Funcionamiento

Para que el ecosistema y la pipeline funcionen correctamente de forma asíncrona, debes ejecutar ciertos procesos en terminales separadas (workers). Recuerda usar **siempre** Sail para estos comandos:

### 1. Worker de Colas (Jobs Async)
Para procesar tareas en segundo plano (ej. llamadas a LLMs, procesamiento de texto, etc.):
```bash
./vendor/bin/sail artisan queue:work
```
*También puedes usar `queue:listen` en desarrollo para que recoja los cambios de código automáticamente sin tener que reiniciarlo.*

### 2. Servidor de Frontend (Vite)
Para compilar y servir los assets en tiempo real (TailwindCSS, Alpine.js, scripts de Filament):
```bash
./vendor/bin/sail npm run dev
```

### 3. Tareas Programadas (Scheduler) (Opcional, si hay crons)
Si hay tareas que se ejecutan cada minuto, necesitas levantar el worker del scheduler:
```bash
./vendor/bin/sail artisan schedule:work
```

## Servicios que Incluye Docker (compose.yaml)

Al hacer `sail up -d` se levantan los siguientes servicios:
- **laravel.test**: El servidor web principal con PHP 8.x.
- **pgsql**: Base de datos principal PostgreSQL.
- **qdrant**: Base de datos vectorial (accesible en `http://localhost:6333/dashboard`).
- **redis**: Sistema de caché y colas.
- **ngrok**: Útil si necesitas exponer tu proyecto local temporalmente.

## Documentación

La documentación técnica actualizada vive en [`docs/functional/`](docs/functional/README.md).

| Documento | Propósito |
|-----------|-----------|
| [architecture.md](docs/functional/architecture.md) | Stack, DDD, modelos, bounded contexts, glosario |
| [archetype-setup.md](docs/functional/archetype-setup.md) | Guía completa para crear un archetype desde cero en Filament |
| [prompt-configuration.md](docs/functional/prompt-configuration.md) | Cómo configurar, mantener y depurar prompts de IA por archetype |
| [prompt-flow.md](docs/functional/prompt-flow.md) | Pipelines de IA: orígenes, placeholders, agentes involucrados |
| [discord-commands.md](docs/functional/discord-commands.md) | Referencia de slash commands: payloads, respuestas, jobs |
| [queue-workers.md](docs/functional/queue-workers.md) | Configuración de workers: Docker, VPS + Supervisor, Shared Hosting + Cron |

**Guías de usuario**

| Documento | Idioma |
|-----------|--------|
| [user-guide-es.md](docs/user-guide-es.md) | Español |
| [user-guide-en.md](docs/user-guide-en.md) | English |

> Los documentos en `docs/obsolete/` y `docs/plans/` son referencia histórica y pueden no estar actualizados.

## Licencia

Distribuido bajo la [MIT License](LICENSE).

## Notas Adicionales y Reglas

- **Comandos de Artisan:** Nunca ejecutes `php artisan ...` directamente. Utiliza siempre la envoltura `./vendor/bin/sail artisan ...` para evitar problemas con drivers de bases de datos o extensiones de PHP faltantes.
- **Estado del Proyecto:** Cualquier tarea o progreso se actualiza en el archivo `estado_proyecto.json` por los agentes.
- **Acceso:** Puedes acceder a la aplicación en `http://localhost` y a la base de datos vectorial en `http://localhost:6333/dashboard`.

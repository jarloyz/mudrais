# Queue Workers — Configuración y Deployment

Referencia para levantar los workers de colas en desarrollo (Docker/Sail) y producción (VPS/shared hosting).

---

## Arquitectura de colas

El sistema usa 6 colas dedicadas para evitar que jobs lentos bloqueen a los urgentes:

| Queue | Jobs que procesa | Urgencia | Timeout por job |
|-------|-----------------|----------|-----------------|
| `high` | Búsquedas Discord, status, views | Crítica — Discord espera < 3s | 30s |
| `default` | Modales de registro, ficha, mensajes, `ProcessInterviewTurnJob` (Weaver) | Alta — respuesta de usuario | 60s |
| `voice` | `ProcessVoiceInterviewTurnJob` (Voice) | Alta — voice-bridge espera respuesta | 60s |
| `index` | IndexAvatarJob, IndexVaultJob, IndexPlayerStyleJob, IndexLoreEntryJob, IndexActivityJob | Media — pipeline LLM completo | 180s |
| `tags` | NormalizeSingleTagJob, NormalizeAvatarTagsJob, NormalizePlayerTagsJob | Baja — puede correr en paralelo | 60s |
| `sync` | SyncActivityHubStatusJob, SyncPlayerQdrantGuildsJob | Background — sin urgencia | 60s |

### Microservicio voice-bridge (Node.js)

El servicio `voice-bridge` no es un worker de Laravel — es un contenedor Node.js independiente
que corre en paralelo al stack y se comunica con Laravel vía HTTP REST + Redis.

| Rol | Mecanismo |
|---|---|
| Detectar inicio de sesión | Pollea `GET /api/voice/pending-start` cada 2s (LPOP Redis atómico) |
| Iniciar sesión | `POST /api/voice/session/start` |
| Entregar transcript | `POST /api/voice/transcription` → respuesta streaming de TalkatorAgent |
| Obtener siguiente pregunta | Pollea `GET /api/voice/next-question/{sessionId}` cada 500ms (LPOP Redis) |

En desarrollo corre como servicio Docker en `compose.yaml`. En producción Supervisor gestiona
el contenedor via Docker Compose (ver `supervisord.conf`).

---

## Desarrollo local (Docker / Sail)

Los workers ya están configurados en `compose.yaml` como servicios independientes.
Se levantan automáticamente con el stack completo:

```bash
./vendor/bin/sail up -d
```

Esto levanta: `laravel.test` (app) + `pgsql` + `qdrant` + `redis` + `ngrok` + **5 workers**.

### Ver workers activos

```bash
./vendor/bin/sail ps
```

Verás los contenedores `worker-high`, `worker-default`, `worker-index`, `worker-tags`, `worker-sync`.

### Ver logs de un worker específico

```bash
./vendor/bin/sail logs -f worker-index
./vendor/bin/sail logs -f worker-tags
```

### Escalar workers de tags (más paralelismo)

Si tienes muchos avatars indexándose simultáneamente, puedes levantar más workers de tags:

```bash
docker compose up --scale worker-tags=3 -d
```

Esto crea 3 contenedores procesando la queue `tags` en paralelo.

### Reiniciar un worker después de cambios de código

Los workers cachean las clases en memoria. Después de un deploy o cambio de código:

```bash
# Opción 1: reiniciar el worker específico
docker compose restart worker-index

# Opción 2: reiniciar todos los workers de una vez
docker compose restart worker-high worker-default worker-index worker-tags worker-sync

# Opción 3: queue:restart (graceful — termina el job actual y para)
./vendor/bin/sail artisan queue:restart
```

### Monitorear jobs en tiempo real

```bash
# Ver todos los jobs pendientes por queue
./vendor/bin/sail artisan queue:monitor redis:high,redis:default,redis:index,redis:tags,redis:sync

# Ver jobs fallidos
./vendor/bin/sail artisan queue:failed

# Reintentar un job fallido por ID
./vendor/bin/sail artisan queue:retry {id}

# Limpiar todos los fallidos
./vendor/bin/sail artisan queue:flush
```

---

## Producción en VPS con Supervisor

En un VPS (Ubuntu/Debian) con PHP, Redis y PostgreSQL instalados, usa Supervisor
para mantener los workers corriendo y reiniciarlos automáticamente.

### Instalar Supervisor

```bash
sudo apt-get install -y supervisor
```

### Crear el archivo de configuración

Crear `/etc/supervisor/conf.d/mudrais-workers.conf`:

```ini
[program:mudrais-worker-high]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=high --timeout=30 --tries=3 --sleep=1 --memory=128 --name=worker-high
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-high.log
stopwaitsecs=60
startsecs=1

[program:mudrais-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=default --timeout=60 --tries=3 --sleep=3 --memory=128 --name=worker-default
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-default.log
stopwaitsecs=90
startsecs=1

[program:mudrais-worker-index]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=index --timeout=180 --tries=2 --sleep=3 --memory=256 --name=worker-index
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-index.log
stopwaitsecs=210
startsecs=1

[program:mudrais-worker-tags]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=tags --timeout=60 --tries=3 --sleep=3 --memory=128 --name=worker-tags
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-tags.log
stopwaitsecs=90
startsecs=1

[program:mudrais-worker-voice]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=voice --timeout=60 --tries=2 --sleep=1 --memory=128 --name=worker-voice
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-voice.log
stopwaitsecs=90
startsecs=1

[program:mudrais-worker-sync]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mudrais/artisan queue:work redis --queue=sync --timeout=60 --tries=2 --sleep=10 --memory=128 --name=worker-sync
directory=/var/www/mudrais
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mudrais/storage/logs/worker-sync.log
stopwaitsecs=90
startsecs=1

[group:mudrais-workers]
programs=mudrais-worker-high,mudrais-worker-default,mudrais-worker-voice,mudrais-worker-index,mudrais-worker-tags,mudrais-worker-sync
```

### Activar y gestionar

```bash
# Recargar configuración
sudo supervisorctl reread
sudo supervisorctl update

# Iniciar todos los workers
sudo supervisorctl start mudrais-workers:*

# Ver estado
sudo supervisorctl status mudrais-workers:*

# Reiniciar después de un deploy
sudo supervisorctl restart mudrais-workers:*

# O con queue:restart (graceful — espera a que terminen los jobs activos)
php artisan queue:restart
sudo supervisorctl restart mudrais-workers:*
```

### Deploy workflow con Supervisor

```bash
# 1. Subir código nuevo
git pull origin main

# 2. Dependencias y cache
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# 3. Reiniciar workers gracefully
php artisan queue:restart
# Supervisor los reiniciará automáticamente tras el graceful stop
```

---

## Shared Hosting (cPanel / Plesk)

> ⚠️ **Limitación importante:** Los shared hosts no soportan procesos persistentes en background.
> Los workers deben ejecutarse como cron jobs, lo que introduce una latencia mínima de ~1 minuto
> entre que llega un job y se procesa.
>
> **Consecuencia para la queue `high`:** Los comandos Discord de tipo `/buscar-actividad` usan
> deferred responses (`type:5`) — Discord muestra "pensando…" hasta 15 minutos. Esto es
> compatible con cron ya que la respuesta llega en el próximo ciclo del cron (~60s máximo).
> Sin embargo, si tu host tiene restricciones de tiempo de ejecución por PHP (ej: `max_execution_time=30s`)
> los jobs del pipeline LLM (`index`, `tags`) necesitan un VPS o solución alternativa.

### Configuración de cron en cPanel

Acceder a **cPanel → Cron Jobs** y agregar una entrada por queue:

```
# Cada minuto — queue high (respuestas Discord urgentes)
* * * * * /usr/local/bin/php /home/tuusuario/public_html/artisan queue:work redis --queue=high --stop-when-empty --timeout=25 --tries=3 --memory=64 >> /dev/null 2>&1

# Cada minuto — queue default (modales de registro)
* * * * * /usr/local/bin/php /home/tuusuario/public_html/artisan queue:work redis --queue=default --stop-when-empty --timeout=55 --tries=3 --memory=64 >> /dev/null 2>&1

# Cada 2 minutos — queue index (pipeline LLM, pesado)
*/2 * * * * /usr/local/bin/php /home/tuusuario/public_html/artisan queue:work redis --queue=index --stop-when-empty --timeout=170 --tries=2 --memory=128 >> /dev/null 2>&1

# Cada minuto — queue tags (normalización de tags)
* * * * * /usr/local/bin/php /home/tuusuario/public_html/artisan queue:work redis --queue=tags --stop-when-empty --timeout=55 --tries=3 --memory=64 >> /dev/null 2>&1

# Cada 5 minutos — queue sync (mantenimiento background)
*/5 * * * * /usr/local/bin/php /home/tuusuario/public_html/artisan queue:work redis --queue=sync --stop-when-empty --timeout=55 --tries=2 --memory=64 >> /dev/null 2>&1
```

> **`--stop-when-empty`** es la clave: el proceso procesa todos los jobs disponibles y termina solo.
> Esto evita que el proceso corra indefinidamente (prohibido en shared hosting).

### Verificar la ruta de PHP en cPanel

La ruta `/usr/local/bin/php` puede variar según el host. Para encontrarla:

```bash
which php
# o
php -v
```

Si el host tiene múltiples versiones de PHP, usar la ruta completa con la versión correcta:
```
/usr/local/bin/php8.3
```

### Verificar que Redis está disponible

Algunos shared hosts no ofrecen Redis. Alternativas:

**Opción A — Redis de pago en el host:**
Muchos cPanels tienen un addon de Redis. Activarlo y configurar en `.env`:
```
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**Opción B — Redis externo (Upstash, Redis Cloud):**
Servicios con free tier compatible con Laravel:
```
REDIS_HOST=global-sacred-xxx.upstash.io
REDIS_PORT=6380
REDIS_PASSWORD=tu_password
REDIS_SCHEME=tls
```

**Opción C — Queue driver `database` (sin Redis):**
Si no hay Redis disponible, cambiar el driver a base de datos:
```
QUEUE_CONNECTION=database
```
Y crear la tabla de jobs:
```bash
php artisan queue:table
php artisan migrate
```
Los cron commands son idénticos — solo cambia el driver de fondo.

### Verificar que el cron está funcionando

```bash
# Ver los últimos jobs procesados (en DB si usas driver database)
php artisan tinker
>>> DB::table('jobs')->count();    # Jobs pendientes
>>> DB::table('failed_jobs')->count();  # Jobs fallidos

# Con Redis, ver el tamaño de cada queue
redis-cli llen queues:high
redis-cli llen queues:index
redis-cli llen queues:tags
```

### Script de deploy para shared hosting

Subir vía FTP/SFTP o Git y ejecutar:

```bash
#!/bin/bash
# deploy.sh — ejecutar en el servidor tras subir código nuevo

cd /home/tuusuario/public_html

# Actualizar dependencias
php composer.phar install --no-dev --optimize-autoloader

# Limpiar y regenerar cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migraciones
php artisan migrate --force

# Señal de restart a los workers (se aplicará en el próximo ciclo de cron)
php artisan queue:restart

echo "Deploy completado."
```

---

## Comparativa rápida

| Aspecto | Docker / VPS + Supervisor | Shared Hosting + Cron |
|---------|--------------------------|----------------------|
| Latencia de procesamiento | < 1 segundo (daemon) | Hasta 60 segundos |
| Redis requerido | Sí (incluido en Docker) | Sí (o driver database) |
| Workers paralelos | Sí (`--scale` o `numprocs`) | No (un proceso por cron) |
| Jobs LLM largos (180s) | ✅ Soportado | ⚠️ Depende del `max_execution_time` del host |
| Coste | VPS desde ~$5/mes | Incluido en el hosting |
| Restart tras deploy | `queue:restart` + Supervisor | `queue:restart` (cron lo aplica en el próximo tick) |
| Recomendado para producción | ✅ | Solo para proyectos de bajo volumen |

---

## Parámetros clave de `queue:work`

| Parámetro | Qué hace | Valor recomendado |
|-----------|----------|------------------|
| `--queue=X` | Cola a procesar | Nombre exacto de la queue |
| `--timeout=N` | Segundos máximos por job (mata el proceso si se excede) | Margen de 10-20% sobre el `$timeout` del job |
| `--tries=N` | Reintentos por job antes de moverlo a `failed_jobs` | 2-3 según criticidad |
| `--sleep=N` | Segundos de espera cuando la queue está vacía | 1 (high), 3 (resto), 10 (sync) |
| `--memory=N` | MB máximos antes de reiniciar el worker | 128-256 según el job |
| `--stop-when-empty` | Termina cuando no hay más jobs (cron mode) | Solo en shared hosting |
| `--name=X` | Nombre del worker en logs y `queue:monitor` | Nombre descriptivo |

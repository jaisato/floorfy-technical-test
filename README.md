# Floorfy Technical Test — Backend (Symfony + Docker)

API REST en Symfony 7.4 para crear **tareas** que generan:

1. **vídeos parciales** a partir de imágenes (con transiciones), y
2. un **vídeo final** concatenando los parciales.

La ejecución pesada (descarga de imágenes + FFmpeg) se hace en segundo plano con
**Symfony Messenger + RabbitMQ**.

---

## Índice

- [Stack](#stack)
- [Arranque rápido](#arranque-rápido)
- [Servicios y puertos](#servicios-y-puertos)
- [Cómo funciona](#cómo-funciona)
- [API](#api)
- [Variables de entorno](#variables-de-entorno)
- [Desarrollo sin Docker](#desarrollo-sin-docker)
- [Tests](#tests)
- [Calidad](#calidad)
- [Integración continua](#integración-continua)
- [Operación](#operación)
- [Troubleshooting](#troubleshooting)

---

## Stack

| Pieza | Versión |
|---|---|
| PHP | 8.4 (`>=8.4.3`) |
| Symfony | 7.4 |
| Base de datos | MySQL 8 (Doctrine ORM + migraciones) |
| Cola | RabbitMQ 3.13 (Symfony Messenger, transporte AMQP) |
| Vídeo | FFmpeg (invocado como proceso, sin librería intermedia) |
| Servidor web | nginx + PHP-FPM |

El código de la aplicación está en `app/symfony/`; los ficheros de Docker en `app/`
y `app/docker/`; el `docker-compose.yml` en la raíz.

---

## Arranque rápido

Sólo hacen falta Docker y Docker Compose. Desde la raíz del repositorio:

```bash
make up          # equivale a: docker compose up -d --build
```

Eso construye la imagen (que **incluye el código y sus dependencias**), arranca
MySQL y RabbitMQ, espera a que estén sanos, **aplica las migraciones** y levanta
la API y el worker. No hay ningún paso manual posterior.

Comprobación:

```bash
curl -i -X POST http://localhost:8080/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"images":[{"url":"https://upload.wikimedia.org/wikipedia/commons/3/3f/Fronalpstock_big.jpg","transition":"zoom_in"}]}'
```

Para parar y borrar los volúmenes:

```bash
make down
```

Todas las variables de `docker-compose.yml` tienen un valor por defecto de
desarrollo, así que no hace falta ningún fichero adicional. Para cambiarlas:

```bash
cp .env.example .env    # y edita lo que necesites
```

---

## Servicios y puertos

| Servicio | Puerto en el host | Notas |
|---|---|---|
| API (nginx) | `8080` | `http://localhost:8080` |
| MySQL | `3307` | sólo para inspección; la app se conecta por la red interna |
| RabbitMQ (gestión) | `15672` | `http://localhost:15672` (app / app) |
| RabbitMQ (AMQP) | — | no se publica: sólo lo usan php y worker |

La aplicación **nunca** se conecta a MySQL como `root`: usa el usuario `app`, y la
contraseña de root se genera aleatoriamente en el primer arranque.

---

## Cómo funciona

```
POST /api/tasks
      │  valida el cuerpo, escribe la tarea y sus partes en UNA transacción
      │  y, una vez confirmada, publica un mensaje en RabbitMQ
      ▼
  [ worker ]  messenger:consume async
      │  1. reclama la tarea con un UPDATE condicional (una sola vez)
      │  2. por cada parte: descarga la imagen (con guardia SSRF) y la anima con
      │     FFmpeg hacia un directorio de staging
      │  3. publica cada clip con rename() -> public/videos/partial_<id>.mp4
      │  4. concatena los clips y publica public/videos/final_<task>.mp4
      ▼
GET /api/tasks/{id}          estado de la tarea y de cada parte
GET /api/tasks/{id}/final    URL del vídeo final
```

Puntos que merece la pena conocer:

- **Reintentos.** El transporte reintenta hasta 20 veces con espera exponencial
  (10 s → 10 min). Un intento fallido **devuelve la reserva** de la tarea y
  relanza; el estado `failed` sólo se escribe cuando Messenger ha agotado los
  reintentos. Los mensajes agotados van a la cola `failed`
  (`messenger:failed:show` / `messenger:failed:retry`), no se descartan.
- **Idempotencia.** La tarea se reclama con
  `UPDATE … SET status='processing' WHERE id=? AND (status='pending' …)`, así que
  una reentrega, o un segundo worker, no reprocesan lo ya hecho. Una reserva más
  antigua que `TASK_LEASE_SECONDS` se considera abandonada y otro worker puede
  tomarla (por si el proceso murió a mitad).
- **Fallo parcial.** Si una imagen falla, el resto de partes se generan igual; el
  reintento sólo repite lo que quedó pendiente.
- **Escritura atómica.** Los vídeos se escriben en `public/videos/.staging/` y se
  mueven con `rename()`, así que un cliente nunca recibe un fichero a medias
  (nginx además deniega cualquier ruta con punto inicial).
- **SSRF.** Las URLs de imagen vienen de fuera, así que se resuelven y validan
  contra el espacio de direcciones público (IPv4 e IPv6), sólo por los puertos 80
  y 443, cada redirección se revalida y la conexión se fija a la dirección ya
  comprobada (anti *DNS rebinding*). Se comprueba además el `Content-Type`, el
  `Content-Length` y el contenido real del fichero descargado.

---

## API

### `POST /api/tasks`

```json
{
  "images": [
    { "url": "https://example.com/a.jpg", "transition": "zoom_in" },
    { "url": "https://example.com/b.jpg", "transition": "pan" }
  ]
}
```

- `transition`: `pan`, `zoom_in` o `zoom_out`.
- Máximo 20 imágenes por tarea; `url` hasta 2048 caracteres.
- **201** `{"task_id": "...", "status": "pending"}`
- **400** `{"error": "Validation failed", "context": {"violations": {...}}}`

### `GET /api/tasks/{id}`

```json
{
  "task_id": "0195…",
  "status": "processing",
  "error": null,
  "partial_videos": [
    {
      "id": "0195…",
      "image_url": "https://example.com/a.jpg",
      "transition": "zoom_in",
      "status": "completed",
      "video_url": "http://localhost:8080/videos/partial_0195….mp4",
      "error": null
    }
  ]
}
```

Estados — tarea: `pending | processing | completed | failed`; parte:
`pending | completed | failed`.

### `GET /api/tasks/{id}/final`

```json
{ "task_id": "0195…", "status": "completed", "final_video_url": "http://localhost:8080/videos/final_0195….mp4" }
```

**404** en ambos `GET` si la tarea no existe (o si el id no es un UUID).

### Vídeos

Se sirven directamente por nginx bajo `/videos/`, con caché larga: el nombre
lleva el id, así que el fichero es inmutable.

---

## Variables de entorno

`app/symfony/.env` está versionado y contiene los valores por defecto de
desarrollo — ninguno es un secreto. La precedencia es
`.env` < `.env.local` < `.env.$APP_ENV` < `.env.$APP_ENV.local` < variables reales
del entorno (que es lo que usa docker-compose). Los secretos van en `.env.local`,
que está en `.gitignore`.

| Variable | Para qué |
|---|---|
| `APP_ENV`, `APP_SECRET` | entorno de Symfony |
| `DATABASE_URL` | conexión MySQL de la aplicación (usuario no root) |
| `MESSENGER_TRANSPORT_DSN` | cola de trabajo (AMQP) |
| `MESSENGER_FAILURE_TRANSPORT_DSN` | cola de mensajes con reintentos agotados |
| `APP_URL` | URL pública de la API; con ella se construyen las URLs de vídeo |
| `DEFAULT_URI` | base para generar URLs fuera de una petición HTTP |
| `TASK_LEASE_SECONDS` | cuánto puede estar una tarea en `processing` antes de que otro worker pueda tomarla |
| `FFMPEG_ANIMATE_TIMEOUT`, `FFMPEG_COMPOSE_TIMEOUT` | presupuesto por invocación de FFmpeg (segundos) |
| `FFMPEG_THREADS` | tope de hilos de FFmpeg (`0` = automático) |

`.env.example` en la raíz lista las variables que lee `docker-compose.yml`
(credenciales, puertos del host y `PUBLIC_BASE_URL`).

---

## Desarrollo sin Docker

Con PHP 8.4 y Composer en el host:

```bash
make install     # composer install
make test        # suite completa salvo los grupos mysql y ffmpeg
make check       # cs + phpstan + lint + test
```

`make install` pasa `--ignore-platform-req=ext-amqp`: la extensión AMQP sólo hace
falta en tiempo de ejecución (la imagen de Docker la trae) y no es razonable
exigirla para poder ejecutar la suite.

---

## Tests

```bash
make test          # 230 tests, sin necesidad de servicios
make test-mysql    # los que dependen de MySQL (FKs, anchos de columna)
make test-ffmpeg   # los que ejecutan FFmpeg de verdad
```

Cómo está montada la suite:

- **Base de datos.** `.env.test` apunta a SQLite y `tests/bootstrap.php` crea el
  esquema desde el mapeo, así que la suite funciona en un clon limpio sin ningún
  servicio. Las migraciones son DDL de MySQL y se prueban en CI.
- **Cola.** `config/packages/test/messenger.yaml` sustituye los transportes por
  `in-memory://`, así que no hace falta ni RabbitMQ ni `ext-amqp`.
- **FFmpeg.** Los adaptadores exponen `buildCommand()`, y lo que se comprueba es
  el argv (el grafo de filtros por transición, la lista de concatenación y su
  escapado). El binario sólo lo necesita el grupo `ffmpeg`, excluido por defecto.
- **Descargas.** `ImageDownloaderTest` levanta el servidor embebido de PHP en
  127.0.0.1 y prueba redirecciones, tamaños, tipos y errores contra un servidor
  real. La suite **no sale a internet**: el acceso a loopback lo permite un doble
  de test (`LoopbackTargetPolicy`); la política de producción no tiene ningún
  interruptor que la relaje, y hay un test que lo comprueba.
- Los grupos `mysql` y `ffmpeg` están excluidos en `phpunit.dist.xml` y se
  ejecutan en CI.

---

## Calidad

| Comando | Qué hace |
|---|---|
| `make cs` / `make cs-fix` | PHP-CS-Fixer (`@Symfony` + `@PER-CS`, riesgosos incluidos) |
| `make stan` | PHPStan **nivel 8**, con las extensiones de Symfony, Doctrine y PHPUnit, sin baseline |
| `make rector` | Rector en `--dry-run` (conjuntos de PHP, Symfony, Doctrine y PHPUnit) |
| `make lint` | `lint:container` y `lint:yaml config` |
| `make check` | todo lo anterior más la suite |

Los mismos comandos existen como scripts de Composer (`composer stan`,
`composer cs`, `composer test`…).

---

## Integración continua

`.github/workflows/ci.yml`, con permisos de sólo lectura, `concurrency` y caché de
Composer:

- **lint** — `composer validate --strict`, PHP-CS-Fixer, PHPStan, Rector,
  `lint:container` y `lint:yaml`.
- **test** — servicios `mysql:8` y `rabbitmq:3.13` con *health checks*, FFmpeg
  instalado, las migraciones aplicadas / revertidas / aplicadas de nuevo contra
  MySQL, `messenger:setup-transports` contra RabbitMQ, y la suite completa
  (incluidos los grupos `mysql` y `ffmpeg`) con cobertura pcov y un mínimo del
  80 % de líneas.

---

## Operación

```bash
make logs                 # todos los servicios
make worker               # sólo el worker
make sh                   # shell en el contenedor php
make migrate              # migraciones dentro del stack
```

Mensajes cuyos reintentos se agotaron:

```bash
docker compose exec php php bin/console messenger:failed:show
docker compose exec php php bin/console messenger:failed:retry
```

El worker termina solo cada `WORKER_TIME_LIMIT` segundos o al alcanzar
`WORKER_MEMORY_LIMIT`, y Docker lo reinicia: es la forma estándar de mantener
fresco un proceso PHP de larga duración. Si no puede arrancar (broker caído, DSN
mal), sale con código distinto de cero y se ve en `docker compose ps`.

---

## Troubleshooting

**El worker no consume.** `make worker`. Si el broker no está sano, el worker no
arranca hasta que lo esté (`depends_on: condition: service_healthy`).

**Una tarea se queda en `processing`.** Sólo puede pasar si el worker murió a
mitad; otro worker la retomará pasados `TASK_LEASE_SECONDS` (una hora por
defecto).

**Una tarea acaba en `failed`.** `GET /api/tasks/{id}` devuelve un motivo corto por
tarea y por parte. El detalle completo (la línea de comandos y el stderr de
FFmpeg) está en el log del worker, nunca en la respuesta.

**Un vídeo devuelve 404.** Los ficheros viven en el volumen `videos_data`; si se
borró el volumen (`make down` lo hace), los vídeos se van con él.

---

## Licencia

Proyecto para prueba técnica.

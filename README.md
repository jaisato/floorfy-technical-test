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
  [ worker ]  messenger:consume async callbacks
      │  1. reclama la tarea con un UPDATE condicional (una sola vez)
      │  2. por cada parte: descarga la imagen (con guardia SSRF) y la anima con
      │     FFmpeg hacia un directorio de staging
      │  3. publica cada clip con rename() -> public/videos/partial_<id>.mp4
      │  4. concatena los clips y publica public/videos/final_<task>.mp4
      ▼
GET /api/tasks               listado paginado con filtros
GET /api/tasks/{id}          estado y progreso de la tarea y de cada parte
GET /api/tasks/{id}/final    URL del vídeo final
DELETE /api/tasks/{id}       cancela una tarea pendiente o en curso
POST /api/tasks/{id}/retry   reencola una tarea fallida o cancelada
      │
      ▼  al llegar a completed | failed | canceled, si la tarea tiene callback_url
  [ worker ]  POST firmado (X-Task-Signature) con el resumen de la tarea
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
- **Cancelación.** `DELETE /api/tasks/{id}` escribe `canceled` con un UPDATE
  condicional (sólo sobre `pending` o `processing`, para no pisar a un worker que
  termina en ese mismo instante). El worker relee el estado entre parte y parte y
  se detiene al verlo: acusa el mensaje, no reintenta, y conserva los clips ya
  generados para un posible `retry`.
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
- `callback_url` (opcional): URL a la que se notifica el desenlace (ver
  [Webhook](#webhook)). Pasa por la **misma guardia SSRF** que las imágenes en el
  momento de la petición: sólo `http`/`https`, puertos 80/443 y direcciones
  públicas; cualquier otra cosa es un **400**.
- **201** `{"task_id": "...", "status": "pending"}`
- **400** un documento `application/problem+json` (ver [Errores](#errores)).
- Acepta la cabecera `Idempotency-Key` (ver
  [Idempotencia](#idempotencia-idempotency-key)).

### Idempotencia (`Idempotency-Key`)

`POST /api/tasks` no es idempotente por sí mismo: si la respuesta se pierde (un
timeout, una conexión cortada), el cliente no sabe si la tarea se creó, y
reintentar crea un segundo vídeo. Con una cabecera `Idempotency-Key` elegida por
el cliente (1–255 caracteres ASCII imprimibles, típicamente un UUID) el reintento
es seguro:

| Situación | Respuesta |
|---|---|
| Primera petición con esa clave | la normal (201, o el error que corresponda) |
| **Misma clave, mismo cuerpo** | la **misma respuesta guardada**, con `Idempotency-Replayed: true`; no se crea nada |
| Misma clave, **cuerpo distinto** | **422**: una clave nombra una sola petición |
| Misma clave mientras la primera petición **sigue en curso** | **409** |
| Clave vacía, demasiado larga o con caracteres no imprimibles | **400** |

Detalles que conviene conocer:

- «Mismo cuerpo» se decide por una huella SHA-256 de método, ruta y cuerpo
  **canonicalizado**: en un objeto JSON el orden de las claves no cuenta (dos
  serializaciones del mismo objeto son la misma petición), pero el orden de una
  lista sí (son otras imágenes).
- Se guarda cualquier respuesta por debajo de 500, **incluidos los 400**: repetir
  una petición inválida bajo la misma clave devuelve el mismo error, no una tarea.
  Un 5xx **libera** la clave: el fallo es nuestro y el reintento tiene que
  ejecutarse de verdad.
- Las claves se guardan **por llamante**, así que dos clientes no pueden
  colisionar eligiendo la misma. Sin autenticación todos los llamantes son el
  mismo (`anonymous`).
- Caducan a los `IDEMPOTENCY_TTL_SECONDS` (24 h por defecto). Los registros
  caducados se borran en cada reclamación, así que la tabla `idempotency_keys` no
  necesita ningún cron.
- La reclamación es un `INSERT` sobre la clave primaria `(scope, key)`: dos
  peticiones simultáneas compiten en la base de datos y sólo una gana. No hay
  ningún «leer y luego escribir» que pueda cruzarse.

### `GET /api/tasks`

Listado paginado, de la más reciente a la más antigua (`created_at` descendente,
con el `id` como desempate, así que dos páginas nunca se solapan):

```
GET /api/tasks?status=failed&createdFrom=2026-01-01&createdTo=2026-01-31T23:59:59Z&page=2&limit=20
```

| Parámetro | Valor |
|---|---|
| `status` | `pending`, `processing`, `completed`, `failed` o `canceled` |
| `createdFrom`, `createdTo` | fecha ISO 8601 (una fecha sola es el inicio de ese día, en UTC); ambos límites inclusivos |
| `page` | número de página, desde 1 |
| `limit` | tamaño de página (20 por defecto, máximo 100; un valor mayor se recorta a 100) |

```json
{
  "items": [ { "task_id": "…", "status": "…", "progress": { "…": "…" }, "final_video_url": null, "error": null, "created_at": "…", "updated_at": "…" } ],
  "total": 41, "page": 2, "limit": 20, "pages": 3, "hasNext": true
}
```

Cada elemento es el mismo resumen que devuelve `GET /api/tasks/{id}`, sin
`partial_videos`. La cabecera `Link` (RFC 8288) lleva las páginas vecinas
(`rel="prev"`, `rel="next"`) con todos los filtros de la petición. Un
parámetro inválido (estado desconocido, fecha que no lo es, `page` o `limit`
que no son enteros positivos, rango invertido) es un **400** con `violations`.

### `GET /api/tasks/{id}`

```json
{
  "task_id": "0195…",
  "status": "processing",
  "progress": { "completed": 1, "failed": 0, "pending": 1, "total": 2, "percent": 50 },
  "final_video_url": null,
  "error": null,
  "created_at": "2026-01-02T03:04:05+00:00",
  "updated_at": "2026-01-02T03:04:09+00:00",
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

Estados — tarea: `pending | processing | completed | failed | canceled`; parte:
`pending | completed | failed`.

`progress` cuenta las partes: `percent` es la proporción de partes completadas,
truncada, así que no llega a `100` mientras quede alguna pendiente o fallida.
Las fechas van siempre en UTC (ISO 8601).

### `GET /api/tasks/{id}/final`

```json
{ "task_id": "0195…", "status": "completed", "final_video_url": "http://localhost:8080/videos/final_0195….mp4" }
```

**404** en ambos `GET` si la tarea no existe (o si el id no es un UUID).

### `DELETE /api/tasks/{id}`

Cancela una tarea `pending` o `processing`. Responde **200** con la misma
representación que `GET /api/tasks/{id}` (ya con `status: canceled`). Una tarea
`completed`, `failed` o `canceled` no se puede cancelar: **409** con el detalle
de la transición rechazada. **404** si no existe.

### `POST /api/tasks/{id}/retry`

Reencola una tarea `failed` o `canceled`: las partes completadas se conservan
(vídeo incluido), las fallidas vuelven a `pending`, `error` se limpia y se
publica un nuevo mensaje para el worker. Responde **202** con la tarea; **409**
para cualquier otro estado; **404** si no existe.

### Webhook

Si la tarea se creó con `callback_url`, al llegar a `completed`, `failed` o
`canceled` se encola una notificación (transporte `callbacks`, con sus propios
reintentos: 6 intentos de 30 s a 10 min, después la cola `failed`) que hace un
`POST` a esa URL:

| Cabecera | Valor |
|---|---|
| `Content-Type` | `application/json` |
| `X-Task-Event` | `task.completed`, `task.failed` o `task.canceled` |
| `X-Task-Id` | id de la tarea |
| `X-Task-Signature` | `sha256=<hex>`: HMAC-SHA256 del cuerpo exacto con `CALLBACK_SIGNING_SECRET` |

El cuerpo es el **resumen de la tarea tal y como está en ese momento** (lo mismo
que un elemento de `GET /api/tasks`, sin `partial_videos`). El evento va en la
cabecera, así que un `task.failed` entregado tras un `retry` sigue diciendo qué
pasó aunque el cuerpo ya muestre `pending`.

Verificación en el receptor (PHP):

```php
$expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_TASK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
```

Reglas de entrega: se acepta cualquier `2xx`; no se siguen redirecciones (un
`3xx` cuenta como rechazo); 10 s de tiempo máximo; la conexión se fija a la
dirección validada por la guardia (como con las imágenes). Un fallo de entrega
**nunca** altera el estado de la tarea; sin `CALLBACK_SIGNING_SECRET` no se envía
nada sin firmar (la notificación acaba en la cola `failed`, con el motivo en el
log).

### Errores

Un único contrato para toda la API, venga el error de un controlador, del router
o de una excepción no prevista: `application/problem+json` (RFC 9457).

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400,
  "detail": "La petición no supera la validación.",
  "violations": { "images[0].url": ["This value is not a valid URL."] }
}
```

El cuerpo **nunca** lleva el mensaje de una excepción inesperada — ahí es donde
un driver pone el host al que no pudo conectar —: eso va al log.

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
| `MESSENGER_CALLBACKS_TRANSPORT_DSN` | cola de notificaciones webhook (reintentos propios) |
| `MESSENGER_FAILURE_TRANSPORT_DSN` | cola de mensajes con reintentos agotados |
| `CALLBACK_SIGNING_SECRET` | clave HMAC de `X-Task-Signature`; una por despliegue, sin ella no se envían notificaciones |
| `APP_URL` | URL pública de la API; con ella se construyen las URLs de vídeo |
| `DEFAULT_URI` | base para generar URLs fuera de una petición HTTP |
| `TASK_LEASE_SECONDS` | cuánto puede estar una tarea en `processing` antes de que otro worker pueda tomarla |
| `IDEMPOTENCY_TTL_SECONDS` | cuánto se recuerda una `Idempotency-Key` (24 h por defecto) |
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
make test          # la suite entera, sin necesidad de servicios
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

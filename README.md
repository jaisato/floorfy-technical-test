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
  - [Endpoints](#endpoints)
  - [Opciones de render](#opciones-de-render)
  - [Idempotencia](#idempotencia-idempotency-key)
  - [Webhook](#webhook)
  - [OpenAPI](#openapi)
  - [Errores](#errores)
  - [Vídeos y URLs firmadas](#vídeos)
  - [Autenticación](#autenticación-opcional)
  - [Límite de creación](#límite-de-creación-opcional)
  - [Salud](#salud-health-healthready)
- [Variables de entorno](#variables-de-entorno)
- [Desarrollo sin Docker](#desarrollo-sin-docker)
- [Tests](#tests)
- [Calidad](#calidad)
- [Integración continua](#integración-continua)
- [Operación](#operación)
  - [Retención de vídeos](#retención-de-vídeos)
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
      │  4. concatena los clips (corte seco o xfade) y publica
      │     public/videos/final_<task>.mp4
      ▼
GET /api/tasks               listado paginado con filtros
GET /api/tasks/{id}          estado y progreso de la tarea y de cada parte
GET /api/tasks/{id}/final    URL del vídeo final
DELETE /api/tasks/{id}       cancela una tarea pendiente o en curso
POST /api/tasks/{id}/retry   reencola una tarea fallida o cancelada
      │
      ▼  al llegar a completed | failed | canceled, si la tarea tiene callback_url
  [ callback-worker ]  messenger:consume callbacks
      │  POST firmado (X-Task-Signature) con el resumen de la tarea
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
- **Retención.** Nada borra ficheros solo: `app:videos:prune` es un trabajo de
  cron (ver [Retención de vídeos](#retención-de-vídeos)).
- **SSRF.** Las URLs de imagen vienen de fuera, así que se resuelven y validan
  contra el espacio de direcciones público (IPv4 e IPv6), sólo por los puertos 80
  y 443, cada redirección se revalida y la conexión se fija a la dirección ya
  comprobada (anti *DNS rebinding*). Se comprueba además el `Content-Type`, el
  `Content-Length` y el contenido real del fichero descargado.

---

## API

### Endpoints

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/api/tasks` | crea una tarea (201); acepta `Idempotency-Key` |
| `GET` | `/api/tasks` | listado paginado con filtros y cabecera `Link` |
| `GET` | `/api/tasks/{id}` | estado, progreso y partes |
| `GET` | `/api/tasks/{id}/final` | URL del vídeo final |
| `DELETE` | `/api/tasks/{id}` | cancela una tarea pendiente o en curso (200) |
| `POST` | `/api/tasks/{id}/retry` | reencola una fallida o cancelada (202) |
| `GET` | `/api/doc.json` | documento OpenAPI 3 (público) |
| `GET` | `/videos/{nombre}` | sirve un vídeo (firmado si hay `VIDEO_URL_SECRET`) |
| `GET` | `/health`, `/health/ready` | liveness y readiness (públicos) |

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
- `duration` (opcional, por imagen) y `options` (opcional, para toda la tarea):
  ver [Opciones de render](#opciones-de-render).
- `callback_url` (opcional): URL a la que se notifica el desenlace (ver
  [Webhook](#webhook)). Pasa por la **misma guardia SSRF** que las imágenes en el
  momento de la petición: sólo `http`/`https`, puertos 80/443 y direcciones
  públicas; cualquier otra cosa es un **400**.
- **201** `{"task_id": "...", "status": "pending"}`
- **400** un documento `application/problem+json` (ver [Errores](#errores)).
- Acepta la cabecera `Idempotency-Key` (ver
  [Idempotencia](#idempotencia-idempotency-key)).

### Opciones de render

Todo es opcional; lo que no se manda se queda con el valor por defecto del
despliegue (`RENDER_DEFAULT_*`).

```json
{
  "images": [
    { "url": "https://example.com/a.jpg", "transition": "zoom_in", "duration": 5 },
    { "url": "https://example.com/b.jpg", "transition": "pan" }
  ],
  "options": { "duration": 3, "fps": 24, "resolution": "1920x1080", "crossfade": 0.5 }
}
```

| Opción | Dónde | Valores |
|---|---|---|
| `duration` | tarea **y** imagen | 1–15 segundos |
| `fps` | tarea | `24`, `25` o `30` |
| `resolution` | tarea | `1280x720` o `1920x1080` |
| `crossfade` | tarea | 0–2 segundos; `0` es un corte seco |

Por qué sólo `duration` puede variar por imagen: sin fundido, los parciales se
concatenan **copiando el flujo**, y eso sólo funciona mientras todos comparten
codec, tamaño y frecuencia de fotogramas. Una imagen a 1080p/24 dentro de una
tarea 720p/30 daría un fichero que se reproduce mal o no se reproduce.

El fundido usa el filtro `xfade` de FFmpeg, que sí re-codifica: por eso el corte
seco (`crossfade: 0`) es el valor por defecto y el camino barato. El fundido se
recorta a la mitad del clip más corto — dos segundos de fundido entre clips de
uno no son una transición, son un comando que FFmpeg rechaza.

Las opciones se resuelven **al crear la tarea** y se guardan con ella, así que un
`retry` meses después renderiza lo mismo aunque los valores por defecto del
despliegue hayan cambiado.

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
| Misma clave cuando la primera petición **murió sin responder** (más de 5 min sin respuesta guardada) | se ejecuta **de verdad**: la reclamación abandonada se sustituye |
| Clave vacía, demasiado larga o con caracteres no imprimibles | **400** |

Detalles que conviene conocer:

- «Mismo cuerpo» se decide por una huella SHA-256 de método, ruta y cuerpo
  **canonicalizado**: en un objeto JSON el orden de las claves no cuenta (dos
  serializaciones del mismo objeto son la misma petición), pero el orden de una
  lista sí (son otras imágenes).
- Se guarda cualquier respuesta que zanje la petición, **incluidos los 400**:
  repetir una petición inválida bajo la misma clave devuelve el mismo error, no
  una tarea. Un **5xx** y un **429** **liberan** la clave, porque las dos
  respuestas invitan a volver: en el 5xx el fallo es nuestro, y guardar el 429
  dejaría la clave pegada a un «vuelve luego» durante todo el TTL, de modo que
  la tarea no se crearía nunca por mucho que el cliente esperase.
- Las claves se guardan **por llamante**, así que dos clientes no pueden
  colisionar eligiendo la misma. Sin autenticación todos los llamantes son el
  mismo (`anonymous`).
- Caducan a los `IDEMPOTENCY_TTL_SECONDS` (24 h por defecto). Los registros
  caducados se borran en cada reclamación, así que la tabla `idempotency_keys` no
  necesita ningún cron.
- La reclamación es un `INSERT` sobre la clave primaria `(scope, key)`: dos
  peticiones simultáneas compiten en la base de datos y sólo una gana. No hay
  ningún «leer y luego escribir» que pueda cruzarse.
- Una reclamación la libera la respuesta que cierra su petición, y una petición
  que muere sin responder (php-fpm la para en `max_execution_time`, se queda sin
  memoria, el contenedor se reemplaza debajo) no libera nada. Para que el
  reintento del cliente no reciba `409` durante todo el TTL por una tarea que
  nunca se creó, una reclamación **sin respuesta durante más de 5 minutos**
  (`DbalIdempotencyStore::IN_PROGRESS_GRACE_SECONDS`; ninguna petición dura ni
  de lejos tanto: php-fpm corta a los 60 s y nginx deja de esperar a los 30 s)
  se considera abandonada y el siguiente reintento la sustituye y se ejecuta de
  verdad. Un cuerpo distinto bajo esa clave sigue siendo un `422`.

### `GET /api/tasks`

Listado paginado, de la más reciente a la más antigua (`created_at` descendente,
con el `id` como desempate, así que el orden es total: una consulta nunca deja
una tarea sin sitio ni la pone en dos):

```
GET /api/tasks?status=failed&createdFrom=2026-01-01&createdTo=2026-01-31T23:59:59Z&page=2&limit=20
```

| Parámetro | Valor |
|---|---|
| `status` | `pending`, `processing`, `completed`, `failed` o `canceled` |
| `createdFrom`, `createdTo` | fecha ISO 8601 (una fecha sola es el inicio de ese día, en UTC); ambos límites inclusivos |
| `page` | número de página, desde 1 |
| `limit` | tamaño de página (20 por defecto, máximo 100; un valor mayor se recorta a 100) |

Recorrer varias páginas es otra cosa. `page` cuenta filas desde el principio, y
el principio de este orden es donde entran las tareas nuevas: una creada entre
la página 1 y la página 2 desplaza todo lo demás una posición, de modo que el
último elemento de la primera vuelve a salir en la segunda y otro se cae al
final. Para un recorrido estable, fija el extremo con `createdTo` en la primera
petición —el `created_at` del primer elemento sirve— y repítelo en las
siguientes: nada creado después entra ya en el listado, y las páginas son las
mismas de principio a fin.

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
  "pruned_at": null,
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

`pruned_at` es lo que explica un `final_video_url` nulo en una tarea
`completed`: sus vídeos los borró la [retención](#retención-de-vídeos).

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
`POST` a esa URL. La consume un worker propio, `callback-worker`: un worker
procesa un mensaje cada vez y un render dura minutos, así que compartiendo
proceso con `async` ninguna notificación salía hasta que terminara el vídeo que
tuviera delante.

| Cabecera | Valor |
|---|---|
| `Content-Type` | `application/json` |
| `X-Task-Event` | `task.completed`, `task.failed` o `task.canceled` |
| `X-Task-Id` | id de la tarea |
| `X-Task-Run` | número de ejecución de la tarea (entero, empieza en 1) |
| `X-Task-Signature` | `sha256=<hex>`: HMAC-SHA256 de **evento + id + ejecución + cuerpo** con `CALLBACK_SIGNING_SECRET` |

El cuerpo es el **resumen de la tarea tal y como está en ese momento** (lo mismo
que un elemento de `GET /api/tasks`, sin `partial_videos`). El evento va en la
cabecera, así que un `task.failed` entregado tras un `retry` sigue diciendo qué
pasó aunque el cuerpo ya muestre `pending`.

Precisamente por eso la firma **no** cubre sólo el cuerpo: el evento anunciado no
se puede reconstruir a partir de él, así que firmando sólo el cuerpo el único
campo que el receptor no puede verificar era también el único que alguien en el
camino podía reescribir gratis sobre un endpoint `http://` permitido —
convirtiendo un fallo en una finalización, o la notificación de esta ejecución en
la de la anterior. Lo firmado son las tres cabeceras que identifican la
notificación y después el cuerpo, una por línea:

```
task.completed\n<task_id>\n<run>\n<cuerpo exacto>
```

Todo lo anterior al cuerpo ocupa una línea por construcción (`task.` + estado, un
UUID, un entero decimal), así que ninguna combinación de valores puede leerse como
otra, y el cuerpo va al final para que sus propios saltos de línea no muevan los
límites.

`X-Task-Run` distingue dos ejecuciones de la misma tarea: el cuerpo dice cómo está
**ahora**, y dos ejecuciones pueden acabar las dos en `failed` en el mismo segundo
(cancelar, reintentar y cancelar), así que es lo único con lo que el receptor puede
ordenarlas o descartar la notificación de una ejecución ya superada.

Verificación en el receptor (PHP):

```php
$signed = implode("\n", [
    $_SERVER['HTTP_X_TASK_EVENT'] ?? '',
    $_SERVER['HTTP_X_TASK_ID'] ?? '',
    $_SERVER['HTTP_X_TASK_RUN'] ?? '',
    $rawBody,
]);
$expected = 'sha256='.hash_hmac('sha256', $signed, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_TASK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
```

Reglas de entrega: se acepta cualquier `2xx`; no se siguen redirecciones (un
`3xx` cuenta como rechazo); 10 s de tiempo máximo; la conexión se fija a la
dirección validada por la guardia (como con las imágenes). Un fallo de entrega
**nunca** altera el estado de la tarea; sin `CALLBACK_SIGNING_SECRET` no se envía
nada sin firmar (la notificación acaba en la cola `failed`, con el motivo en el
log).

### OpenAPI

`GET /api/doc.json` devuelve el documento OpenAPI 3 de la API (público aunque
`API_TOKENS` esté configurado: nadie debería tener que autenticarse para leer el
contrato). Se genera con `nelmio/api-doc-bundle` a partir de los atributos de los
controladores más los esquemas compartidos de
`config/packages/nelmio_api_doc.yaml` (`Problem`, `Task`, `TaskPage`,
`RenderOptions`, los dos esquemas de seguridad…).

`tests/Ui/Http/OpenApiTest.php` compara el documento con el router: **una ruta
nueva sin documentar rompe la suite**, igual que una operación sin `summary`, una
respuesta sin cuerpo o un `401`/`404` sin declarar. Un documento escrito a mano
caduca en cuanto alguien añade un endpoint; éste no puede.

Para verlo con Swagger UI, sin añadir dependencias al proyecto:

```bash
docker run --rm -p 8081:8080 -e SWAGGER_JSON_URL=http://localhost:8080/api/doc.json swaggerapi/swagger-ui
```

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

`GET /videos/{nombre}` sirve un vídeo generado. La ruta sólo admite los dos
nombres que produce la aplicación (`partial_<uuid>.mp4`, `final_<uuid>.mp4`), así
que ninguna petición puede nombrar un fichero cualquiera.

**Sin `VIDEO_URL_SECRET`** (por defecto) no se comprueba nada y la respuesta se
cachea 30 días: el nombre lleva el id, el fichero es inmutable.

**Con `VIDEO_URL_SECRET`** cada URL que devuelve la API va firmada y caduca:

```
http://localhost:8080/videos/final_0195….mp4?expires=1767322800&sig=<hmac-sha256>
```

La firma es un HMAC-SHA256 sobre la ruta **y** el plazo, así que un enlace no se
puede editar para apuntar a otro vídeo ni para durar más, y deja de funcionar
solo (`VIDEO_URL_TTL_SECONDS`, 1 h por defecto). Un enlace inválido o caducado es
un **403**, no un 404: el vídeo probablemente existe, y decir lo contrario manda
al cliente a buscar una tarea perdida. **Actívalo siempre que actives
`API_TOKENS`**: si no, los vídeos de una API autenticada siguen siendo legibles
por cualquiera que tenga el enlace, y la URL es lo único que puede llevar una
etiqueta `<video>` — no puede mandar una cabecera `Authorization`.

Por qué en la aplicación y no con `secure_link` de nginx: así la comprobación
está cubierta por la suite, es idéntica en todos los despliegues y responde en
`problem+json` como el resto de la API. Lo que `secure_link` habría ahorrado —
tener un proceso de PHP ocupado durante toda la descarga — se evita igual: con
`VIDEOS_X_ACCEL_PREFIX` puesto (lo pone el compose) el controlador responde con
`X-Accel-Redirect` y **nginx** manda los bytes desde una *location* `internal`,
que el exterior no puede pedir. Sin esa variable (la suite, `symfony server`)
los manda PHP.

La tarea guarda la **ruta** del vídeo, no una URL: una URL en la base de datos
congela el host del día en que se renderizó y no puede llevar una firma que
caduque. La dirección se construye al pedirla.

### Autenticación (opcional)

Desactivada por defecto: `API_TOKENS` vacío deja la API abierta, que es lo que
esperan las instrucciones de la prueba. Con valor, la forma es
`nombre:secreto,otro:secreto` (secretos de **16 caracteres como mínimo**), y a
partir de ahí toda petición necesita una credencial:

```bash
curl -H 'Authorization: Bearer <secreto>' http://localhost:8080/api/tasks
curl -H 'X-API-Key: <secreto>'            http://localhost:8080/api/tasks
```

- Sin credencial, o con una que no existe: **401** `problem+json` con
  `WWW-Authenticate`. **El mismo cuerpo en los dos casos**: distinguir «no has
  mandado clave» de «esa clave no existe» es un oráculo.
- El **nombre** del cliente no viaja: es lo que el secreto resuelve, y es lo que
  se usa como *scope* de sus [`Idempotency-Key`](#idempotencia-idempotency-key)
  y en el log.
- Quedan públicos `/health`, `/health/ready`, `/api/doc.json` y `/videos/…` —
  un *prober* no tiene token y un reproductor de vídeo no puede mandar
  cabeceras. Los vídeos se protegen con [URLs firmadas](#vídeos).
- Una configuración mal escrita (sin `:`, secreto corto, dos clientes con el
  mismo secreto) es un error de arranque, no un despliegue silenciosamente
  abierto.

### Límite de creación (opcional)

`RATE_LIMIT_TASK_CREATION=N` limita a N por minuto los `POST /api/tasks` de un
mismo llamante (ventana deslizante, no fija: así nadie gasta el presupuesto de
un minuto dos veces cruzando el borde). `0` — el valor por defecto — lo apaga
del todo: ni siquiera se consulta el limitador.

Sólo se cuenta la creación de tareas, que es lo que cuesta dinero: cada petición
compra minutos de FFmpeg en un worker compartido. Las lecturas no se limitan.

- Dentro del límite: la respuesta normal, con `X-RateLimit-Limit`,
  `X-RateLimit-Remaining` y `X-RateLimit-Reset`, para que un cliente educado
  pueda frenar antes de que le digan que no.
- Pasado el límite: **429** `problem+json` con `Retry-After`. No se crea nada.
- El contador es **por llamante**: el nombre del cliente autenticado si lo hay,
  y la IP si la API está abierta.
- Un **reintento que se responde desde la caché de
  [`Idempotency-Key`](#idempotencia-idempotency-key) no gasta cuota**: no crea
  nada. El cliente reintenta precisamente porque no vio la respuesta, y cobrarle
  ese reintento convertiría el timeout en un 429 sobre una tarea que ya existe.
  Todo lo que sí podría crear una tarea pasa por el limitador.

### Salud (`/health`, `/health/ready`)

Dos preguntas distintas, con dos respuestas distintas — mezclarlas es lo que
hace que un orquestador reinicie una aplicación sana porque su base de datos se
ha caído:

| Endpoint | Pregunta | Respuesta |
|---|---|---|
| `GET /health` | *liveness*: ¿sigue vivo el proceso? | siempre **200** `{"status":"ok"}`; no toca nada |
| `GET /health/ready` | *readiness*: ¿puede trabajar ahora? | **200** si todo pasa, **503** si algo falla |

```json
{
  "status": "unavailable",
  "checks": { "database": "ok", "ffmpeg": "failed", "transport": "ok", "videos_dir": "ok", "work_dir": "ok" }
}
```

Qué se comprueba: un `SELECT 1` contra MySQL, el recuento de la cola `async`
(que es lo que abre de verdad la conexión con RabbitMQ), `ffmpeg -version`, y
que `public/videos` y el directorio de trabajo existen y son escribibles (los
dos son volúmenes de Docker, y un volumen creado antes que la imagen es la forma
clásica de que todos los renders fallen en el último paso).

El cuerpo dice **qué** comprobación falla, nunca **por qué**: el motivo (un
host, una ruta, un mensaje del driver) va al log. Los dos endpoints siguen
siendo públicos aunque la API key esté activada — un *prober* no tiene token — y
la respuesta de readiness nunca se cachea.

Lo mismo desde la shell, que es lo que ejecutan los *healthchecks* de los
contenedores (el worker no sirve HTTP y php-fpm no habla HTTP):

```bash
docker compose exec php php bin/console app:health:ready   # 0 = listo, 1 = no
```

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
| `API_TOKENS` | claves de API `nombre:secreto,…`; **vacío = API abierta** (por defecto) |
| `RATE_LIMIT_TASK_CREATION` | tareas por minuto y llamante; `0` = sin límite (por defecto) |
| `SYMFONY_TRUSTED_PROXIES` | direcciones (IPs, rangos CIDR, `private_ranges`, `REMOTE_ADDR`) de los proxies inversos cuyas cabeceras `X-Forwarded-For` / `-Proto` / `-Port` se creen; vacío = se ignoran (por defecto). Detrás de un balanceador que termina TLS hace falta: sin ella el límite de creación cuenta a todos los clientes como la IP del proxy y las cabeceras `Link` de los listados salen con `http://`. El stack de compose no la necesita (nginx habla FastCGI y pasa la IP real). `SYMFONY_TRUSTED_HEADERS` añade `x-forwarded-host` / `x-forwarded-prefix` |
| `RENDER_DEFAULT_DURATION`, `RENDER_DEFAULT_FPS`, `RENDER_DEFAULT_RESOLUTION`, `RENDER_DEFAULT_CROSSFADE` | valores por defecto de las [opciones de render](#opciones-de-render) |
| `VIDEO_URL_SECRET` | clave HMAC de las URLs firmadas de `/videos/`; vacío = sin firmar |
| `VIDEO_URL_TTL_SECONDS` | validez de una URL firmada (1 h por defecto) |
| `VIDEOS_X_ACCEL_PREFIX` | *location* interna de nginx a la que se delega el envío del fichero; vacío = lo manda PHP |
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

### Retención de vídeos

Los ficheros son lo único que crece sin límite: una fila de tarea son unos
cientos de bytes, un vídeo son megas, y comparten volumen. El comando
`app:videos:prune` borra el vídeo final, los clips y el directorio de trabajo de
las tareas **terminadas** (`completed`, `failed` o `canceled`) cuya última
actualización sea anterior a la ventana:

```bash
docker compose exec php php bin/console app:videos:prune --older-than=30d --dry-run
docker compose exec php php bin/console app:videos:prune --older-than=30d
```

| Opción | Valor |
|---|---|
| `--older-than` | ventana de retención: `<n>m`, `<n>h`, `<n>d` o `<n>w` (por defecto `7d`) |
| `--dry-run` | enumera lo que borraría y no toca nada |
| `--limit` | máximo de tareas por ejecución (500 por defecto), para que una ejecución tenga coste acotado |

La **tarea no se borra**: se queda con `final_video_url` a null y con
`pruned_at`. Borrar la fila perdería el registro de que el trabajo se hizo y
dejaría que la misma petición se creara otra vez como nueva. Las tareas en curso
nunca se tocan: borrar sus ficheros rompería el render en marcha. Cada tarea se
vuelve a leer **bajo el bloqueo de su fila**, dentro de la misma transacción que
borra: entre listarla y llegar a ella cabe un reintento, y entonces esos
ficheros no son restos sino la entrada del render que acaba de empezar.

Reintentar una tarea (`POST /api/tasks/{id}/retry`) limpia su `pruned_at`: va a
tener vídeos otra vez, y dejarlo puesto la excluiría de todas las retenciones
posteriores, que es justo lo contrario de lo que hace falta.

El comando barre además `public/videos/.staging/`, donde un worker que muere a
media codificación deja el fichero a medio escribir que ya no publica nadie
(sólo los anteriores a la ventana, así que un render en marcha nunca entra).

En cron, una vez al día:

```cron
0 4 * * * cd /ruta/al/repo && docker compose exec -T php php bin/console app:videos:prune --older-than=30d
```

### Recuperación de mensajes perdidos

La fila de una tarea vive en MySQL y su mensaje en RabbitMQ, así que escribir
una no puede formar parte de confirmar el otro. El mensaje se publica justo
**después** del commit —nunca antes, que anunciaría una tarea que aún puede
revertirse—, y un proceso que muera en ese hueco deja una tarea en `pending` que
ningún worker reclamará, o una tarea terminada cuyo callback nadie entregará.

`app:tasks:recover` busca esas dos cosas y vuelve a publicar su mensaje:

```bash
docker compose exec php php bin/console app:tasks:recover --stuck-for=10m --dry-run
docker compose exec php php bin/console app:tasks:recover --stuck-for=10m
```

| Opción | Valor |
|---|---|
| `--stuck-for` | cuánto tiempo sin tocar antes de dar un mensaje por perdido (por defecto `10m`) |
| `--dry-run` | enumera lo que reencolaría y no publica nada |
| `--limit` | máximo de tareas de cada tipo por ejecución (100 por defecto) |

Repetirlo es inofensivo, y por el mismo motivo en los dos casos: cada mensaje se
reclama antes de publicarse. Una tarea que ya se está procesando rechaza la
reclamación; una notificación se marca en `callback_attempted_at` al publicarla,
así que la barrida siguiente —con la entrega todavía en vuelo o reintentándose en
el transporte— la deja en paz hasta que ese intento sea a su vez más antiguo que
la ventana. Sin esa marca, «aún no entregada» se leía como «perdida» y el cliente
recibía el mismo POST una vez por barrida. Una notificación ya entregada queda
registrada en `callback_notified_at` y deja de aparecer. Reintentar la tarea borra
ambas marcas:
la ejecución nueva vuelve a terminar y debe su propia notificación, y con la
marca de la anterior puesta ésa sería la única que este comando no podría
recuperar nunca.

Qué ejecución es cada cosa lo dice `run_generation`, un contador que avanza en
cada escritura que empieza una ejecución (la reclamación del worker) o la
termina. El estado no sirve —dos ejecuciones de una tarea pueden acabar igual— y
`updated_at` tampoco: es un `DATETIME`, y cancelar, reintentar y volver a
cancelar sin que ningún worker llegue a reclamarla deja las dos dentro del mismo
segundo. El worker lleva el número que le dio su reclamación y cada escritura
suya lo devuelve, así que un intento al que le quitaron la tarea no puede
renovar, soltar ni completar la ejecución de otro; y la entrega de una
notificación se registra contra el número de la transición que la produjo, así
que la entrega tardía de una ejecución anterior no responde por la que el
cliente sigue esperando. La ventana importa: una tarea
publicada hace un segundo no está atascada, es nueva, y reencolarla sólo pondría
a dos workers a competir por una reclamación que uno va a perder. En un
despliegue sano este comando no encuentra nada, que es también la forma de saber
si se está perdiendo algo.

En cron, cada pocos minutos:

```cron
*/5 * * * * cd /ruta/al/repo && docker compose exec -T php php bin/console app:tasks:recover --stuck-for=10m
```

### Comandos

```bash
make logs                 # todos los servicios
make worker               # sólo el worker
make sh                   # shell en el contenedor php
make migrate              # migraciones dentro del stack
make ready                # comprobaciones de readiness (0 = listo)
make prune RETENTION=30d  # retención de vídeos
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

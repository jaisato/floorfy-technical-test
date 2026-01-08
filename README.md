# Floorfy Technical Test — Backend (Symfony + Docker)

API REST en Symfony para crear **tareas** que generan:
1) **vídeos parciales** a partir de imágenes (con transiciones), y
2) un **vídeo final** concatenando los parciales.

La ejecución pesada (descarga + FFmpeg) se hace en background con **Symfony Messenger + RabbitMQ**.

---

## Índice

- [Stack](#stack)
- [Requisitos](#requisitos)
- [Arranque rápido](#arranque-rápido)
- [Servicios y puertos](#servicios-y-puertos)
- [Cómo funciona](#cómo-funciona)
- [API](#api)
    - [POST /api/tasks](#post-apitasks)
    - [GET /api/tasks/{id}](#get-apitasksid)
    - [GET /api/tasks/{id}/final](#get-apitasksidfinal)
    - [Servir vídeos](#servir-vídeos)
- [Instrucciones para probar](#instrucciones-para-probar-el-proyecto)
- [Logs y depuración](#logs-y-depuración)
- [Base de datos](#base-de-datos)
- [RabbitMQ](#rabbitmq)
- [Troubleshooting](#troubleshooting)
- [Comandos útiles](#comandos-útiles)

---

## Stack

- PHP 8.x + Symfony
- Nginx
- MySQL
- RabbitMQ (AMQP)
- FFmpeg
- Symfony Messenger (cola `async`)

---

## Requisitos

- Docker
- Docker Compose

> Recomendación en Windows: usar WSL2 y tener el repo dentro del filesystem Linux (p. ej. `~/projects/...`) para evitar problemas de permisos.

---

## Arranque rápido

Desde la raíz del repositorio:

```bash
docker compose up -d --build
```

### Migraciones / Esquema de Base de Datos

Tras levantar los contenedores por primera vez, necesitas crear el esquema de BD.

Ejecutar migraciones Doctrine (si existen)

```bash
docker exec -it floorfy-technical-test-php-1 sh -lc "php bin/console doctrine:migrations:migrate -n"
```

Comprobar que está arriba:

```bash
curl -i http://localhost:8080/
```

Ver logs:

```bash
docker compose logs -f nginx
docker compose logs -f php
docker compose logs -f worker
```

---

## Servicios y puertos

- API (Nginx): `http://localhost:8080`
- MySQL: `localhost:3307`
- RabbitMQ AMQP: `localhost:5672`
- RabbitMQ UI: `http://localhost:15672` (guest / guest)

---

## Cómo funciona

1) `POST /api/tasks` crea una task en BD con estado `pending` y encola un mensaje en Messenger.
2) El servicio `worker` consume la cola `async` (RabbitMQ) y procesa la task:
    - descarga imágenes
    - genera `public/videos/partial_<partial_id>.mp4`
    - concatena parciales y genera `public/videos/final_<task_id>.mp4`
3) La API expone el estado de la task y la URL del vídeo final.

### Persistencia de ficheros

Para evitar problemas de permisos con bind mounts, se usan volúmenes Docker:

- `videos_data` → `/var/www/html/public/videos`
- `work_data` → `/var/www/html/var/work`

---

## API

### POST /api/tasks

Crea una task.

**Request:**
- `images`: array de elementos con:
    - `url`: string (URL pública de imagen)
    - `transition`: string (por ejemplo: `zoom_in`, `zoom_out`, `pan`)

**Ejemplo:**

```bash
curl -i -X POST "http://localhost:8080/api/tasks" \
  -H "Content-Type: application/json" \
  -d '{
    "images": [
      { "url": "https://www.tuexperto.com/wp-content/uploads/2020/01/png.jpg", "transition": "zoom_in" },
      { "url": "https://www.tuexperto.com/wp-content/uploads/2020/01/png.jpg", "transition": "pan" }
    ]
  }'
```

**Respuesta (ejemplo):**
```json
{
  "task_id": "019b...abc",
  "status": "pending"
}
```

---

### GET /api/tasks/{id}

Devuelve el estado de la task y el estado de cada vídeo parcial.

```bash
curl -s "http://localhost:8080/api/tasks/<TASK_ID>" | jq .
```

**Respuesta (ejemplo):**
```json
{
  "task_id": "019b...abc",
  "status": "processing",
  "partial_videos": [
    { "image_url": "...", "status": "completed" },
    { "image_url": "...", "status": "pending" }
  ]
}
```

Estados típicos:
- task: `pending | processing | completed | failed`
- partial: `pending | completed | failed`

---

### GET /api/tasks/{id}/final

Devuelve la URL del vídeo final si ya existe.

```bash
curl -s "http://localhost:8080/api/tasks/<TASK_ID>/final" | jq .
```

**Cuando no está listo:**
```json
{
  "task_id": "019b...abc",
  "status": "processing",
  "final_video_url": null
}
```

**Cuando está listo:**
```json
{
  "task_id": "019b...abc",
  "status": "completed",
  "final_video_url": "http://localhost:8080/videos/final_<TASK_ID>.mp4"
}
```

---

### Servir vídeos

Los vídeos se sirven desde:

- `http://localhost:8080/videos/partial_<partial_id>.mp4`
- `http://localhost:8080/videos/final_<task_id>.mp4`

Comprobar por HEAD:

```bash
curl -I "http://localhost:8080/videos/final_<TASK_ID>.mp4"
```

---

## Instrucciones para probar el proyecto

1) Arranca el stack:

```bash
docker compose up -d --build
```

2) Crea una task:

```bash
TASK_ID=$(
  curl -s -X POST "http://localhost:8080/api/tasks" \
    -H "Content-Type: application/json" \
    -d '{"images":[
      {"url":"https://www.tuexperto.com/wp-content/uploads/2020/01/png.jpg","transition":"zoom_in"},
      {"url":"https://www.tuexperto.com/wp-content/uploads/2020/01/png.jpg","transition":"pan"}
    ]}' | python -c "import sys, json; print(json.load(sys.stdin)['task_id'])"
)
echo "TASK_ID=$TASK_ID"
```

3) Poll hasta que esté completado:

```bash
while true; do
  RESP=$(curl -s "http://localhost:8080/api/tasks/$TASK_ID/final")
  STATUS=$(echo "$RESP" | python -c "import sys, json; print(json.load(sys.stdin)['status'])")
  URL=$(echo "$RESP" | python -c "import sys, json; print(json.load(sys.stdin)['final_video_url'])")
  echo "status=$STATUS url=$URL"

  if [ "$STATUS" = "completed" ] && [ "$URL" != "None" ] && [ "$URL" != "null" ]; then
    echo "Final listo: $URL"
    break
  fi

  if [ "$STATUS" = "failed" ]; then
    echo "Task failed: $RESP"
    break
  fi

  sleep 2
done
```

4) Abre la URL final en el navegador.

---

## Logs y depuración

Logs del worker (procesamiento real):

```bash
docker compose logs -f worker
```

Logs de PHP/Nginx:

```bash
docker compose logs -f php
docker compose logs -f nginx
```

Listar ficheros generados:

```bash
docker exec -it floorfy-technical-test-worker-1 sh -lc "ls -lh /var/www/html/public/videos | tail -n 50"
```

---

## Base de datos

Entrar a MySQL (si necesitas inspeccionar):

```bash
docker exec -it floorfy-technical-test-mysql-1 sh -lc 'mysql -uapp -p"$MYSQL_PASSWORD" app'
```

Consultar últimas tasks (si hay tablas `video_tasks` / `partial_videos`):

```bash
docker exec -it floorfy-technical-test-php-1 sh -lc \
'php bin/console doctrine:query:sql "SELECT id,status,error_message,final_video_url,created_at FROM video_tasks ORDER BY created_at DESC LIMIT 10"'
```

---

## RabbitMQ

UI:
- `http://localhost:15672` (guest/guest)

Ver colas y mensajes:

```bash
docker exec -it floorfy-technical-test-rabbitmq-1 sh -lc \
"rabbitmqctl list_queues name messages_ready messages_unacknowledged"
```

---

## Troubleshooting

### 1) El worker no consume mensajes
- Revisa logs:
  ```bash
  docker compose logs -f worker
  ```
- Comprueba que RabbitMQ está levantado:
  ```bash
  docker compose logs -f rabbitmq
  ```

### 2) “Could not connect to the AMQP server”
El DSN de messenger debe apuntar al host `rabbitmq` dentro de la red Docker (no `localhost`).

Revisa dentro del worker:
```bash
docker exec -it floorfy-technical-test-worker-1 sh -lc \
'php -r "echo getenv(\"MESSENGER_TRANSPORT_DSN\").PHP_EOL;"'
```

### 3) “Directorio no escribible: /var/www/html/public/videos”
Asegúrate de usar volúmenes Docker (`videos_data`) y recrea:

```bash
docker compose down -v
docker compose up -d --build
```

Comprobación de escritura como uid 1000:

```bash
docker exec --user 1000:1000 -it floorfy-technical-test-worker-1 sh -lc \
'touch /var/www/html/public/videos/_write_test && echo OK || echo FAIL'
```

---

## Comandos útiles

Rebuild completo:

```bash
docker compose down -v
docker compose up -d --build
```

Entrar a contenedores:

```bash
docker exec -it floorfy-technical-test-php-1 sh
docker exec -it floorfy-technical-test-worker-1 sh
```

Ver todos los servicios:

```bash
docker compose ps
```

---

## Licencia

Proyecto para prueba técnica.

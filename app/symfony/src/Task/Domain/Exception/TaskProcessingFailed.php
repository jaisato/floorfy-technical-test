<?php

declare(strict_types=1);

namespace App\Task\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * A processing attempt that did not produce a final video.
 *
 * The message is deliberately short: it ends up in `video_tasks.error_message`
 * and is handed to API clients, so tool output (ffmpeg writes hundreds of lines
 * of banner and stream detail to stderr) belongs in the log instead.
 */
final class TaskProcessingFailed extends DomainException
{
    public static function partialsFailed(int $failed, int $total): self
    {
        return new self(\sprintf('No se pudieron generar %d de %d vídeos parciales.', $failed, $total));
    }

    public static function noPartials(): self
    {
        return new self('La tarea no tiene imágenes que procesar.');
    }

    public static function compositionFailed(): self
    {
        return new self('No se pudo componer el vídeo final a partir de los parciales.');
    }

    public static function outputNotWritable(string $directory): self
    {
        return new self(\sprintf('El directorio de salida no es escribible: %s', $directory));
    }
}

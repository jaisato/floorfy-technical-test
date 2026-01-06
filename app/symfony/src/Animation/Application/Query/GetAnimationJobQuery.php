<?php
declare(strict_types=1);

namespace App\Animation\Application\Query;

use App\Shared\Domain\Bus\Query;

final readonly class GetAnimationJobQuery implements Query
{
    public function __construct(public string $id)
    {
    }
}

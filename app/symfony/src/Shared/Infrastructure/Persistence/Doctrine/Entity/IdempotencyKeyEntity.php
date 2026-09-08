<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Idempotency-Key a caller used, with the request it named and the answer
 * it got. Mapped so that the test schema, which is built from the mapping,
 * carries the table; the store itself works through DBAL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idempotency_keys')]
// The purge of expired keys runs on every claim.
#[ORM\Index(columns: ['expires_at'], name: 'idx_idempotency_keys_expires_at')]
class IdempotencyKeyEntity
{
    /**
     * Who used the key: the authenticated client, or "anonymous".
     *
     * Compared byte for byte, like the key beside it. Under the table's
     * utf8mb4_unicode_ci, clients configured as `Acme` and `acme` were one
     * scope and could replay each other's stored responses. Declared here as
     * well as in the migration because Doctrine puts the connection's default
     * collation on the mapping side, so leaving it out makes
     * doctrine:schema:validate report the column as changed.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 190, options: ['collation' => 'utf8mb4_bin'])]
    public string $scope;

    /** An opaque token the caller chose; `ABC` and `abc` are different keys. */
    #[ORM\Id]
    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 255, options: ['collation' => 'utf8mb4_bin'])]
    public string $idempotencyKey;

    /** SHA-256 of method, path and canonical body. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $fingerprint;

    /** Null while the original request is still running. */
    #[ORM\Column(name: 'response_status', type: Types::SMALLINT, nullable: true)]
    public ?int $responseStatus = null;

    #[ORM\Column(name: 'response_content_type', type: Types::STRING, length: 255, nullable: true)]
    public ?string $responseContentType = null;

    #[ORM\Column(name: 'response_body', type: Types::TEXT, nullable: true)]
    public ?string $responseBody = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $expiresAt;
}

<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Amp\Http\HttpStatus;

final readonly class RpcRejection
{
    private const string INVALID_PUBKEY = 'Invalid pubkey';

    private function __construct(
        private string $message,
        private int $status,
    ) {
    }

    public static function badRequest(string $message): self
    {
        return new self($message, HttpStatus::BAD_REQUEST);
    }

    public static function unauthorised(string $message): self
    {
        return new self($message, HttpStatus::UNAUTHORIZED);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, HttpStatus::FORBIDDEN);
    }

    public static function conflict(string $message): self
    {
        return new self($message, HttpStatus::CONFLICT);
    }

    public static function serverError(): self
    {
        return new self('internal server error', HttpStatus::INTERNAL_SERVER_ERROR);
    }

    public static function invalidPubkey(): self
    {
        return self::badRequest(self::INVALID_PUBKEY);
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

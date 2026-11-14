<?php
declare(strict_types=1);

namespace WebringHub;

use Exception;
use Throwable;

final class AppError extends Exception
{
    public int $status;
    public string $errorCode;
    public ?string $details;

    public function __construct(int $status, string $errorCode, string $message, ?string $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public static function fromThrowable(Throwable $err, string $fallbackMessage = 'Something went wrong.'): self
    {
        if ($err instanceof self) {
            return $err;
        }
        return new self(500, 'internal_error', $fallbackMessage, $err->getMessage());
    }
}

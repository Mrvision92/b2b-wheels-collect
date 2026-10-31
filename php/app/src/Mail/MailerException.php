<?php

declare(strict_types=1);

namespace Bwc\Mail;

final class MailerException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}

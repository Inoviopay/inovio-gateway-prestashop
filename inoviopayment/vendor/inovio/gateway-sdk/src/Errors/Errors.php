<?php

declare(strict_types=1);

namespace Inovio\Gateway\Errors;

use RuntimeException;
use Throwable;

/**
 * Exception hierarchy (object model §3.7).
 *
 * A DECLINE IS NEVER THROWN (Q1). A declined transaction returns normally as a
 * TransactionResult with status DECLINED, carrying the full outcome/AVS/CVV
 * detail. Exceptions mean "your request never got a payment answer", not "the
 * answer was no".
 */
class InovioException extends RuntimeException
{
    /** @var array<string,string> */
    private array $raw;

    /** @param array<string,string> $raw */
    public function __construct(string $message, array $raw = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->raw = $raw;
    }

    /** @return array<string,string> every field the gateway returned, verbatim */
    public function raw(): array
    {
        return $this->raw;
    }
}

/** API tier 100-106 — bad credentials, inactive user, bad site/service. */
class AuthenticationException extends InovioException
{
    private ?int $code_;

    /** @param array<string,string> $raw */
    public function __construct(string $message, ?int $code = null, array $raw = [])
    {
        parent::__construct($message, $raw);
        $this->code_ = $code;
    }

    public function apiCode(): ?int
    {
        return $this->code_;
    }
}

/** Client-side, or API 110-120 — a missing/invalid field; refField names it. */
class ValidationException extends InovioException
{
    private ?int $code_;
    private ?string $refField;

    /** @param array<string,string> $raw */
    public function __construct(
        string $message,
        ?int $code = null,
        ?string $refField = null,
        array $raw = []
    ) {
        parent::__construct($message, $raw);
        $this->code_ = $code;
        $this->refField = $refField;
    }

    public function apiCode(): ?int
    {
        return $this->code_;
    }

    /** The offending wire field, when the gateway named one. */
    public function refField(): ?string
    {
        return $this->refField;
    }
}

/** Currency/product/merchant-account not configured (155, 165, 210, 500...). */
class ConfigurationException extends InovioException
{
    private ?int $code_;

    /** @param array<string,string> $raw */
    public function __construct(string $message, ?int $code = null, array $raw = [])
    {
        parent::__construct($message, $raw);
        $this->code_ = $code;
    }

    public function apiCode(): ?int
    {
        return $this->code_;
    }
}

/** Network-level failure — the request may or may not have been processed. */
class TransportException extends InovioException
{
}

/**
 * The gateway did not answer in time. THE TRANSACTION STATE IS UNKNOWN — it may
 * still have been approved.
 *
 * Carries the idempotency key so the caller can resolve the true state with
 * client->status(...) rather than blindly retrying and double-charging.
 */
class GatewayTimeoutException extends TransportException
{
    private int $timeoutMs;
    private ?string $xtlOrderId;

    public function __construct(string $message, int $timeoutMs, ?string $xtlOrderId = null)
    {
        parent::__construct($message);
        $this->timeoutMs = $timeoutMs;
        $this->xtlOrderId = $xtlOrderId;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function xtlOrderId(): ?string
    {
        return $this->xtlOrderId;
    }

    /** Guidance surfaced on the error itself, since this is the trap case. */
    public function recoveryHint(): string
    {
        if ($this->xtlOrderId !== null) {
            return 'Transaction state is UNKNOWN. Resolve it with $client->status('
                . 'Refs::xtlOrder("' . $this->xtlOrderId . '")) before retrying — '
                . 'a blind retry may double-charge.';
        }

        return 'Transaction state is UNKNOWN. No idempotency key was set, so the state '
            . 'cannot be resolved by key; set idempotency on future requests.';
    }
}

/** API 100 — throttled. */
class RateLimitException extends InovioException
{
}

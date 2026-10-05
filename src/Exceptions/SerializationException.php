<?php

declare(strict_types=1);

namespace Marko\Queue\Exceptions;

use Throwable;

class SerializationException extends QueueException
{
    public static function invalidJobData(
        string $reason,
    ): self {
        return new self(
            message: 'Invalid job data: cannot deserialize job payload.',
            context: "Deserialization failed: $reason",
            suggestion: 'Ensure the job data was serialized correctly and the payload is not corrupted.',
        );
    }

    public static function unserializableClosure(
        string $jobClass,
    ): self {
        return new self(
            message: 'Job contains an unserializable closure.',
            context: "Job class '$jobClass' contains a Closure that cannot be serialized.",
            suggestion: 'Remove the Closure from the job or convert it to an invokable class.',
        );
    }

    public static function unserializableEvent(
        string $observerClass,
        string $eventClass,
        Throwable $previous,
    ): self {
        return new self(
            message: "Cannot queue async observer $observerClass: its event could not be serialized.",
            context: "Serializing event '$eventClass' for an AsyncObserverJob: {$previous->getMessage()}",
            suggestion: 'Async observers receive a serialized copy of the event. Keep closures, resources and live '
                . 'connections out of the event (pass IDs or plain values instead), or remove async: true from the observer.',
            previous: $previous,
        );
    }

    public static function signatureMismatch(): self
    {
        return new self(
            message: 'Job payload HMAC signature does not match — possible tampering or data corruption.',
            context: 'Verifying HMAC-SHA256 signature of queue job envelope.',
            suggestion: 'Do not modify queue payloads directly. Ensure all writers use the same app key.',
        );
    }

    public static function emptySigningKey(): self
    {
        return new self(
            message: 'Cannot sign or verify queue job payload: the encryption key is empty.',
            context: 'Reading encryption.key from config for HMAC signing.',
            suggestion: 'Set the ENCRYPTION_KEY environment variable before starting the queue worker.',
        );
    }
}

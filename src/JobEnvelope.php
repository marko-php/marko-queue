<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Encryption\Config\EncryptionConfig;
use Marko\Queue\Exceptions\SerializationException;

/**
 * HMAC-signed, transport-safe wrapper around serialized queue payloads.
 *
 * Format: {64-char-hex-hmac}.b64:{base64(serialized)}
 *
 * PHP's serialize() emits NUL bytes for private and protected properties, which
 * PostgreSQL TEXT columns reject. Base64 keeps the envelope 7-bit clean for every
 * storage backend. The HMAC covers the whole segment after the separator, so the
 * "b64:" marker is authenticated too.
 *
 * The HMAC key is a subkey derived from the app encryption key with HKDF, so the
 * key that encrypts data is never also used to sign queue payloads.
 */
readonly class JobEnvelope
{
    /**
     * HKDF info string that scopes the derived subkey to queue envelopes.
     */
    public const string KEY_PURPOSE = 'marko-queue-envelope';

    private const string BASE64_MARKER = 'b64:';

    public function __construct(
        private EncryptionConfig $encryptionConfig,
    ) {}

    /**
     * Wrap a serialized job payload in an HMAC-signed, base64-encoded envelope.
     *
     * @throws SerializationException when the signing key is empty
     */
    public function wrap(
        string $serialized,
    ): string {
        $key = $this->signingKey();
        $body = self::BASE64_MARKER . base64_encode($serialized);

        return hash_hmac('sha256', $body, $key) . '.' . $body;
    }

    /**
     * Verify an HMAC-signed envelope and return the inner serialized bytes.
     *
     * @throws SerializationException when the envelope does not verify, is malformed, or the key is empty
     */
    public function verifyAndUnwrap(
        string $envelope,
    ): string {
        $key = $this->signingKey();

        $hmac = substr($envelope, 0, 64);
        $separator = substr($envelope, 64, 1);
        $body = substr($envelope, 65);

        if ($separator !== '.') {
            throw SerializationException::signatureMismatch();
        }

        if (!hash_equals(hash_hmac('sha256', $body, $key), $hmac)) {
            throw SerializationException::signatureMismatch();
        }

        if (!str_starts_with($body, self::BASE64_MARKER)) {
            throw SerializationException::invalidJobData('the envelope body is missing the b64: marker');
        }

        $decoded = base64_decode(substr($body, strlen(self::BASE64_MARKER)), true);

        if ($decoded === false) {
            throw SerializationException::invalidJobData('the envelope body is not valid base64');
        }

        return $decoded;
    }

    /**
     * Derive the queue-envelope HMAC subkey from the app encryption key.
     *
     * @throws SerializationException when the signing key is empty
     */
    private function signingKey(): string
    {
        $key = $this->encryptionConfig->key();

        if ($key === '') {
            throw SerializationException::emptySigningKey();
        }

        return hash_hkdf('sha256', $key, 32, self::KEY_PURPOSE);
    }
}

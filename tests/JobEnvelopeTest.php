<?php

declare(strict_types=1);

use Marko\Encryption\Config\EncryptionConfig;
use Marko\Queue\Exceptions\SerializationException;
use Marko\Queue\JobEnvelope;
use Marko\Queue\Tests\Fixtures\PrivatePropertyJob;
use Marko\Testing\Fake\FakeConfigRepository;

function createJobEnvelope(
    string $key = 'test-key-for-hmac-verification',
): JobEnvelope {
    $config = new EncryptionConfig(new FakeConfigRepository(['encryption.key' => $key]));

    return new JobEnvelope($config);
}

describe('JobEnvelope', function (): void {
    it('wraps a serialized job in an HMAC-signed envelope', function (): void {
        $envelope = createJobEnvelope();
        $serialized = 'O:8:"TestJob":1:{s:7:"message";s:4:"test";}';

        $wrapped = $envelope->wrap($serialized);

        expect($wrapped)->toBeString()
            ->and($wrapped[64])->toBe('.');
    });

    it('wraps payloads in the b64 envelope format', function (): void {
        $envelope = createJobEnvelope();
        $serialized = 'O:8:"TestJob":1:{s:7:"message";s:4:"test";}';
        $body = 'b64:' . base64_encode($serialized);

        $wrapped = $envelope->wrap($serialized);

        expect(substr($wrapped, 65))->toBe($body)
            ->and(substr($wrapped, 0, 64))->toBe(hash_hmac('sha256', $body, 'test-key-for-hmac-verification'));
    });

    it('produces envelopes without NUL bytes for objects with private and protected properties', function (): void {
        $envelope = createJobEnvelope();
        $serialized = serialize(new PrivatePropertyJob('secret', 'shared'));

        expect(str_contains($serialized, "\0"))->toBeTrue()
            ->and(str_contains($envelope->wrap($serialized), "\0"))->toBeFalse();
    });

    it('round-trips a new-format envelope back to the original serialized bytes', function (): void {
        $envelope = createJobEnvelope();
        $serialized = serialize(new PrivatePropertyJob('secret', 'shared'));

        expect($envelope->verifyAndUnwrap($envelope->wrap($serialized)))->toBe($serialized);
    });

    it('still verifies and unwraps legacy raw envelopes', function (): void {
        $envelope = createJobEnvelope();
        $serialized = serialize(new PrivatePropertyJob('secret', 'shared'));
        $legacy = hash_hmac('sha256', $serialized, 'test-key-for-hmac-verification') . '.' . $serialized;

        expect($envelope->verifyAndUnwrap($legacy))->toBe($serialized);
    });

    it('rejects a new-format envelope whose base64 was tampered with', function (): void {
        $envelope = createJobEnvelope();
        $wrapped = $envelope->wrap('O:8:"TestJob":1:{s:7:"message";s:4:"test";}');
        $tampered = substr($wrapped, 0, 69) . base64_encode('O:7:"EvilJob":0:{}');

        expect(fn () => $envelope->verifyAndUnwrap($tampered))
            ->toThrow(SerializationException::class);
    });

    it(
        'rejects a new-format envelope whose b64 body is not valid base64 even with a valid signature',
        function (): void {
            $envelope = createJobEnvelope();
            $body = 'b64:not*valid*base64!';
            $forged = hash_hmac('sha256', $body, 'test-key-for-hmac-verification') . '.' . $body;

            expect(fn () => $envelope->verifyAndUnwrap($forged))
                ->toThrow(SerializationException::class, 'Invalid job data');
        },
    );

    it('verifies and unwraps a legitimately signed envelope', function (): void {
        $envelope = createJobEnvelope();
        $serialized = 'O:8:"TestJob":1:{s:7:"message";s:4:"test";}';

        $wrapped = $envelope->wrap($serialized);
        $unwrapped = $envelope->verifyAndUnwrap($wrapped);

        expect($unwrapped)->toBe($serialized);
    });

    it('throws SerializationException when the envelope HMAC does not verify', function (): void {
        $envelope = createJobEnvelope();
        $fakeHmac = str_repeat('a', 64);
        $payload = $fakeHmac . '.O:8:"TestJob":1:{s:7:"message";s:4:"test";}';

        expect(fn () => $envelope->verifyAndUnwrap($payload))
            ->toThrow(SerializationException::class);
    });

    it('refuses to unwrap a payload that has been tampered with', function (): void {
        $envelope = createJobEnvelope();
        $serialized = 'O:8:"TestJob":1:{s:7:"message";s:4:"test";}';

        $wrapped = $envelope->wrap($serialized);

        // Tamper with the payload part (after the HMAC)
        $tampered = substr($wrapped, 0, 65) . 'O:8:"EvilJob":0:{}';

        expect(fn () => $envelope->verifyAndUnwrap($tampered))
            ->toThrow(SerializationException::class);
    });

    it('throws loudly when the signing key is empty', function (): void {
        $envelope = createJobEnvelope(key: '');

        expect(fn () => $envelope->wrap('O:8:"TestJob":0:{}'))
            ->toThrow(SerializationException::class);
    });
});

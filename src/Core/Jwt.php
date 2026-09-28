<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\UnauthorizedException;
use JsonException;

/**
 * HS256 JSON Web Tokens, hand-rolled.
 *
 * WHY NOT A LIBRARY
 * A JWT is three base64url segments, an HMAC and a comparison. Depending on a
 * package for that would be a defensible choice in a normal project; here the
 * security review (`docs/SECURITY.md` §5) needs to be able to read exactly what
 * validates a token, and a third-party verifier with its own algorithm
 * negotiation is the classic source of the `alg: none` and RS256/HS256
 * confusion bugs. No algorithm is ever read from the token — it is always HS256.
 *
 * THE RULES THAT MATTER
 *  - `hash_equals`, not `===`, for the signature. Constant-time comparison is
 *    the entire point; a `===` on a MAC is a timing oracle.
 *  - `typ` and `alg` are both verified, so a token minted for another purpose
 *    cannot be replayed here.
 *  - Lifetime is 15 minutes, which bounds how long a demoted or suspended
 *    account keeps working.
 *  - Refresh tokens are NOT JWTs. They are opaque random strings stored hashed,
 *    because they must be individually revocable — a self-contained token
 *    cannot be. See RefreshTokenRepository.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    /** Access token lifetime in seconds. Short, because it cannot be revoked. */
    public const ACCESS_TTL = 900;

    /** Tolerance for clock drift between the app server and the client. */
    private const CLOCK_SKEW = 30;

    public function __construct(private readonly string $signingKey)
    {
        if (strlen($this->signingKey) < 32) {
            // Refusing here is better than signing with a guessable key. This is
            // the same condition as Config::assertProductionSafe(), checked again
            // so a key injected directly into the constructor is also covered.
            throw new UnauthorizedException('The token signing key is too short to be safe.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims, ?int $now = null): string
    {
        $issuedAt = $now ?? time();

        $payload = array_merge($claims, [
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + self::ACCESS_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $header = ['alg' => self::ALGORITHM, 'typ' => 'JWT'];
        $segments = [
            self::base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $segments[] = self::base64UrlEncode($this->sign($signingInput));

        return implode('.', $segments);
    }

    /**
     * Verify and decode.
     *
     * @return array<string, mixed>
     * @throws UnauthorizedException on any failure — the message never says which.
     */
    public function verify(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnauthorizedException();
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expected = $this->sign($encodedHeader . '.' . $encodedPayload);
        $actual = self::base64UrlDecode($encodedSignature);
        if ($actual === null || !hash_equals($expected, $actual)) {
            throw new UnauthorizedException();
        }

        try {
            $header = json_decode(
                self::base64UrlDecode($encodedHeader) ?? '',
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            $claims = json_decode(
                self::base64UrlDecode($encodedPayload) ?? '',
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new UnauthorizedException('The access token is not valid.', previous: $exception);
        }

        if (!is_array($header) || !is_array($claims)) {
            throw new UnauthorizedException();
        }

        if (($header['alg'] ?? null) !== self::ALGORITHM || ($header['typ'] ?? null) !== 'JWT') {
            throw new UnauthorizedException();
        }

        $now ??= time();
        $expiry = (int) ($claims['exp'] ?? 0);
        $notBefore = (int) ($claims['nbf'] ?? 0);

        if ($expiry <= 0 || $expiry + self::CLOCK_SKEW <= $now) {
            throw new UnauthorizedException('The access token has expired.');
        }

        if ($notBefore - self::CLOCK_SKEW > $now) {
            throw new UnauthorizedException();
        }

        return $claims;
    }

    /**
     * The lifetime to advertise to clients, in seconds.
     */
    public function ttl(): int
    {
        return self::ACCESS_TTL;
    }

    private function sign(string $input): string
    {
        return hash_hmac('sha256', $input, $this->signingKey, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}

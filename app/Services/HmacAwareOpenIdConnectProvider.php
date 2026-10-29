<?php

namespace App\Services;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use SocialiteProviders\OpenIDConnect\Provider;

class HmacAwareOpenIdConnectProvider extends Provider
{
    protected function verifyAndDecodeJWT(string $jwt, ?string $alg)
    {
        if ($alg === null || ! $this->isHmacAlgorithm($alg)) {
            return parent::verifyAndDecodeJWT($jwt, $alg);
        }

        $allowedAlgorithms = $this->getAllowedSigningAlgorithms();

        if (! in_array($alg, $allowedAlgorithms, true)) {
            throw new InvalidArgumentException(
                'JWT: Disallowed signing algorithm ['.$alg.']; expected one of ['.implode(', ', $allowedAlgorithms).'].',
                401,
            );
        }

        $previousLeeway = JWT::$leeway;

        try {
            JWT::$leeway = (int) ($this->getConfig('clock_skew') ?? 0);

            return json_decode(json_encode(JWT::decode($jwt, new Key($this->clientSecret, $alg))));
        } catch (Exception $exception) {
            throw new InvalidArgumentException('JWT: Verification failed - '.$exception->getMessage(), 401);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }
}

<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use RuntimeException;
use Throwable;

class FirebaseAccessTokenProvider
{
    private const MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?string $cachedAccessToken = null;

    private int $expiresAt = 0;

    public function accessToken(): string
    {
        if ($this->cachedAccessToken !== null && $this->expiresAt > time()) {
            return $this->cachedAccessToken;
        }

        $path = config('services.firebase.credentials_path');
        $projectId = config('services.firebase.project_id');

        if (! is_string($path) || $path === '' || ! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('Firebase server credentials are not configured.');
        }

        $resolvedPath = realpath($path);
        $publicPath = realpath(public_path());

        if (
            $resolvedPath === false
            || $publicPath === false
            || ! is_file($resolvedPath)
            || ! is_readable($resolvedPath)
            || $this->isWithinDirectory($resolvedPath, $publicPath)
        ) {
            throw new RuntimeException('Firebase server credentials are unavailable.');
        }

        try {
            $contents = file_get_contents($resolvedPath);
            if ($contents === false) {
                throw new RuntimeException('Firebase server credentials are unavailable.');
            }

            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Firebase server credentials are invalid.');
        }

        if (
            ! is_array($credentials)
            || ($credentials['type'] ?? null) !== 'service_account'
            || ($credentials['project_id'] ?? null) !== $projectId
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)
        ) {
            throw new RuntimeException('Firebase server credentials are invalid.');
        }

        try {
            $provider = new ServiceAccountCredentials([self::MESSAGING_SCOPE], $credentials);
            $token = $provider->fetchAuthToken();
        } catch (Throwable) {
            throw new RuntimeException('Firebase authorization is unavailable.');
        }

        if (! is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
            throw new RuntimeException('Firebase authorization is unavailable.');
        }

        $this->cachedAccessToken = $token['access_token'];
        $this->expiresAt = time() + max(30, (int) ($token['expires_in'] ?? 3600) - 60);

        return $this->cachedAccessToken;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        return str_starts_with(strtolower($path.'/'), strtolower($directory.'/'));
    }
}

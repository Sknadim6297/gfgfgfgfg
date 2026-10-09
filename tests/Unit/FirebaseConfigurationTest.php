<?php

namespace Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;

class FirebaseConfigurationTest extends TestCase
{
    public function test_firebase_configuration_reads_server_side_environment_values(): void
    {
        $environment = [
            'FIREBASE_PROJECT_ID' => 'notification-web-test',
            'FIREBASE_MESSAGING_SENDER_ID' => '123456789012',
            'FIREBASE_VAPID_PUBLIC_KEY' => 'test-public-vapid-key',
            'FIREBASE_CREDENTIALS_PATH' => 'C:\\private\\firebase\\service-account.json',
            'FIREBASE_API_KEY' => 'test-public-api-key',
            'FIREBASE_APP_ID' => '1:123456789012:web:test-app',
        ];

        $firebase = $this->withFirebaseEnvironment($environment, fn (): array => $this->loadFirebaseConfig());

        $this->assertSame([
            'project_id' => 'notification-web-test',
            'messaging_sender_id' => '123456789012',
            'vapid_public_key' => 'test-public-vapid-key',
            'credentials_path' => 'C:\\private\\firebase\\service-account.json',
            'api_key' => 'test-public-api-key',
            'app_id' => '1:123456789012:web:test-app',
        ], $firebase);
    }

    public function test_missing_firebase_configuration_is_null_without_loading_or_exposing_credentials(): void
    {
        $environment = [
            'FIREBASE_PROJECT_ID' => '',
            'FIREBASE_MESSAGING_SENDER_ID' => '',
            'FIREBASE_VAPID_PUBLIC_KEY' => '',
            'FIREBASE_CREDENTIALS_PATH' => '',
            'FIREBASE_API_KEY' => '',
            'FIREBASE_APP_ID' => '',
        ];

        $firebase = $this->withFirebaseEnvironment($environment, fn (): array => $this->loadFirebaseConfig());

        $this->assertSame([
            'project_id' => null,
            'messaging_sender_id' => null,
            'vapid_public_key' => null,
            'credentials_path' => null,
            'api_key' => null,
            'app_id' => null,
        ], $firebase);
        $this->assertArrayNotHasKey('credentials', $firebase);
        $this->assertArrayNotHasKey('private_key', $firebase);
    }

    private function loadFirebaseConfig(): array
    {
        $services = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'services.php';

        return $services['firebase'];
    }

    private function withFirebaseEnvironment(array $values, Closure $callback): mixed
    {
        $original = [];

        foreach ($values as $key => $value) {
            $original[$key] = [
                'env' => array_key_exists($key, $_ENV),
                'env_value' => $_ENV[$key] ?? null,
                'server' => array_key_exists($key, $_SERVER),
                'server_value' => $_SERVER[$key] ?? null,
                'process' => getenv($key),
            ];

            if ($value === '') {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($original as $key => $state) {
                if ($state['env']) {
                    $_ENV[$key] = $state['env_value'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server']) {
                    $_SERVER[$key] = $state['server_value'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($state['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['process']);
                }
            }
        }
    }
}

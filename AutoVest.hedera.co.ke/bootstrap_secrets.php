<?php

require __DIR__ . '/vendor/autoload.php';

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

function loadAwsSecrets(string $secretId, string $region): array
{
    static $cache = [];

    if (isset($cache[$secretId])) {
        return $cache[$secretId];
    }

    $client = new SecretsManagerClient([
        'version' => 'latest',
        'region'  => $region,
    ]);

    try {
        $result = $client->getSecretValue([
            'SecretId' => $secretId,
        ]);

        $secretString = $result['SecretString'] ?? null;

        if (!$secretString) {
            throw new RuntimeException("SecretString missing for {$secretId}");
        }

        $decoded = json_decode($secretString, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Secret {$secretId} is not valid JSON");
        }

        $cache[$secretId] = $decoded;
        return $decoded;

    } catch (AwsException $e) {
        throw new RuntimeException(
            'Failed to load AWS secret: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()),
            0,
            $e
        );
    }
}
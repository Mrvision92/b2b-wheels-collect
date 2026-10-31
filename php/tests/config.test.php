<?php
/**
 * Configuration des tests et de l'essai local : base MySQL locale (Docker,
 * voir README), mode démonstration. Aucun accès réel.
 */

return [
    'app_url' => getenv('BWC_TEST_URL') ?: 'http://localhost:3300',
    'app_secret' => 'tests-only-secret-not-for-production-0123456789',
    'demo' => true,
    'db' => [
        'host' => getenv('BWC_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('BWC_TEST_DB_PORT') ?: 3307),
        'name' => getenv('BWC_TEST_DB_NAME') ?: 'bwc_test',
        'user' => getenv('BWC_TEST_DB_USER') ?: 'bwc',
        'password' => getenv('BWC_TEST_DB_PASSWORD') ?: 'bwc-local',
    ],
];

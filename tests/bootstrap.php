<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

// OAuth keys of the MCP server (issue #276) for the functional tests, at the
// default paths of config/services.yaml. Created once, never committed.
(static function (string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if (!is_file($dir . '/private.key')) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate the OAuth test key pair.');
        }
        openssl_pkey_export($key, $private);
        file_put_contents($dir . '/private.key', $private);
        chmod($dir . '/private.key', 0600);
        file_put_contents($dir . '/public.key', openssl_pkey_get_details($key)['key']);
    }
    if (!is_file($dir . '/encryption.key')) {
        file_put_contents($dir . '/encryption.key', base64_encode(random_bytes(32)) . "\n");
    }
})(dirname(__DIR__) . '/var/oauth');

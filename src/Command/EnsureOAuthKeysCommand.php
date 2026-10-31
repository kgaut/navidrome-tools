<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates the OAuth keys of the MCP server when missing (issue #276):
 * the RSA pair signing access tokens (via the bundle's own command) and the
 * key encrypting authorization codes and refresh tokens. Idempotent, never
 * overwrites: run by the web container at boot. Losing these files only
 * invalidates the tokens issued so far (clients reconnect).
 */
#[AsCommand(name: 'app:oauth:ensure-keys', description: 'Génère les clés OAuth du serveur MCP si elles sont absentes.')]
class EnsureOAuthKeysCommand extends Command
{
    public function __construct(
        #[Autowire('%env(resolve:OAUTH_ENCRYPTION_KEY_FILE)%')]
        private readonly string $encryptionKeyFile,
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')]
        private readonly string $privateKeyFile,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \LogicException('Command must run inside the console application.');
        }
        $status = $application->find('league:oauth2-server:generate-keypair')
            ->run(new ArrayInput(['--skip-if-exists' => true]), $output);
        if ($status !== Command::SUCCESS) {
            return $status;
        }
        // The bundle writes the private key world-readable.
        if ($this->filesystem->exists($this->privateKeyFile)) {
            $this->filesystem->chmod($this->privateKeyFile, 0600);
        }

        if ($this->filesystem->exists($this->encryptionKeyFile)) {
            $output->writeln('Clé de chiffrement OAuth déjà présente.');

            return Command::SUCCESS;
        }
        $this->filesystem->dumpFile($this->encryptionKeyFile, base64_encode(random_bytes(32)) . "\n");
        $this->filesystem->chmod($this->encryptionKeyFile, 0600);
        $output->writeln(sprintf('Clé de chiffrement OAuth créée : %s', $this->encryptionKeyFile));

        return Command::SUCCESS;
    }
}

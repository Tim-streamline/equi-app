<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PowerSyncGenerateKeys extends Command
{
    protected $signature = 'powersync:generate-keys {--force : Overwrite an existing keypair}';

    protected $description = 'Generate the RSA keypair used to sign PowerSync JWTs';

    public function handle(): int
    {
        $privPath = config('powersync.private_key_path');
        $pubPath = config('powersync.public_key_path');

        if (! $this->option('force') && (file_exists($privPath) || file_exists($pubPath))) {
            $this->error('Keypair already exists. Re-run with --force to overwrite.');
            $this->line("  private: {$privPath}");
            $this->line("  public:  {$pubPath}");

            return self::FAILURE;
        }

        @mkdir(dirname($privPath), 0700, true);
        @mkdir(dirname($pubPath), 0755, true);

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (! $resource) {
            $this->error('openssl_pkey_new failed: '.openssl_error_string());

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);
        $publicPem = $details['key'];

        file_put_contents($privPath, $privatePem);
        // Use a permissive mode so both the artisan-running user and the
        // web-serving user can read the key (in Sail those are different).
        // For production, lock this down and align uids properly.
        chmod($privPath, 0644);
        file_put_contents($pubPath, $publicPem);
        chmod($pubPath, 0644);

        $this->info('Wrote keypair:');
        $this->line("  private: {$privPath}");
        $this->line("  public:  {$pubPath}");
        $this->line("  kid:     ".config('powersync.key_id'));

        return self::SUCCESS;
    }
}

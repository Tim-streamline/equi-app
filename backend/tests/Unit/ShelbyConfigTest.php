<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;

class ShelbyConfigTest extends TestCase
{
    public function test_missing_or_blank_shelby_key_falls_back_but_a_dedicated_key_takes_priority(): void
    {
        $repository = Env::getRepository();
        $names = ['SHELBY_OPENAI_API_KEY', 'FEED_SCANNER_OPENAI_API_KEY'];
        $original = array_map(fn ($name) => $repository->get($name), $names);
        try {
            // Synthetic keys only; never make provider requests in this test.
            $repository->set('FEED_SCANNER_OPENAI_API_KEY', 'scanner-test-key');
            foreach ([null, '', '   ', 'shelby-test-key'] as $key) {
                $key === null
                    ? $repository->clear('SHELBY_OPENAI_API_KEY')
                    : $repository->set('SHELBY_OPENAI_API_KEY', $key);
                $config = require __DIR__.'/../../config/shelby.php';
                $this->assertSame($key === 'shelby-test-key' ? $key : 'scanner-test-key', $config['openai']['api_key']);
            }
        } finally {
            foreach ($names as $index => $name) {
                $original[$index] === null ? $repository->clear($name) : $repository->set($name, $original[$index]);
            }
        }
    }
}

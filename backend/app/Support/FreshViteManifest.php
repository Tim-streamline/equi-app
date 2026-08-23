<?php

namespace App\Support;

use Illuminate\Foundation\Vite;

class FreshViteManifest extends Vite
{
    public function flush()
    {
        parent::flush();

        static::$manifests = [];
    }
}

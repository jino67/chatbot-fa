<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Toujours : le compte super admin. En local seulement : des donnees de demonstration.
     */
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}

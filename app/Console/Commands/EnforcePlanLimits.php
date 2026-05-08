<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

class EnforcePlanLimits extends Command
{
    protected $signature   = 'plans:enforce-limits';
    protected $description = 'Deactivate excess masters for shops that exceed their plan limit';

    public function handle(): int
    {
        $shops = Shop::whereNotNull('subscription_plan')->get();

        foreach ($shops as $shop) {
            $limits = $shop->getPlanLimits();
            $max    = $limits['max_masters'];

            if ($max === null) {
                continue;
            }

            $shop->enforceMasterLimit();
            $this->line("Shop [{$shop->name}]: max_masters={$max}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

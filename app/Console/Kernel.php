<?php

namespace App\Console;

use App\Jobs\SalesBonusCalculateJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')
        //          ->hourly();
        //$this->salesBonusCalculate($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    private function salesBonusCalculate(Schedule $schedule) {
        $schedule->job(SalesBonusCalculateJob::class)
            ->cron('0 19 * * *')
            //->everyFiveMinutes()
            ->name('sale_bonus_calculate')
            ->withoutOverlapping();
    }
}

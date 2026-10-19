<?php

namespace App\Providers;

use App\Http\Kernel;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;
use App\Models\Setting;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Kernel $kernel): void
    {
        Paginator::useBootstrapFive();
        Schema::defaultStringLength(191);
        $this->applyMailSettings();
    }

    /**
     * Use the SMTP details saved in Admin > Settings for every email the
     * panel sends (OTP, password reset, welcome, shipment status), including
     * mails sent from queued jobs and scheduled tracking commands.
     */
    protected function applyMailSettings(): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
            $s = Setting::whereIn('setting_name', [
                'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'email_from', 'application_name',
            ])->pluck('filed_value', 'setting_name')->toArray();

            if (empty($s['smtp_host']) || empty($s['smtp_user']) || empty($s['smtp_pass'])) {
                return; // fall back to .env
            }

            $port = (int) ($s['smtp_port'] ?? 587);
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp', [
                'transport'  => 'smtp',
                'host'       => trim($s['smtp_host']),
                'port'       => $port,
                'encryption' => $port === 465 ? 'ssl' : 'tls',
                'username'   => trim($s['smtp_user']),
                'password'   => str_replace(' ', '', $s['smtp_pass']), // Gmail app passwords are shown with spaces
                'timeout'    => 30,
                'local_domain' => env('MAIL_EHLO_DOMAIN'),
            ]);
            Config::set('mail.from', [
                'address' => $s['email_from'] ?: $s['smtp_user'],
                'name'    => $s['application_name'] ?? 'Shipxpeed',
            ]);
        } catch (\Throwable $e) {
            // DB not reachable (e.g. during install) - keep .env mail settings
        }
    }
}

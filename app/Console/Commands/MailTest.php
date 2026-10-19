<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    protected $signature = 'mail:test {email}';
    protected $description = 'Send a test email using the saved SMTP settings';

    public function handle(): int
    {
        $to = $this->argument('email');
        try {
            Mail::html('<p>Email is working on your Shipxpeed panel. OTP and shipment emails will be sent from this account.</p>',
                fn ($m) => $m->to($to)->subject('Shipxpeed test email'));
            $this->info('Sent to ' . $to . ' via ' . config('mail.mailers.smtp.host') . ' as ' . config('mail.from.address'));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

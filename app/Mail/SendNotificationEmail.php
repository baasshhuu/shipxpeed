<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendNotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;
    public $settings;

    public function __construct($data, $settings = [])
    {
        $this->data     = $data;
        $this->settings = $settings;
    }

    public function build()
    {
        $subject = $this->data['subject'] ?? (($this->settings['application_name'] ?? 'Shipxpeed') . ' notification');
        $body    = $this->data['body'] ?? ($this->data['message'] ?? '');

        $mail = $this->subject($subject)->html(nl2br(e(strip_tags($body))) ?: e($subject));

        if (!empty($this->data['attachment']) && is_file($this->data['attachment'])) {
            $mail->attach($this->data['attachment']);
        }
        return $mail;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\DemoMailConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DemoTestMail extends Command
{
    protected $signature = 'demo:test-mail';

    protected $description = 'Send a test email to the configured demo account';

    public function handle(): int
    {
        $recipient = config('app.test_user_email');
        $configurationError = DemoMailConfiguration::errorFor(
            is_string($recipient) ? $recipient : null
        );

        if ($configurationError !== null) {
            $this->error($configurationError);

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Ceci est un email de test de la configuration SMTP de la démonstration INSaf.',
                function ($message) use ($recipient): void {
                    $message->to($recipient)
                        ->subject('Test email — démonstration INSaf');
                }
            );
        } catch (Throwable $exception) {
            $reason = DemoMailConfiguration::safeExceptionMessage($exception);
            $this->error("Échec de l’envoi SMTP : {$reason}");

            return self::FAILURE;
        }

        $this->info('Email de test envoyé avec succès via SMTP.');

        return self::SUCCESS;
    }
}

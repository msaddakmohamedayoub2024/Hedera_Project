<?php

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

class DemoMailConfiguration
{
    public const INCOMPLETE_MESSAGE = 'Configuration email incomplète : remplissez MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS et TEST_USER_EMAIL dans backend/.env puis lancez php artisan config:clear';

    private const PLACEHOLDERS = [
        'votre-adresse',
        'votre-email',
        'user-mail',
        'user-email',
        'example',
    ];

    public static function errorFor(?string $recipient): ?string
    {
        $requiredValues = [
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.password'),
            config('mail.from.address'),
            config('app.test_user_email'),
        ];

        foreach ($requiredValues as $value) {
            if (! self::isConfiguredValue($value)) {
                return self::INCOMPLETE_MESSAGE;
            }
        }

        if (! self::isConfiguredValue($recipient)) {
            return self::INCOMPLETE_MESSAGE;
        }

        if (config('mail.default') !== 'smtp') {
            return 'Le mailer SMTP n’est pas activé. Configurez MAIL_MAILER=smtp dans backend/.env puis lancez php artisan config:clear.';
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return 'L’adresse email du propriétaire enregistrée dans la base de données est invalide.';
        }

        return null;
    }

    public static function isValidConfiguredEmail(?string $email): bool
    {
        return self::isConfiguredValue($email) &&
            filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function safeExceptionMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        foreach ([
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.url'),
        ] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[masqué]', $message);
            }
        }

        return Str::limit($message !== '' ? $message : 'Erreur SMTP inconnue.', 400);
    }

    private static function isConfiguredValue(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $normalized = strtolower($value);
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (str_contains($normalized, $placeholder)) {
                return false;
            }
        }

        return true;
    }
}

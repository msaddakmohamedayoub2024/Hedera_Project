<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\DemoMailConfiguration;
use Illuminate\Console\Command;

class DemoSyncTestUser extends Command
{
    protected $signature = 'demo:sync-test-user';

    protected $description = 'Sync only the CIN 12345677 test account email from TEST_USER_EMAIL';

    public function handle(): int
    {
        $email = config('app.test_user_email');
        if (! is_string($email) || ! DemoMailConfiguration::isValidConfiguredEmail($email)) {
            $this->error('TEST_USER_EMAIL est vide, invalide ou contient une valeur modèle dans backend/.env.');

            return self::FAILURE;
        }

        $student = Student::query()->where('cin', '12345677')->first();
        if ($student === null) {
            $this->error('Le compte de test CIN 12345677 est introuvable. Lancez d’abord php artisan migrate --seed.');

            return self::FAILURE;
        }

        $student->update(['email' => $email]);
        $this->info('L’adresse email du seul compte de test CIN 12345677 a été synchronisée.');

        return self::SUCCESS;
    }
}

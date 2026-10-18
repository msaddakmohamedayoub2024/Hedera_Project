<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\Application;
use App\Models\University;
use App\Services\Insaf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentAndApplicationSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42);
        $fictionalPwd = Hash::make('FictionalPassword123!');
        $universityIds = University::pluck('id')->all();

        $firstNames = ['Youssef', 'Mariam', 'Amine', 'Salma', 'Fares', 'Ines', 'Anis', 'Nour', 'Malek', 'Rania'];
        $familyNames = ['Trabelsi', 'Gharbi', 'Mansour', 'Jaziri', 'Khelifi', 'Saidi', 'Bouzid', 'Ayari', 'Haddad', 'Cherif'];

        // 1. Les 198 lignes d'étudiants d'arrière-plan + leurs 198 applications associées
        for ($i = 2; $i <= 199; $i++) {
            $code = sprintf('S%03d', $i);
            $index = $i - 2;

            $student = Student::firstOrCreate(
                ['code' => $code],
                [
                    'score' => mt_rand(800, 1900) / 100,
                    'cin' => (string) (10000000 + $i),
                    'email' => strtolower($code) . '@fictional-insaf.test',
                    'password' => $fictionalPwd,
                    'name_fr' => $firstNames[$index % count($firstNames)] . ' ' . $familyNames[$index % count($familyNames)],
                    'name_ar' => 'طالب افتراضي',
                    'institution_fr' => 'Lycée Pilote',
                    'institution_ar' => 'المعهد النموذجي',
                    'phone' => (string) (21000000 + $index),
                    'city' => 'Tunis',
                    'is_admin' => 0,
                ]
            );

            // Génération de l'application correspondante pour cet étudiant
            shuffle($universityIds);
            $choices = array_slice($universityIds, 0, 5);
            $salt = bin2hex(random_bytes(16));

            Application::firstOrCreate(
                ['student_id' => $student->id],
                [
                    'choices' => $choices,
                    'salt' => $salt,
                    'commitment' => Insaf::commit($code, $choices, $salt),
                ]
            );
        }

        // 2. Votre compte de Test Unique (Ce qui amène la table à l'état désiré)
        $ownerEmail = env('TEST_USER_EMAIL', 'owner.machine@insaf.test');

        $owner = Student::firstOrCreate(
            ['cin' => '123456789'],
            [
                'id'             => 201,
                'code'           => 'S201',
                'score'          => 17.52,
                'email'          => $ownerEmail,
                'password'       => Hash::make('insaf2026'),
                'name_fr'        => 'Owner Account',
                'name_ar'        => 'صاحب المشروع',
                'institution_fr' => 'Lycée Pilote de Gabès',
                'institution_ar' => 'المعهد النموذجي بقابس',
                'phone'          => '20202020',
                'city'           => 'Gabès',
                'is_admin'       => 0,
            ]
        );

        // Optionnel : Si vous souhaitez également lui associer une ligne d'application initiale
        shuffle($universityIds);
        $ownerChoices = array_slice($universityIds, 0, 5);
        $ownerSalt = bin2hex(random_bytes(16));

        Application::firstOrCreate(
            ['student_id' => $owner->id],
            [
                'choices' => $ownerChoices,
                'salt' => $ownerSalt,
                'commitment' => Insaf::commit('S201', $ownerChoices, $ownerSalt),
            ]
        );
    }
}

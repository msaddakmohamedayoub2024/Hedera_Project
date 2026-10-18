<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            ['name' => 'Médecine', 'capacity' => 15, 'minimum_score' => 16.50, 'description_fr' => 'Études médicales, sciences de la santé...', 'description_en' => 'Medical studies...'],
            ['name' => 'Informatique', 'capacity' => 30, 'minimum_score' => 13.50, 'description_fr' => 'Informatique, systèmes logiciels...', 'description_en' => 'Computer science...'],
            ['name' => 'Génie civil', 'capacity' => 25, 'minimum_score' => 12.50, 'description_fr' => 'Conception des structures...', 'description_en' => 'Structural design...'],
            ['name' => 'Pharmacie', 'capacity' => 15, 'minimum_score' => 15.00, 'description_fr' => 'Sciences pharmaceutiques...', 'description_en' => 'Pharmaceutical sciences...'],
            ['name' => 'Architecture', 'capacity' => 15, 'minimum_score' => 13.00, 'description_fr' => 'Conception architecturale...', 'description_en' => 'Architectural design...'],
            ['name' => 'Droit', 'capacity' => 30, 'minimum_score' => 11.00, 'description_fr' => 'Études juridiques...', 'description_en' => 'Legal studies...'],
            ['name' => 'Économie', 'capacity' => 25, 'minimum_score' => 10.00, 'description_fr' => 'Sciences économiques...', 'description_en' => 'Economics...'],
            ['name' => 'Lettres', 'capacity' => 25, 'minimum_score' => 9.00, 'description_fr' => 'Langues, littérature...', 'description_en' => 'Languages...'],
        ];

        foreach ($specialties as $data) {
            Program::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}

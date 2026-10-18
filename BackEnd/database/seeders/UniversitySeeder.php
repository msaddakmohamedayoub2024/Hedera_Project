<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\University;
use Illuminate\Database\Seeder;

class UniversitySeeder extends Seeder
{
    public function run(): void
    {
        $programs = Program::all();
        $cities = [
            ['Tunis', 'تونس'],
            ['Sfax', 'صفاقس'],
            ['Sousse', 'سوسة'],
            ['Monastir', 'المنستير'],
            ['Gabès', 'قابس'],
        ];

        $specialtyNamesEn = [
            'Médecine' => 'Medicine', 'Informatique' => 'Computer Science', 'Génie civil' => 'Civil Engineering',
            'Pharmacie' => 'Pharmacy', 'Architecture' => 'Architecture', 'Droit' => 'Law',
            'Économie' => 'Economics', 'Lettres' => 'Literature'
        ];

        foreach ($programs as $program) {
            foreach ($cities as $index => [$city]) {
                University::firstOrCreate([
                    'specialty_id' => $program->id,
                    'name_fr' => "Université de {$program->name} de {$city}"
                ], [
                    'name_en' => "{$city} {$specialtyNamesEn[$program->name]} University",
                    'description_fr' => "Université de démonstration proposant {$program->name}",
                    'description_en' => "Demo university offering {$specialtyNamesEn[$program->name]}",
                    'location_fr' => "{$city}, Tunisie",
                    'location_en' => "{$city}, Tunisia",
                    'minimum_score' => max(0, $program->minimum_score + (($index - 2) * 0.25)),
                    'capacity' => intdiv($program->capacity, count($cities)) + ($index < $program->capacity % count($cities) ? 1 : 0),
                ]);
            }
        }
    }
}

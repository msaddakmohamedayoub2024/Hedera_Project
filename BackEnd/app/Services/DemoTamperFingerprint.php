<?php

namespace App\Services;

use App\Models\Application;
use App\Models\TamperSimulation;

class DemoTamperFingerprint
{
    public static function merkleRootWith(TamperSimulation $simulation): string
    {
        $leaves = Application::with('student')->get()
            ->map(fn (Application $application) => Insaf::commit(
                $application->student->code,
                $application->student_id === $simulation->student_id
                    ? $simulation->simulated_choices
                    : $application->choices,
                $application->salt
            ))
            ->all();

        return Insaf::merkleRoot($leaves);
    }
}

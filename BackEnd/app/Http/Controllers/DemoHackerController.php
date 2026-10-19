<?php

namespace App\Http\Controllers;

use App\Mail\TamperAlertMail;
use App\Models\TamperSimulation;
use App\Services\Insaf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DemoHackerController extends Controller
{
    public function simulate(Request $request)
    {
        abort_unless(
            $this->demoModeEnabled(),
            403,
            'This action is only available in demo mode.'
        );

        $student = $request->user();
        $application = $student->application;
        if (
            ! $application ||
            $application->receipt_sequence === null ||
            ! is_array($application->receipt_choices) ||
            ! $application->receipt_salt ||
            ! $application->receipt_hash
        ) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'Aucun engagement de placement ancré n’est disponible pour ce compte.',
            ], 409);
        }
        if (! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'Aucune adresse email valide n’est enregistrée pour le propriétaire.',
            ], 409);
        }

        $topicId = $application->receipt_topic_id ?? config('services.hedera.topic_id');
        try {
            $anchorRecord = Insaf::readAnchorRecord(
                (int) $application->receipt_sequence,
                $topicId
            );
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'Le Mirror Node Hedera est indisponible. Aucun changement ni email n’a été effectué.',
            ], 503);
        } catch (\UnexpectedValueException $exception) {
            report($exception);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'Le Mirror Node Hedera a renvoyé un enregistrement invalide. Aucun changement ni email n’a été effectué.',
            ], 502);
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'La configuration du Mirror Node Hedera est invalide. Aucun changement ni email n’a été effectué.',
            ], 503);
        }

        if ($anchorRecord === null) {
            return response()->json([
                'status' => 'pending',
                'message' => 'L’engagement est en attente d’indexation sur Hedera. Aucun changement ni email n’a été effectué.',
            ], 202);
        }

        $anchoredMessage = $anchorRecord['message'] ?? null;
        $anchoredFingerprint = 'c:'.$application->receipt_hash;
        if (! is_string($anchoredMessage) || ! hash_equals($anchoredFingerprint, $anchoredMessage)) {
            return response()->json([
                'status' => 'chain_mismatch',
                'message' => 'L’empreinte de l’engagement ne correspond pas à celle lue sur Hedera. Aucun email n’a été envoyé.',
            ], 409);
        }

        $alteredChoices = array_map('intval', $application->receipt_choices);
        if (count($alteredChoices) < 2) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'Il faut au moins deux choix pour simuler une altération.',
            ], 409);
        }
        [$alteredChoices[0], $alteredChoices[1]] = [$alteredChoices[1], $alteredChoices[0]];

        $newFingerprint = Insaf::commit(
            $student->code,
            $alteredChoices,
            $application->receipt_salt
        );
        if (hash_equals($application->receipt_hash, $newFingerprint)) {
            return response()->json([
                'status' => 'verified',
                'message' => 'Les empreintes correspondent. Aucune alerte n’a été envoyée.',
            ]);
        }

        $simulation = TamperSimulation::firstOrNew(['student_id' => $student->id]);
        if ($simulation->exists && $simulation->alert_queued_at !== null) {
            return response()->json([
                'status' => 'data_changed',
                'message' => 'Une tentative de piratage simulée est déjà active. Réinitialisez la démo pour la rejouer.',
                'reportReference' => $simulation->report_reference,
                'emailQueued' => true,
            ]);
        }

        $simulation->fill([
            'simulated_choices' => $alteredChoices,
            'old_fingerprint' => $application->receipt_hash,
            'new_fingerprint' => $newFingerprint,
            'report_reference' => 'DEMO-'.Str::upper(Str::random(8)),
            'is_simulation' => true,
            'alert_queued_at' => null,
        ]);
        $simulation->save();

        Mail::to($student->email)->queue(new TamperAlertMail(
            $simulation->old_fingerprint,
            $simulation->new_fingerprint,
            $simulation->report_reference,
            $simulation->created_at
        ));

        $simulation->update(['alert_queued_at' => now()]);

        return response()->json([
            'status' => 'data_changed',
            'message' => 'Tentative de piratage détectée. Un email d’alerte a été mis en file d’envoi pour le propriétaire.',
            'reportReference' => $simulation->report_reference,
            'emailQueued' => true,
        ]);
    }

    public function reset(Request $request)
    {
        abort_unless(
            $this->demoModeEnabled(),
            403,
            'This action is only available in demo mode.'
        );

        TamperSimulation::where('student_id', $request->user()->id)->delete();

        return response()->json([
            'status' => 'reset',
            'message' => 'La simulation a été réinitialisée. Les données réelles sont inchangées.',
        ]);
    }

    private function demoModeEnabled(): bool
    {
        return app()->environment(['local', 'development', 'testing']) &&
            filter_var(config('services.hedera.demo_mode'), FILTER_VALIDATE_BOOLEAN);
    }
}

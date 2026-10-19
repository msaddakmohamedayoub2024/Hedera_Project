<?php

namespace App\Http\Controllers;

use App\Mail\TamperAlertMail;
use App\Models\Application;
use App\Models\Student;
use App\Models\TamperSimulation;
use App\Services\DemoMailConfiguration;
use App\Services\Insaf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class DemoHackerController extends Controller
{
    public function simulate(Request $request)
    {
        if (! $this->demoModeEnabled()) {
            return response()->json([
                'success' => false,
                'status' => 'demo_disabled',
                'message' => 'Mode démo désactivé côté serveur.',
            ], 403);
        }

        $student = $request->user();
        $mailConfigurationError = DemoMailConfiguration::errorFor($student->email);
        if ($mailConfigurationError !== null) {
            return response()->json([
                'success' => false,
                'status' => 'email_configuration_incomplete',
                'message' => $mailConfigurationError,
            ], 422);
        }

        try {
            $application = $this->ensureUserReceipt($student);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'status' => 'receipt_unavailable',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Demo HCS receipt creation failed.', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'status' => 'anchor_failed',
                'message' => 'Impossible de créer l’ancrage Hedera des choix actuels. Vérifiez le réseau, le topic et les identifiants HCS du backend.',
            ], 502);
        }

        $topicId = $application->receipt_topic_id ?? config('services.hedera.topic_id');
        $anchorRecord = null;
        $deadline = microtime(true) + 20;
        do {
            try {
                $anchorRecord = Insaf::readAnchorRecord(
                    (int) $application->receipt_sequence,
                    $topicId
                );
            } catch (ConnectionException|RequestException $exception) {
                Log::error('Demo HCS receipt lookup failed.', [
                    'exception_class' => $exception::class,
                ]);

                return response()->json([
                    'success' => false,
                    'status' => 'mirror_node_unavailable',
                    'message' => 'Le Mirror Node Hedera est indisponible. L’indexation de l’ancrage n’a pas pu être vérifiée et aucun email n’a été envoyé.',
                ], 503);
            } catch (\UnexpectedValueException $exception) {
                Log::error('Demo HCS receipt lookup returned invalid data.', [
                    'exception_class' => $exception::class,
                ]);

                return response()->json([
                    'success' => false,
                    'status' => 'invalid_mirror_record',
                    'message' => 'Le Mirror Node Hedera a renvoyé un enregistrement invalide. Aucun email n’a été envoyé.',
                ], 502);
            } catch (\RuntimeException $exception) {
                Log::error('Demo HCS receipt lookup is misconfigured.', [
                    'exception_class' => $exception::class,
                ]);

                return response()->json([
                    'success' => false,
                    'status' => 'mirror_node_misconfigured',
                    'message' => 'La configuration du Mirror Node Hedera est invalide. Aucun email n’a été envoyé.',
                ], 503);
            }

            if ($anchorRecord === null && microtime(true) < $deadline) {
                usleep(500_000);
            }
        } while ($anchorRecord === null && microtime(true) < $deadline);

        if ($anchorRecord === null) {
            return response()->json([
                'success' => false,
                'status' => 'pending',
                'message' => 'L’ancrage a été créé, mais il n’est toujours pas indexé par le Mirror Node après 20 secondes. Réessayez ultérieurement ; aucun email n’a été envoyé.',
            ], 503);
        }

        $anchoredMessage = $anchorRecord['message'] ?? null;
        $anchoredFingerprint = 'c:'.$application->receipt_hash;
        if (! is_string($anchoredMessage) || ! hash_equals($anchoredFingerprint, $anchoredMessage)) {
            return response()->json([
                'success' => false,
                'status' => 'chain_mismatch',
                'message' => 'L’empreinte de l’engagement ne correspond pas à celle lue sur Hedera. Aucun email n’a été envoyé.',
            ], 409);
        }

        $alteredChoices = array_map('intval', $application->receipt_choices);
        if (count($alteredChoices) < 2) {
            return response()->json([
                'success' => false,
                'status' => 'unavailable',
                'message' => 'Il faut au moins deux choix pour simuler une altération.',
            ], 422);
        }
        [$alteredChoices[0], $alteredChoices[1]] = [$alteredChoices[1], $alteredChoices[0]];

        $newFingerprint = Insaf::commit(
            $student->code,
            $alteredChoices,
            $application->receipt_salt
        );
        if (hash_equals($application->receipt_hash, $newFingerprint)) {
            return response()->json([
                'success' => false,
                'status' => 'verified',
                'message' => 'Les empreintes correspondent. Aucune alerte n’a été envoyée.',
            ], 409);
        }

        $simulation = TamperSimulation::firstOrNew(['student_id' => $student->id]);
        $simulation->fill([
            'simulated_choices' => $alteredChoices,
            'old_fingerprint' => $application->receipt_hash,
            'new_fingerprint' => $newFingerprint,
            'report_reference' => 'DEMO-'.Str::upper(Str::random(8)),
            'is_simulation' => true,
            'alert_queued_at' => null,
        ]);
        $simulation->save();

        try {
            Mail::to($student->email)->send(new TamperAlertMail(
                $simulation->old_fingerprint,
                $simulation->new_fingerprint,
                $simulation->report_reference,
                now()
            ));
        } catch (Throwable $exception) {
            $reason = DemoMailConfiguration::safeExceptionMessage($exception);
            Log::error('Demo tamper alert email could not be sent.', [
                'exception_class' => $exception::class,
                'reason' => $reason,
            ]);

            return response()->json([
                'success' => false,
                'status' => 'email_failed',
                'message' => "L’altération simulée a été détectée, mais l’email n’a pas pu être envoyé : {$reason}",
                'reportReference' => $simulation->report_reference,
            ], 500);
        }

        $simulation->update(['alert_queued_at' => now()]);

        return response()->json([
            'success' => true,
            'status' => 'data_changed',
            'message' => 'Tentative de piratage détectée. Un email d’alerte a été envoyé au propriétaire.',
            'reportReference' => $simulation->report_reference,
            'emailSent' => true,
        ]);
    }

    public function reset(Request $request)
    {
        if (! $this->demoModeEnabled()) {
            return response()->json([
                'success' => false,
                'status' => 'demo_disabled',
                'message' => 'Mode démo désactivé côté serveur.',
            ], 403);
        }

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

    private function ensureUserReceipt(Student $student): Application
    {
        return DB::transaction(function () use ($student): Application {
            $application = Application::query()
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($application === null) {
                throw new InvalidArgumentException('Aucun choix de placement enregistré ne peut être ancré.');
            }

            if (
                $application->receipt_sequence !== null &&
                is_array($application->receipt_choices) &&
                $application->receipt_salt &&
                $application->receipt_hash
            ) {
                return $application;
            }

            $existingReceiptFields = [
                $application->receipt_choices,
                $application->receipt_salt,
                $application->receipt_hash,
                $application->receipt_transaction_id,
                $application->receipt_network,
                $application->receipt_topic_id,
                $application->receipt_consensus_timestamp,
                $application->receipt_submitted_at,
            ];
            if (count(array_filter($existingReceiptFields, fn ($value) => $value !== null)) > 0) {
                throw new InvalidArgumentException('Les métadonnées du reçu de placement sont incomplètes ; aucun nouvel ancrage n’a été créé.');
            }

            $choices = array_map('intval', $application->choices ?? []);
            if (count($choices) < 2 || count(array_unique($choices)) !== count($choices)) {
                throw new InvalidArgumentException('Les choix enregistrés sont absents ou invalides ; aucun ancrage n’a été créé.');
            }
            if (! is_string($application->salt) || $application->salt === '') {
                throw new InvalidArgumentException('Le sel de l’empreinte est absent ; aucun ancrage n’a été créé.');
            }

            $fingerprint = Insaf::commit($student->code, $choices, $application->salt);
            $anchor = Insaf::anchor('c:'.$fingerprint);
            $submittedAt = now();

            $application->forceFill([
                'receipt_choices' => $choices,
                'receipt_salt' => $application->salt,
                'receipt_hash' => $fingerprint,
                'receipt_transaction_id' => $anchor['transactionId'],
                'receipt_network' => $anchor['network'],
                'receipt_topic_id' => $anchor['topicId'],
                'receipt_consensus_timestamp' => null,
                'receipt_sequence' => (int) $anchor['sequence'],
                'receipt_submitted_at' => $submittedAt,
            ])->save();

            return $application->fresh();
        });
    }
}

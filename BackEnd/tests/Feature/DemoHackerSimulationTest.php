<?php

namespace Tests\Feature;

use App\Mail\TamperAlertMail;
use App\Models\Commitment;
use App\Models\Student;
use App\Models\TamperSimulation;
use App\Models\University;
use App\Services\Insaf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoHackerSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hedera.network' => 'testnet',
            'services.hedera.topic_id' => '0.0.123',
            'services.hedera.mirror_node_url' => 'https://testnet.mirrornode.hedera.com',
            'services.hedera.demo_mode' => false,
        ]);
    }

    public function test_simulation_detects_tampering_alerts_owner_and_reset_restores_verification(): void
    {
        $this->seed();
        config(['services.hedera.demo_mode' => true]);

        [$student, $application] = $this->createAnchoredApplication();
        $applicationBefore = $application->only([
            'choices',
            'salt',
            'commitment',
            'receipt_choices',
            'receipt_salt',
            'receipt_hash',
            'receipt_sequence',
        ]);
        $merkleRoot = Insaf::preferencesMerkleRoot();
        Commitment::create([
            'kind' => 'merkle_root',
            'hash' => $merkleRoot,
            'hcs_tx_id' => '0.0.123@1700000000.000000088',
            'hcs_sequence' => 88,
            'hedera_network' => 'testnet',
            'hedera_topic_id' => '0.0.123',
        ]);
        $this->fakeAnchors($application->receipt_hash, $merkleRoot);
        Mail::fake();
        Process::fake();
        Sanctum::actingAs($student);

        $this->postJson('/api/demo/simulate-hacker')
            ->assertOk()
            ->assertJsonPath('status', 'data_changed')
            ->assertJsonPath('emailQueued', true);

        $simulation = TamperSimulation::where('student_id', $student->id)->firstOrFail();
        $this->assertTrue($simulation->is_simulation);
        $this->assertNotSame($simulation->old_fingerprint, $simulation->new_fingerprint);
        $this->assertNotNull($simulation->alert_queued_at);
        Mail::assertQueued(TamperAlertMail::class, fn (TamperAlertMail $mail) => $mail->oldFingerprint === $simulation->old_fingerprint &&
            $mail->newFingerprint === $simulation->new_fingerprint &&
            $mail->reportReference === $simulation->report_reference &&
            str_contains($mail->render(), 'DEMO SIMULATION')
        );
        Process::assertNothingRan();

        $application->refresh();
        $this->assertSame($applicationBefore, $application->only(array_keys($applicationBefore)));

        $this->getJson('/api/commitments/verify')
            ->assertOk()
            ->assertJsonPath('commitments.0.status', 'data_changed');

        $this->postJson('/api/demo/reset')
            ->assertOk()
            ->assertJsonPath('status', 'reset');
        $this->assertDatabaseMissing('tamper_simulations', ['student_id' => $student->id]);
        $this->getJson('/api/commitments/verify')
            ->assertOk()
            ->assertJsonPath('commitments.0.status', 'verified');
        Mail::assertQueued(TamperAlertMail::class, 1);
    }

    public function test_simulation_requires_demo_mode_and_an_anchored_receipt(): void
    {
        $this->seed();
        [$student] = $this->createAnchoredApplication(withReceipt: false);
        Mail::fake();
        Sanctum::actingAs($student);

        $this->postJson('/api/demo/simulate-hacker')->assertForbidden();
        config(['services.hedera.demo_mode' => true]);
        $this->postJson('/api/demo/simulate-hacker')
            ->assertStatus(409)
            ->assertJsonPath('status', 'unavailable');

        $this->assertDatabaseCount('tamper_simulations', 0);
        Mail::assertNothingSent();
    }

    private function createAnchoredApplication(bool $withReceipt = true): array
    {
        $student = Student::where('code', 'S201')->firstOrFail();
        $student->update(['email' => 'owner@example.test']);
        $choices = University::query()->orderBy('id')->limit(5)->pluck('id')->map(
            fn ($id) => (int) $id
        )->all();
        $salt = bin2hex(random_bytes(16));
        $fingerprint = Insaf::commit($student->code, $choices, $salt);
        $application = $student->application()->firstOrFail();
        $application->update([
            'choices' => $choices,
            'salt' => $salt,
            'commitment' => $fingerprint,
        ]);

        if ($withReceipt) {
            $application->update([
                'receipt_choices' => $choices,
                'receipt_salt' => $salt,
                'receipt_hash' => $fingerprint,
                'receipt_sequence' => 42,
                'receipt_transaction_id' => '0.0.123@1700000000.000000042',
                'receipt_network' => 'testnet',
                'receipt_topic_id' => '0.0.123',
                'receipt_submitted_at' => now(),
            ]);
        }

        return [$student, $application->fresh()];
    }

    private function fakeAnchors(string $receiptFingerprint, string $merkleRoot): void
    {
        Http::fake(function ($request) use ($receiptFingerprint, $merkleRoot) {
            $sequence = (int) basename(parse_url($request->url(), PHP_URL_PATH));
            $message = $sequence === 42
                ? 'c:'.$receiptFingerprint
                : 'merkle_root:'.$merkleRoot;

            return Http::response([
                'message' => base64_encode($message),
                'topic_id' => '0.0.123',
                'sequence_number' => $sequence,
                'consensus_timestamp' => '1700000000.000000001',
                'transaction_id' => '0.0.123@1700000000.'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT),
            ]);
        });
    }
}

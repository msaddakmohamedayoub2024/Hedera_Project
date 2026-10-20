<?php

namespace Tests\Feature;

use App\Mail\TamperAlertMail;
use App\Models\Commitment;
use App\Models\Student;
use App\Models\TamperSimulation;
use App\Models\University;
use App\Services\DemoMailConfiguration;
use App\Services\Insaf;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            'app.test_user_email' => 'owner@insaf.test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.username' => 'demo-smtp-user',
            'mail.mailers.smtp.password' => 'demo-smtp-password',
            'mail.from.address' => 'insaf@insaf.test',
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
            ->assertJsonPath('emailSent', true)
            ->assertJsonPath('success', true);

        $simulation = TamperSimulation::where('student_id', $student->id)->firstOrFail();
        $this->assertTrue($simulation->is_simulation);
        $this->assertNotSame($simulation->old_fingerprint, $simulation->new_fingerprint);
        $this->assertNotNull($simulation->alert_queued_at);
        Mail::assertSent(TamperAlertMail::class, fn (TamperAlertMail $mail) => $mail->oldFingerprint === $simulation->old_fingerprint &&
            $mail->newFingerprint === $simulation->new_fingerprint &&
            $mail->reportReference === $simulation->report_reference &&
            str_contains($mail->render(), 'SIMULATION DE DÉMONSTRATION')
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
        Mail::assertSent(TamperAlertMail::class, 1);
    }

    public function test_database_hacker_changes_preferences_detects_the_difference_and_reset_restores_them(): void
    {
        $this->seed();
        config(['services.hedera.demo_mode' => true]);
        [$student, $application] = $this->createAnchoredApplication();
        $originalChoices = array_map('intval', $application->receipt_choices);
        $originalHash = $application->receipt_hash;
        Http::fake([
            '*' => Http::response([
                'message' => base64_encode('c:'.$originalHash),
                'topic_id' => '0.0.123',
                'sequence_number' => 42,
                'consensus_timestamp' => '1700000000.000000001',
                'transaction_id' => $application->receipt_transaction_id,
            ]),
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/demo/simulate-database-hacker')
            ->assertOk()
            ->assertJsonPath('status', 'database_preferences_changed')
            ->assertJsonPath('beforeChoices', $originalChoices);

        $application->refresh();
        $alteredChoices = array_map('intval', $application->choices);
        $this->assertNotSame($originalChoices, $alteredChoices);
        $this->assertSame($originalChoices, array_map('intval', $application->receipt_choices));
        $this->assertSame($originalHash, $application->receipt_hash);
        $this->assertSame(
            Insaf::commit($student->code, $alteredChoices, $application->salt),
            $application->commitment
        );

        $this->postJson('/api/verify', [
            'code' => $student->code,
            'score' => $student->score,
            'choices' => $originalChoices,
            'salt' => $application->receipt_salt,
            'sequence' => $application->receipt_sequence,
            'tx' => $application->receipt_transaction_id,
        ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('checks.0.ok', true)
            ->assertJsonPath('checks.1.ok', false)
            ->assertJsonPath('incident.originalChoices', $originalChoices)
            ->assertJsonPath('incident.currentChoices', $alteredChoices);

        $this->postJson('/api/demo/reset')
            ->assertOk()
            ->assertJsonPath('status', 'reset');
        $application->refresh();
        $this->assertSame($originalChoices, array_map('intval', $application->choices));
        $this->assertSame(
            Insaf::commit($student->code, $originalChoices, $application->salt),
            $application->commitment
        );
    }

    public function test_simulation_creates_and_indexes_a_receipt_only_after_the_button_is_clicked(): void
    {
        $this->seed();
        config(['services.hedera.demo_mode' => true]);
        [$student, $application] = $this->createAnchoredApplication(withReceipt: false);
        Mail::fake();
        Process::fake([
            '*' => Process::result(json_encode([
                'transactionId' => '0.0.123@1700000000.000000042',
                'sequence' => 42,
                'topicId' => '0.0.123',
                'network' => 'testnet',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $fingerprint = Insaf::commit(
            $student->code,
            $application->choices,
            $application->salt
        );
        Http::fakeSequence()
            ->push([], 404)
            ->push([
                'message' => base64_encode('c:'.$fingerprint),
                'topic_id' => '0.0.123',
                'sequence_number' => 42,
                'consensus_timestamp' => '1700000000.000000001',
                'transaction_id' => '0.0.123@1700000000.000000042',
            ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/demo/simulate-hacker')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('emailSent', true);

        $application->refresh();
        $this->assertSame(42, (int) $application->receipt_sequence);
        $this->assertSame($application->choices, $application->receipt_choices);
        $this->assertSame($fingerprint, $application->receipt_hash);
        Mail::assertSent(TamperAlertMail::class, 1);
        Process::assertRanTimes(
            fn ($process) => in_array('send.js', (array) $process->command, true),
            1
        );
    }

    public function test_no_request_means_no_simulation_no_hcs_message_and_no_email(): void
    {
        $this->seed();
        Mail::fake();
        Process::fake();

        $this->assertDatabaseCount('tamper_simulations', 0);
        Mail::assertNothingOutgoing();
        Process::assertNothingRan();
    }

    public function test_mail_configuration_errors_are_returned_without_anchoring_or_simulation(): void
    {
        $this->seed();
        config([
            'services.hedera.demo_mode' => true,
            'mail.mailers.smtp.password' => '',
        ]);
        [$student] = $this->createAnchoredApplication();
        Mail::fake();
        Process::fake();
        Sanctum::actingAs($student);

        $this->postJson('/api/demo/simulate-hacker')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                DemoMailConfiguration::INCOMPLETE_MESSAGE
            );

        $this->assertDatabaseCount('tamper_simulations', 0);
        Mail::assertNothingOutgoing();
        Process::assertNothingRan();
    }

    public function test_smtp_failure_is_returned_and_credentials_are_redacted(): void
    {
        $this->seed();
        config(['services.hedera.demo_mode' => true]);
        [$student, $application] = $this->createAnchoredApplication();
        $this->fakeAnchors($application->receipt_hash, 'unused');
        Mail::shouldReceive('to')
            ->once()
            ->with($student->email)
            ->andThrow(new \RuntimeException(
                'SMTP authentication failed for demo-smtp-user with demo-smtp-password'
            ));
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/demo/simulate-hacker')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'email_failed');

        $this->assertStringNotContainsString('demo-smtp-password', $response->json('message'));
        $this->assertStringNotContainsString('demo-smtp-user', $response->json('message'));
        $this->assertDatabaseHas('tamper_simulations', [
            'student_id' => $student->id,
            'is_simulation' => true,
        ]);
    }

    public function test_sync_test_user_command_changes_only_the_test_account_email(): void
    {
        $this->seed();
        $testUser = Student::where('cin', '12345677')->firstOrFail();
        $otherUser = Student::where('code', 'S002')->firstOrFail();
        $otherEmail = $otherUser->email;
        config(['app.test_user_email' => 'owner-updated@insaf.test']);

        $this->assertSame(Command::SUCCESS, Artisan::call('demo:sync-test-user'));
        $this->assertSame('owner-updated@insaf.test', $testUser->fresh()->email);
        $this->assertSame($otherEmail, $otherUser->fresh()->email);
    }

    public function test_test_mail_command_uses_the_configured_test_user_recipient(): void
    {
        $recipient = 'owner@insaf.test';
        config(['app.test_user_email' => $recipient]);
        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(function (string $body, callable $configure) use ($recipient): bool {
                $message = new class
                {
                    public ?string $recipient = null;

                    public ?string $subjectLine = null;

                    public function to(string $recipient): self
                    {
                        $this->recipient = $recipient;

                        return $this;
                    }

                    public function subject(string $subject): self
                    {
                        $this->subjectLine = $subject;

                        return $this;
                    }
                };
                $configure($message);

                return str_contains(strtolower($body), 'test') &&
                    $message->recipient === $recipient &&
                    $message->subjectLine === 'Test email — démonstration INSaf';
            });

        $this->assertSame(Command::SUCCESS, Artisan::call('demo:test-mail'));
        $this->assertStringContainsString('envoyé avec succès', Artisan::output());
    }

    private function createAnchoredApplication(bool $withReceipt = true): array
    {
        $student = Student::where('code', 'S201')->firstOrFail();
        $student->update(['email' => 'owner@insaf.test']);
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

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AgentPoolSelector;
use App\Audit\AiRequestException;
use App\Audit\AuditCoordinator;
use App\Audit\CrossVerifier;
use App\Audit\NineRouterClient;
use App\Audit\PageResultValidator;
use App\Audit\PromptBuilder;
use App\Models\AiAgent;
use App\Models\AiUsageRecord;
use App\Models\PageVerification;
use App\Models\PublicationPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VerifyPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 220;

    public function __construct(
        public int $pageId
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('verify-page-'.$this->pageId))->shared()->releaseAfter(15)->expireAfter(250)];
    }

    public function handle(
        AgentPoolSelector $poolSelector,
        NineRouterClient $client,
        PromptBuilder $prompt,
        PageResultValidator $validator,
        CrossVerifier $verifier,
        AuditCoordinator $coordinator
    ): void {
        $page = PublicationPage::findOrFail($this->pageId);
        $publication = $page->publication;

        if ($page->status !== 'complete') {
            return;
        }

        // Verify if page should be verified
        if (! $verifier->shouldVerify($page)) {
            return;
        }

        // Must select a DIFFERENT agent from the one that analyzed the page
        $verifierEndpoint = $poolSelector->selectAgent($publication, excludeAgentId: $page->ai_agent_id);
        if (! $verifierEndpoint || ! ($verifierEndpoint instanceof AiAgent)) {
            // Cannot cross-verify if only 1 agent is available or no verifier found
            PageVerification::updateOrCreate(
                ['publication_page_id' => $page->id],
                [
                    'ai_agent_id' => $page->ai_agent_id,
                    'status' => 'skipped',
                    'error' => 'Dilewati: Diperlukan minimal 2 Agen AI siap yang berbeda untuk verifikasi silang.',
                ]
            );
            $coordinator->afterPage($publication);

            return;
        }

        $verification = PageVerification::updateOrCreate(
            ['publication_page_id' => $page->id],
            [
                'ai_agent_id' => $verifierEndpoint->id,
                'status' => 'running',
                'error' => null,
            ]
        );

        $started = microtime(true);
        try {
            $imagePath = Storage::disk('local')->path($page->image_path);
            $messages = $prompt->messages($page, $imagePath);

            $response = $client->request($verifierEndpoint, $messages);
            $result = $validator->validate($response['result'], $page->physical_page);

            AiUsageRecord::create([
                'publication_id' => $publication->id,
                'page_id' => $page->id,
                'ai_agent_id' => $verifierEndpoint->id,
                'model' => $response['model'],
                'request_id' => $response['request_id'],
                'usage' => $response['usage'],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'status' => 'verified_'.$result['readability'],
            ]);

            $verifier->reconcile($page, $verifierEndpoint, $result);
            $coordinator->afterPage($publication);
        } catch (Throwable $e) {
            $verification->update([
                'status' => 'failed',
                'error' => 'Verifikasi silang gagal: '.$e->getMessage(),
            ]);

            if ($e instanceof AiRequestException && $e->category === 'capacity') {
                $this->release($e->retryAfter);

                return;
            }

            // Do not fail the whole publication if verification fails; report and move forward
            $coordinator->afterPage($publication);
        }
    }
}

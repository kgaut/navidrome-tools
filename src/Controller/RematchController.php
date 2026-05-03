<?php

namespace App\Controller;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\RematchReport;
use App\Service\LastFmRematchService;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RematchController extends AbstractController
{
    public function __construct(
        private readonly LastFmRematchService $rematch,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $containerManager,
    ) {
    }

    #[Route('/lastfm/rematch', name: 'app_lastfm_rematch', methods: ['POST'])]
    public function rematch(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('lastfm_rematch', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $rawRunId = $request->request->get('run_id');
        $runId = ($rawRunId !== null && $rawRunId !== '') ? (int) $rawRunId : null;
        $reference = $runId !== null ? 'run-' . $runId : 'all';

        try {
            $this->containerManager->assertSafeToWrite();
        } catch (NavidromeContainerException $e) {
            $this->addFlash('error', $e->getMessage());

            $back = $runId !== null
                ? $this->generateUrl('app_history_detail', ['id' => $runId])
                : $this->generateUrl('app_lastfm_import');

            return new RedirectResponse($back);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $entry = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_REMATCH,
                reference: $reference,
                label: 'Rematch unmatched — ' . $reference,
                action: fn (RunHistory $run) => [$run, $this->rematch->rematch(runId: $runId)],
                extractMetrics: static fn (array $r) => [
                    'considered' => $r[1]->considered,
                    'inserted' => $r[1]->matchedAsInserted,
                    'duplicate' => $r[1]->matchedAsDuplicate,
                    'skipped' => $r[1]->skipped,
                    'still_unmatched' => $r[1]->stillUnmatched,
                    'run_id_filter' => $runId,
                ],
            );
            [$runEntry, $report] = $entry;
            /** @var RunHistory $runEntry */
            /** @var RematchReport $report */

            $this->addFlash('success', sprintf(
                'Rematch terminé : %d considérés, %d insérés, %d doublons, %d ignorés, %d toujours non matchés.',
                $report->considered,
                $report->matchedAsInserted,
                $report->matchedAsDuplicate,
                $report->skipped,
                $report->stillUnmatched,
            ));

            return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $runEntry->getId()]));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec du rematch : ' . $e->getMessage());

            $back = $runId !== null
                ? $this->generateUrl('app_history_detail', ['id' => $runId])
                : $this->generateUrl('app_lastfm_import');

            return new RedirectResponse($back);
        }
    }
}

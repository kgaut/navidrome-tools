<?php

namespace App\Controller;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Message\RunLastFmRematchMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class RematchController extends AbstractController
{
    public function __construct(
        private readonly NavidromeContainerManager $containerManager,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
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

        $entry = new RunHistory(
            type: RunHistory::TYPE_LASTFM_REMATCH,
            reference: $reference,
            label: 'Rematch unmatched — ' . $reference,
        );
        $entry->setStatus(RunHistory::STATUS_QUEUED);
        $this->em->persist($entry);
        $this->em->flush();

        $this->bus->dispatch(new RunLastFmRematchMessage(
            runHistoryId: (int) $entry->getId(),
            runIdFilter: $runId,
        ));

        $this->addFlash('success', 'Rematch mis en file — la progression s\'affiche ci-dessous.');

        return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $entry->getId()]));
    }
}

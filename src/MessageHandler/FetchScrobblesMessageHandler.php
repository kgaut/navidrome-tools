<?php

namespace App\MessageHandler;

use App\Message\FetchScrobblesMessage;
use App\Message\SyncLovedFlagsMessage;
use App\Scrobble\ScrobbleFetchRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class FetchScrobblesMessageHandler
{
    public function __construct(
        private readonly ScrobbleFetchRunner $runner,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(FetchScrobblesMessage $message): void
    {
        $this->runner->run($message->dateMin, $message->dateMax, $message->maxScrobbles, $message->dryRun);

        // Keep the loved flags honest after a fetch, as the Last.fm import did.
        if (!$message->dryRun) {
            $this->bus->dispatch(new SyncLovedFlagsMessage());
        }
    }
}

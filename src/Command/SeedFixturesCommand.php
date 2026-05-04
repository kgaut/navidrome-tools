<?php

namespace App\Command;

use App\Entity\PlaylistDefinition;
use App\Repository\PlaylistDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:seed',
    description: 'Insert example (disabled) playlist definitions on first boot. Idempotent.',
)]
class SeedFixturesCommand extends Command
{
    public function __construct(
        private readonly PlaylistDefinitionRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $samples = [
            [
                'name' => 'Top 7 derniers jours',
                'generator_key' => 'top-last-days',
                'parameters' => ['days' => 7],
                'template' => 'Top 7j — {date}',
            ],
            [
                'name' => 'Top 30 derniers jours',
                'generator_key' => 'top-last-days',
                'parameters' => ['days' => 30],
                'template' => 'Top 30j — {date}',
            ],
            [
                'name' => 'Top du mois passé',
                'generator_key' => 'top-last-month',
                'parameters' => [],
                'template' => 'Top {month}',
            ],
            [
                'name' => 'Top de l\'année passée',
                'generator_key' => 'top-last-year',
                'parameters' => [],
                'template' => 'Top {year}',
            ],
        ];

        $created = 0;
        foreach ($samples as $sample) {
            if ($this->repository->findOneByName($sample['name']) !== null) {
                continue;
            }
            $def = (new PlaylistDefinition())
                ->setName($sample['name'])
                ->setGeneratorKey($sample['generator_key'])
                ->setParameters($sample['parameters'])
                ->setPlaylistNameTemplate($sample['template'])
                ->setEnabled(false)
                ->setReplaceExisting(true);
            $this->em->persist($def);
            $created++;
        }

        $this->em->flush();
        $io->success(sprintf('%d playlist definitions created (existing ones left untouched).', $created));

        return Command::SUCCESS;
    }
}

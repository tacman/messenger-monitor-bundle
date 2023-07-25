<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\TransportMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsCommand('messenger:monitor:snapshot', 'Display a historical snapshot of processed messages')]
final class SnapshotCommand extends Command
{
    public function __construct(private Storage $storage, private TransportMonitor $transports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'From date', Specification::ONE_DAY_AGO, Specification::DATE_PRESETS)
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'To date', 'now')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status, "failed" or "success"', null, [Specification::SUCCESS, Specification::FAILED])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Message type')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Transport', null, fn() => $this->transports->names())
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Tag')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specification = Specification::fromArray([
            'from' => $input->getOption('from'),
            'to' => $input->getOption('to'),
            'status' => $input->getOption('status'),
            'message_type' => $input->getOption('type'),
            'transport' => $input->getOption('transport'),
            'tag' => $input->getOption('tag'),
        ]);
        $snapshot = $specification->snapshot($this->storage);
        $waitTime = $snapshot->averageWaitTime();
        $handlingTime = $snapshot->averageHandlingTime();
        $failRate = \round($snapshot->failRate() * 100);
        $total = $snapshot->totalCount();

        if ($fails = $snapshot->failureCount()) {
            $total .= \sprintf(' (<error>%s</error> failed)', $fails);
        }

        $io->createTable()
            ->setHorizontal()
            ->setHeaderTitle('Historical Snapshot')
            ->setHeaders([
                'Period',
                'Messages Processed',
                'Fail Rate',
                'Avg. Wait Time',
                'Avg. Handling Time',
                'Handled Per Minute',
                'Handled Per Hour',
                'Handled Per Day',
            ])
            ->addRow([
                \sprintf('<comment>%s</comment> to <comment>%s</comment>', $input->getOption('from'), $input->getOption('to')),
                $total,
                match (true) {
                    $failRate < 5 => \sprintf('<info>%s%%</info>', $failRate),
                    $failRate < 10 => \sprintf('<comment>%s%%</comment>', $failRate),
                    default => \sprintf('<error>%s%%</error>', $failRate),
                },
                $waitTime ? Helper::formatTime($snapshot->averageWaitTime()) : 'n/a',
                $handlingTime ? Helper::formatTime($snapshot->averageHandlingTime()) : 'n/a',
                \round($snapshot->handledPerMinute(), 2),
                \round($snapshot->handledPerHour(), 2),
                \round($snapshot->handledPerDay(), 2),
            ])
            ->render()
        ;

        $io->newLine();

        $listing = $io->createTable()
            ->setHeaderTitle('Last 10')
            ->setHeaders(['Type', 'Transport', 'Time in Queue', 'Time to Handle', 'Handled At', 'Tags'])
        ;

        foreach ($snapshot->messages()->take(10) as $message) {
            $handledAt = $message->handledAt()->format('Y-m-d H:i:s');

            $listing->addRow([
                $message->type()->shortName(),
                $message->transport(),
                Helper::formatTime($message->timeInQueue()),
                Helper::formatTime($message->timeToHandle()),
                $message->isFailure() ? \sprintf('<error>[!] %s</error>', $handledAt) : \sprintf('<info>%s</info>', $handledAt),
                $message->tags()->implode() ?? '(none)',
            ]);
        }

        $listing->render();

        return self::SUCCESS;
    }
}

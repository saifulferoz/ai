<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\AiBundle\Command;

use Symfony\AI\Agent\Approval\Checkpoint\CheckpointStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Saiful Islam <saif012@gmail.com>
 */
#[AsCommand(
    name: 'ai:agent:list-pending',
    description: 'Lists all pending agent tool approval requests',
)]
final class AgentListPendingCommand extends Command
{
    public function __construct(
        private readonly ?CheckpointStoreInterface $checkpointStore = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->checkpointStore) {
            $io->warning('No CheckpointStoreInterface service is configured.');

            return Command::FAILURE;
        }

        $checkpoints = $this->checkpointStore->all();

        if ([] === $checkpoints) {
            $io->success('No pending tool approvals found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($checkpoints as $cp) {
            $pendingTools = [];
            foreach ($cp->getPendingToolCalls() as $toolCall) {
                $argsStr = json_encode($toolCall->getArguments(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                $pendingTools[] = \sprintf('%s(%s)', $toolCall->getName(), $argsStr);
            }

            $rows[] = [
                $cp->getId(),
                $cp->getAgentName(),
                $cp->getModel(),
                implode(', ', $pendingTools),
                $cp->getCreatedAt()->format('Y-m-d H:i:s'),
                $cp->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Never',
            ];
        }

        $io->title('Pending Agent Tool Approvals');
        $io->table(
            ['Checkpoint ID', 'Agent', 'Model', 'Pending Tool Call(s)', 'Created At', 'Expires At'],
            $rows,
        );

        $io->note('To approve or reject a request, run: bin/console ai:agent:approve <checkpoint-id>');

        return Command::SUCCESS;
    }
}

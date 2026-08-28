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

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Approval\ApprovalDecision;
use Symfony\AI\Agent\Approval\Checkpoint\CheckpointSignerInterface;
use Symfony\AI\Agent\Approval\Checkpoint\CheckpointStoreInterface;
use Symfony\AI\Agent\Approval\Checkpoint\ExecutionCheckpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @author Saiful Islam <saif012@gmail.com>
 */
#[AsCommand(
    name: 'ai:agent:approve',
    description: 'Review and approve, reject, or modify a pending agent tool approval',
)]
final class AgentApproveCommand extends Command
{
    /**
     * @param ServiceLocator<AgentInterface> $agents
     */
    public function __construct(
        private readonly ServiceLocator $agents,
        private readonly ?CheckpointStoreInterface $checkpointStore = null,
        private readonly ?CheckpointSignerInterface $signer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('checkpoint', InputArgument::OPTIONAL, 'Checkpoint ID or signed token to review')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Reject the tool execution')
            ->addOption('feedback', null, InputOption::VALUE_REQUIRED, 'Feedback or explanation for the approval/rejection')
            ->addOption('arguments', null, InputOption::VALUE_REQUIRED, 'JSON-encoded modified arguments for the tool call');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $checkpointInput = $input->getArgument('checkpoint');

        if (null === $checkpointInput) {
            if (null === $this->checkpointStore) {
                $io->error('No checkpoint specified and no CheckpointStore configured.');

                return Command::FAILURE;
            }

            $pending = $this->checkpointStore->all();
            if ([] === $pending) {
                $io->success('No pending approvals found.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($pending as $cp) {
                $toolName = $cp->getPendingToolCalls()[0]?->getName() ?? 'unknown';
                $choices[$cp->getId()] = \sprintf('%s (Agent: %s, Tool: %s)', $cp->getId(), $cp->getAgentName(), $toolName);
            }

            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion('Select a pending approval to review:', $choices);
            $checkpointInput = $helper->ask($input, $output, $question);
        }

        $checkpoint = $this->resolveCheckpoint($checkpointInput);
        if (null === $checkpoint) {
            $io->error(\sprintf('Could not find or decode checkpoint "%s".', $checkpointInput));

            return Command::FAILURE;
        }

        $agentName = $checkpoint->getAgentName();
        if (!$this->agents->has($agentName)) {
            $io->error(\sprintf('Agent "%s" is not registered in the service container.', $agentName));

            return Command::FAILURE;
        }

        $agent = $this->agents->get($agentName);

        $io->title('Reviewing Tool Approval Request');
        $io->definitionList(
            ['Checkpoint ID' => $checkpoint->getId()],
            ['Agent' => $agentName],
            ['Model' => $checkpoint->getModel()],
            ['Created At' => $checkpoint->getCreatedAt()->format('Y-m-d H:i:s')],
        );

        $pendingCalls = $checkpoint->getPendingToolCalls();
        if ([] === $pendingCalls) {
            $io->warning('No pending tool calls in this checkpoint.');

            return Command::SUCCESS;
        }

        $currentTool = $pendingCalls[0];
        $io->section(\sprintf('Tool Call: %s', $currentTool->getName()));
        $io->writeln('Arguments: ' . json_encode($currentTool->getArguments(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $feedback = $input->getOption('feedback');
        $modifiedArgsJson = $input->getOption('arguments');

        if ($input->getOption('reject')) {
            $decision = ApprovalDecision::reject($feedback ?? 'Rejected via CLI');
            $io->warning('Rejecting tool execution...');
        } elseif (null !== $modifiedArgsJson) {
            $modifiedArgs = json_decode($modifiedArgsJson, true);
            if (!\is_array($modifiedArgs)) {
                $io->error('The --arguments option must be valid JSON.');

                return Command::FAILURE;
            }
            $decision = ApprovalDecision::modify($modifiedArgs, $feedback);
            $io->note('Executing tool with modified arguments...');
        } elseif ($input->isInteractive() && null === $feedback) {
            $helper = $this->getHelper('question');
            $confirm = new ConfirmationQuestion('Do you want to approve this tool execution? (y/n) [y]: ', true);
            if ($helper->ask($input, $output, $confirm)) {
                $decision = ApprovalDecision::approve();
                $io->success('Approved. Resuming agent execution...');
            } else {
                $decision = ApprovalDecision::reject('Rejected interactively via CLI');
                $io->warning('Rejected. Resuming agent execution with rejection feedback...');
            }
        } else {
            $decision = ApprovalDecision::approve($feedback);
            $io->success('Approved. Resuming agent execution...');
        }

        $result = $agent->resume($checkpoint, $decision);

        $io->section('Agent Response');
        $io->writeln((string) $result->getContent());

        return Command::SUCCESS;
    }

    private function resolveCheckpoint(string $identifier): ?ExecutionCheckpoint
    {
        if (null !== $this->signer) {
            try {
                return $this->signer->decode($identifier);
            } catch (\Throwable) {
                // Fallback to store lookup
            }
        }

        if (null !== $this->checkpointStore) {
            return $this->checkpointStore->get($identifier);
        }

        return null;
    }
}

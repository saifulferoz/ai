<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\AiBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Approval\Checkpoint\ExecutionCheckpoint;
use Symfony\AI\Agent\Approval\Checkpoint\InMemoryCheckpointStore;
use Symfony\AI\AiBundle\Command\AgentListPendingCommand;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AgentListPendingCommandTest extends TestCase
{
    public function testListEmptyPendingApprovals()
    {
        $store = new InMemoryCheckpointStore();
        $command = new AgentListPendingCommand($store);
        $tester = new CommandTester($command);

        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('No pending tool approvals found.', $tester->getDisplay());
    }

    public function testListPendingApprovalsWithData()
    {
        $store = new InMemoryCheckpointStore();
        $checkpoint = new ExecutionCheckpoint(
            id: 'chk-12345',
            agentName: 'finance-agent',
            model: 'gpt-4o',
            pendingToolCalls: [
                new ToolCall('call_1', 'transfer_money', ['amount' => 250]),
            ],
        );
        $store->save($checkpoint);

        $command = new AgentListPendingCommand($store);
        $tester = new CommandTester($command);

        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Pending Agent Tool Approvals', $output);
        $this->assertStringContainsString('chk-12345', $output);
        $this->assertStringContainsString('finance-agent', $output);
        $this->assertStringContainsString('transfer_money', $output);
    }
}

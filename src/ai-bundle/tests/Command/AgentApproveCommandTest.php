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
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Approval\ApprovalDecision;
use Symfony\AI\Agent\Approval\Checkpoint\ExecutionCheckpoint;
use Symfony\AI\Agent\Approval\Checkpoint\InMemoryCheckpointStore;
use Symfony\AI\AiBundle\Command\AgentApproveCommand;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class AgentApproveCommandTest extends TestCase
{
    public function testApproveCheckpointSuccessfully()
    {
        $checkpoint = new ExecutionCheckpoint(
            id: 'cp-abc',
            agentName: 'finance',
            model: 'gpt-4o',
            pendingToolCalls: [
                new ToolCall('call_1', 'transfer_money', ['amount' => 100]),
            ],
        );

        $store = new InMemoryCheckpointStore();
        $store->save($checkpoint);

        $agent = $this->createMock(AgentInterface::class);
        $agent->expects($this->once())
            ->method('resume')
            ->with($checkpoint, $this->callback(static fn (ApprovalDecision $d) => $d->isApproved()))
            ->willReturn(new TextResult('Transfer succeeded.'));

        $locator = new ServiceLocator([
            'finance' => fn () => $agent,
        ]);

        $command = new AgentApproveCommand($locator, $store);
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'checkpoint' => 'cp-abc',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Transfer succeeded.', $tester->getDisplay());
    }

    public function testRejectCheckpointSuccessfully()
    {
        $checkpoint = new ExecutionCheckpoint(
            id: 'cp-abc',
            agentName: 'finance',
            model: 'gpt-4o',
            pendingToolCalls: [
                new ToolCall('call_1', 'transfer_money', ['amount' => 100]),
            ],
        );

        $store = new InMemoryCheckpointStore();
        $store->save($checkpoint);

        $agent = $this->createMock(AgentInterface::class);
        $agent->expects($this->once())
            ->method('resume')
            ->with($checkpoint, $this->callback(static fn (ApprovalDecision $d) => $d->isRejected() && 'Too risky' === $d->getFeedback()))
            ->willReturn(new TextResult('Rejection processed.'));

        $locator = new ServiceLocator([
            'finance' => fn () => $agent,
        ]);

        $command = new AgentApproveCommand($locator, $store);
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'checkpoint' => 'cp-abc',
            '--reject' => true,
            '--feedback' => 'Too risky',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Rejection processed.', $tester->getDisplay());
    }
}

<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\ContainerBuilderHasAliasConstraint;
use PHPUnit\Framework\Constraint\LogicalNot;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Zenstruck\Messenger\Monitor\DependencyInjection\ZenstruckMessengerMonitorExtension;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\History\Storage\ORMStorage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage as ProcessedMessageImpl;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ZenstruckMessengerMonitorExtensionTest extends AbstractExtensionTestCase
{
    /**
     * @test
     */
    public function no_config(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias(TransportMonitor::class, 'zenstruck_messenger_monitor.transport_monitor');
        $this->assertContainerBuilderHasAlias(WorkerMonitor::class, 'zenstruck_messenger_monitor.worker_monitor');
        $this->assertThat($this->container, new LogicalNot(new ContainerBuilderHasAliasConstraint(Storage::class)));
    }

    /**
     * @test
     */
    public function orm_config(): void
    {
        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessageImpl::class]]]);

        $this->assertContainerBuilderHasAlias(TransportMonitor::class, 'zenstruck_messenger_monitor.transport_monitor');
        $this->assertContainerBuilderHasAlias(WorkerMonitor::class, 'zenstruck_messenger_monitor.worker_monitor');
        $this->assertContainerBuilderHasService('zenstruck_messenger_monitor.history.storage', ORMStorage::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('zenstruck_messenger_monitor.history.storage', 1, ProcessedMessageImpl::class);
        $this->assertContainerBuilderHasAlias(Storage::class, 'zenstruck_messenger_monitor.history.storage');
    }

    /**
     * @test
     */
    public function non_orm_entity(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['storage' => ['orm' => ['entity_class' => 'invalid']]]);
    }

    /**
     * @test
     */
    public function non_extended_orm_entity(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessage::class]]]);
    }

    protected function getContainerExtensions(): array
    {
        return [new ZenstruckMessengerMonitorExtension()];
    }
}
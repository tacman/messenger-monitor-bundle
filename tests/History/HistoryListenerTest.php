<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Zenstruck\Messenger\Monitor\History\HistoryListener;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\History\Stamp\ResultStamp;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\Tag;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class HistoryListenerTest extends TestCase
{
    /**
     * @test
     */
    public function adds_monitor_stamp(): void
    {
        $listener = new HistoryListener($this->createMock(Storage::class), new ResultNormalizer());
        $envelope = new Envelope(new \stdClass());
        $event = new SendMessageToTransportsEvent($envelope, []);

        $this->assertNull($event->getEnvelope()->last(MonitorStamp::class));

        $listener->addMonitorStamp($event);

        $this->assertInstanceOf(MonitorStamp::class, $event->getEnvelope()->last(MonitorStamp::class));
    }

    /**
     * @test
     */
    public function skips_standard_messages_without_monitor_stamp(): void
    {
        $listener = new HistoryListener($this->createMock(Storage::class), new ResultNormalizer());
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertEmpty($event->getEnvelope()->all(MonitorStamp::class));

        $listener->receiveMessage($event);

        $this->assertEmpty($event->getEnvelope()->all(MonitorStamp::class));
    }

    /**
     * @test
     */
    public function marks_standard_message_as_received(): void
    {
        $listener = new HistoryListener($this->createMock(Storage::class), new ResultNormalizer());
        $envelope = new Envelope(new \stdClass(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());

        $listener->receiveMessage($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
        $this->assertSame('foo', $event->getEnvelope()->last(MonitorStamp::class)->transport());
        $this->assertEmpty($event->getEnvelope()->all(Tag::class));
    }

    /**
     * @test
     */
    public function marks_scheduled_message_as_received(): void
    {
        $listener = new HistoryListener($this->createMock(Storage::class), new ResultNormalizer());
        $envelope = new Envelope(new \stdClass(), [new ScheduledStamp(new MessageContext(
            'default',
            'id',
            $this->createMock(TriggerInterface::class),
            new \DateTimeImmutable()
        ))]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertNull($event->getEnvelope()->last(MonitorStamp::class));

        $listener->receiveMessage($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
        $this->assertSame('foo', $event->getEnvelope()->last(MonitorStamp::class)->transport());
        $this->assertCount(1, $event->getEnvelope()->all(Tag::class));
        $this->assertSame(['schedule:default:id'], $event->getEnvelope()->last(Tag::class)->values);
    }

    /**
     * @test
     */
    public function handles_success(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            (new MonitorStamp())->markReceived('foo'),
            new HandledStamp('handler', 'return'),
        ]);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with($this->isInstanceOf(Envelope::class));

        $listener = new HistoryListener($storage, new ResultNormalizer());

        $listener->handleSuccess($event = new WorkerMessageHandledEvent($envelope, 'foo'));

        $this->assertCount(1, $event->getEnvelope()->all(ResultStamp::class));
    }

    /**
     * @test
     */
    public function handles_success_invalid(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');

        $listener = new HistoryListener($storage, new ResultNormalizer());

        $listener->handleSuccess(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'foo'));
        $listener->handleSuccess(new WorkerMessageHandledEvent(new Envelope(new \stdClass(), [new MonitorStamp()]), 'foo'));
    }

    /**
     * @test
     */
    public function handles_failure(): void
    {
        $envelope = new Envelope(new \stdClass(), [(new MonitorStamp())->markReceived('foo')]);
        $exception = new \RuntimeException();
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with($this->isInstanceOf(Envelope::class), $exception);

        $listener = new HistoryListener($storage, new ResultNormalizer());

        $listener->handleFailure($event = new WorkerMessageFailedEvent($envelope, 'foo', $exception));

        $this->assertCount(1, $event->getEnvelope()->all(ResultStamp::class));
    }

    /**
     * @test
     */
    public function handles_failure_invalid(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');

        $listener = new HistoryListener($storage, new ResultNormalizer());

        $listener->handleFailure(new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'foo', new \RuntimeException()));
        $listener->handleFailure(new WorkerMessageFailedEvent(new Envelope(new \stdClass(), [new MonitorStamp()]), 'foo', new \RuntimeException()));
    }
}
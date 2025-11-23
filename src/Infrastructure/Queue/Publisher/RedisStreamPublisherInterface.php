<?php
namespace PenPay\Infrastructure\Queue\Publisher;

interface RedisStreamPublisherInterface
{
    /**
     * Publish event to redis stream.
     *
     * @param string $stream name
     * @param array $payload data (serializable)
     */
    public function publish(string $stream, array $payload): void;
}
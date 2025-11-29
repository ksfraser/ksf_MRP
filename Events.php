<?php
/**
 * MRP Events
 */

namespace FA\Modules\MRP\Events;

use FA\Events\Event;

/**
 * MRP Calculation Started Event
 */
class MRPStartedEvent extends Event
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getName(): string
    {
        return 'mrp.started';
    }
}

/**
 * MRP Calculation Succeeded Event
 */
class MRPSucceededEvent extends Event
{
    private MRPSummary $summary;

    public function __construct(MRPSummary $summary)
    {
        $this->summary = $summary;
    }

    public function getSummary(): MRPSummary
    {
        return $this->summary;
    }

    public function getName(): string
    {
        return 'mrp.succeeded';
    }
}

/**
 * MRP Calculation Failed Event
 */
class MRPFailedEvent extends Event
{
    private \Exception $exception;
    private array $config;

    public function __construct(\Exception $exception, array $config)
    {
        $this->exception = $exception;
        $this->config = $config;
    }

    public function getException(): \Exception
    {
        return $this->exception;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getName(): string
    {
        return 'mrp.failed';
    }
}
<?php
/**
 * MRP Summary and Supporting Classes
 */

namespace FA\Modules\MRP;

/**
 * MRP Calculation Summary
 */
class MRPSummary
{
    private array $parameters;
    private array $plannedOrders = [];
    private array $partSummaries = [];
    private \DateTime $runTime;

    public function __construct(?array $parameters = null, array $plannedOrders = [])
    {
        $this->runTime = new \DateTime();
        $this->parameters = $parameters ?? [];
        $this->plannedOrders = $plannedOrders;
    }

    public function addPartSummary(PartMRPSummary $partSummary): void
    {
        $this->partSummaries[] = $partSummary;
    }

    public function getPlannedOrdersCount(): int
    {
        return count($this->plannedOrders);
    }

    public function getTotalPlannedQuantity(): float
    {
        return array_sum(array_column($this->plannedOrders, 'supplyquantity'));
    }

    public function getPartsProcessedCount(): int
    {
        return count($this->partSummaries);
    }

    public function getRunTime(): \DateTime
    {
        return $this->runTime;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getPlannedOrders(): array
    {
        return $this->plannedOrders;
    }

    public function getPartSummaries(): array
    {
        return $this->partSummaries;
    }

    public function toArray(): array
    {
        return [
            'run_time' => $this->runTime->format('Y-m-d H:i:s'),
            'parameters' => $this->parameters,
            'planned_orders_count' => $this->getPlannedOrdersCount(),
            'total_planned_quantity' => $this->getTotalPlannedQuantity(),
            'parts_processed' => $this->getPartsProcessedCount(),
            'planned_orders' => $this->plannedOrders,
            'part_summaries' => array_map(fn($s) => $s->toArray(), $this->partSummaries)
        ];
    }
}

/**
 * Individual Part MRP Summary
 */
class PartMRPSummary
{
    private string $partCode;
    private float $grossRequirements = 0;
    private float $scheduledReceipts = 0;
    private float $projectedBalance = 0;
    private float $netRequirements = 0;
    private float $plannedOrderQuantity = 0;
    private ?\DateTime $plannedOrderDate = null;

    public function __construct(string $partCode)
    {
        $this->partCode = $partCode;
    }

    public function setGrossRequirements(float $quantity): self
    {
        $this->grossRequirements = $quantity;
        return $this;
    }

    public function setScheduledReceipts(float $quantity): self
    {
        $this->scheduledReceipts = $quantity;
        return $this;
    }

    public function setProjectedBalance(float $balance): self
    {
        $this->projectedBalance = $balance;
        return $this;
    }

    public function setNetRequirements(float $quantity): self
    {
        $this->netRequirements = $quantity;
        return $this;
    }

    public function setPlannedOrder(float $quantity, ?\DateTime $date = null): self
    {
        $this->plannedOrderQuantity = $quantity;
        $this->plannedOrderDate = $date;
        return $this;
    }

    public function getPartCode(): string
    {
        return $this->partCode;
    }

    public function toArray(): array
    {
        return [
            'part_code' => $this->partCode,
            'gross_requirements' => $this->grossRequirements,
            'scheduled_receipts' => $this->scheduledReceipts,
            'projected_balance' => $this->projectedBalance,
            'net_requirements' => $this->netRequirements,
            'planned_order_quantity' => $this->plannedOrderQuantity,
            'planned_order_date' => $this->plannedOrderDate?->format('Y-m-d')
        ];
    }
}
<?php
/**
 * MRP Module Tests
 */

namespace FA\Modules\MRP\Tests;

use PHPUnit\Framework\TestCase;
use FA\Modules\MRP\MRPService;
use FA\Modules\MRP\MRPSummary;
use FA\Modules\MRP\MRPException;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcherInterface;
use FA\Services\InventoryService;
use FA\Services\ManufacturingService;
use Psr\Log\LoggerInterface;

/**
 * MRP Service Test Suite
 */
class MRPServiceTest extends TestCase
{
    private $db;
    private $events;
    private $logger;
    private $inventory;
    private $manufacturing;
    private MRPService $mrpService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DBALInterface::class);
        $this->events = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->inventory = $this->createMock(InventoryService::class);
        $this->manufacturing = $this->createMock(ManufacturingService::class);

        $this->mrpService = new MRPService(
            $this->db,
            $this->events,
            $this->logger,
            $this->inventory,
            $this->manufacturing
        );
    }

    public function testConfigureReturnsSelf()
    {
        $result = $this->mrpService->configure(['use_eoq' => true]);
        $this->assertSame($this->mrpService, $result);
    }

    public function testRunMRPReturnsSummary()
    {
        // Mock successful MRP run
        $this->db->expects($this->any())
            ->method('executeStatement')
            ->willReturn(true);

        $this->db->expects($this->any())
            ->method('executeQuery')
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $summary = $this->mrpService->runMRP();

        $this->assertInstanceOf(MRPSummary::class, $summary);
    }

    public function testRunMRPHandlesExceptions()
    {
        $this->db->expects($this->any())
            ->method('executeStatement')
            ->willThrowException(new \Exception('Database error'));

        $this->expectException(MRPException::class);
        $this->mrpService->runMRP();
    }

    public function testCleanupRemovesTempTables()
    {
        $this->db->expects($this->exactly(8))
            ->method('executeStatement')
            ->withConsecutive(
                ['DROP TEMPORARY TABLE IF EXISTS tempbom'],
                ['DROP TEMPORARY TABLE IF EXISTS passbom'],
                ['DROP TEMPORARY TABLE IF EXISTS passbom2'],
                ['DROP TEMPORARY TABLE IF EXISTS bomlevels'],
                ['DROP TEMPORARY TABLE IF EXISTS mrprequirements'],
                ['DROP TEMPORARY TABLE IF EXISTS mrpsupplies'],
                ['DROP TEMPORARY TABLE IF EXISTS mrpplannedorders'],
                ['DROP TABLE IF EXISTS levels']
            );

        $this->mrpService->cleanup();
    }
}

/**
 * MRP Summary Test
 */
class MRPSummaryTest extends TestCase
{
    public function testSummaryCreation()
    {
        $summary = new MRPSummary();

        $this->assertInstanceOf(MRPSummary::class, $summary);
        $this->assertEquals(0, $summary->getPlannedOrdersCount());
        $this->assertEquals(0, $summary->getTotalPlannedQuantity());
    }

    public function testSummaryWithData()
    {
        $parameters = ['test' => 'data'];
        $plannedOrders = [
            ['supplyquantity' => 100],
            ['supplyquantity' => 200]
        ];

        $summary = new MRPSummary($parameters, $plannedOrders);

        $this->assertEquals(2, $summary->getPlannedOrdersCount());
        $this->assertEquals(300, $summary->getTotalPlannedQuantity());
        $this->assertEquals($parameters, $summary->getParameters());
    }

    public function testToArray()
    {
        $summary = new MRPSummary(['config' => true], []);
        $array = $summary->toArray();

        $this->assertArrayHasKey('run_time', $array);
        $this->assertArrayHasKey('parameters', $array);
        $this->assertArrayHasKey('planned_orders_count', $array);
    }
}
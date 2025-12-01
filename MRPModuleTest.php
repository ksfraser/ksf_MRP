<?php
/**
 * FrontAccounting MRP Module Tests
 *
 * Unit tests for MRP functionality.
 *
 * @package FA\Modules\MRP
 * @version 1.0.0
 * @author FrontAccounting Team
 * @license GPL-3.0
 */

namespace FA\Modules\MRP;

use PHPUnit\Framework\TestCase;
use FA\Modules\MRP\MRPService;
use FA\Modules\MRP\Entities\MRPCalculation;
use FA\Modules\MRP\Entities\MRPRequirement;
use FA\Modules\MRP\Entities\MRPPlannedOrder;
use FA\Modules\MRP\Entities\MRPShortage;
use FA\Modules\MRP\Events\MRPCalculationCompletedEvent;
use FA\Modules\MRP\MRPException;

/**
 * MRP Module Test Suite
 */
class MRPModuleTest extends TestCase
{
    private MRPService $mrpService;
    private $mockDBAL;
    private $mockEventDispatcher;
    private $mockLogger;

    protected function setUp(): void
    {
        $this->mockDBAL = $this->createMock(\FA\Interfaces\DBALInterface::class);
        $this->mockEventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->mrpService = new MRPService(
            $this->mockDBAL,
            $this->mockEventDispatcher,
            $this->mockLogger
        );
    }

    /**
     * Test MRP calculation creation
     */
    public function testCreateMRPCalculation(): void
    {
        $calculationData = [
            'calculation_name' => 'Monthly MRP Run',
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
            'include_safety_stock' => true,
            'planning_horizon_days' => 30
        ];

        $this->mockDBAL->expects($this->once())
            ->method('insert')
            ->with('mrp_calculations', $this->anything())
            ->willReturn(1);

        $this->mockEventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(MRPCalculationCreatedEvent::class));

        $calculation = $this->mrpService->createMRPCalculation($calculationData);

        $this->assertInstanceOf(MRPCalculation::class, $calculation);
        $this->assertEquals('Monthly MRP Run', $calculation->getCalculationName());
        $this->assertTrue($calculation->shouldIncludeSafetyStock());
    }

    /**
     * Test MRP calculation execution
     */
    public function testExecuteMRPCalculation(): void
    {
        $calculationId = 1;

        // Mock getting calculation
        $this->mockDBAL->expects($this->any())
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                // MRP calculation
                [['id' => 1, 'calculation_name' => 'Test Calc', 'start_date' => '2024-01-01', 'end_date' => '2024-01-31', 'include_safety_stock' => 1, 'planning_horizon_days' => 30, 'status' => 'created', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00']],
                // Demand data
                [['stock_id' => 'ITEM001', 'required_date' => '2024-01-15', 'quantity' => 100.0]],
                // Current stock levels
                [['stock_id' => 'ITEM001', 'quantity' => 50.0, 'on_order' => 25.0]],
                // BOM data
                [['parent' => 'FINISHED001', 'component' => 'ITEM001', 'quantity' => 2.0, 'effective_from' => '2024-01-01']],
                // Empty requirements (first call)
                [],
                // Empty planned orders (first call)
                []
            );

        $this->mockDBAL->expects($this->any())
            ->method('insert')
            ->willReturnOnConsecutiveCalls(1, 2);

        $this->mockEventDispatcher->expects($this->exactly(2))
            ->method('dispatch');

        $result = $this->mrpService->executeMRPCalculation($calculationId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('requirements', $result);
        $this->assertArrayHasKey('shortages', $result);
        $this->assertArrayHasKey('planned_orders', $result);
    }

    /**
     * Test shortage analysis
     */
    public function testAnalyzeShortages(): void
    {
        $calculationId = 1;

        $this->mockDBAL->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'stock_id' => 'ITEM001',
                    'required_quantity' => 100.0,
                    'available_quantity' => 50.0,
                    'shortage_quantity' => 50.0,
                    'required_date' => '2024-01-15'
                ]
            ]);

        $shortages = $this->mrpService->analyzeShortages($calculationId);

        $this->assertIsArray($shortages);
        $this->assertCount(1, $shortages);
        $this->assertInstanceOf(MRPShortage::class, $shortages[0]);
        $this->assertEquals(50.0, $shortages[0]->getShortageQuantity());
    }

    /**
     * Test planned order generation
     */
    public function testGeneratePlannedOrders(): void
    {
        $calculationId = 1;

        $this->mockDBAL->expects($this->any())
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                // Shortages
                [['stock_id' => 'ITEM001', 'shortage_quantity' => 50.0, 'required_date' => '2024-01-15']],
                // Supplier lead times
                [['stock_id' => 'ITEM001', 'supplier_id' => 'SUP001', 'lead_time_days' => 7]]
            );

        $this->mockDBAL->expects($this->once())
            ->method('insert')
            ->willReturn(1);

        $this->mockEventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(MRPPlannedOrderCreatedEvent::class));

        $orders = $this->mrpService->generatePlannedOrders($calculationId);

        $this->assertIsArray($orders);
        $this->assertCount(1, $orders);
        $this->assertInstanceOf(MRPPlannedOrder::class, $orders[0]);
    }

    /**
     * Test MRP calculation with invalid data
     */
    public function testCreateMRPCalculationWithInvalidData(): void
    {
        $this->expectException(MRPValidationException::class);

        $invalidData = [
            'calculation_name' => '', // Empty name should fail
            'start_date' => 'invalid-date',
            'end_date' => '2024-01-31'
        ];

        $this->mrpService->createMRPCalculation($invalidData);
    }

    /**
     * Test getting MRP calculation that doesn't exist
     */
    public function testGetMRPCalculationNotFound(): void
    {
        $this->mockDBAL->expects($this->once())
            ->method('fetchOne')
            ->willReturn(null);

        $this->expectException(MRPCalculationNotFoundException::class);

        $this->mrpService->getMRPCalculation(999);
    }

    /**
     * Test MRP reporting
     */
    public function testGetMRPReport(): void
    {
        $calculationId = 1;

        $this->mockDBAL->expects($this->any())
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                // Requirements
                [['stock_id' => 'ITEM001', 'required_quantity' => 100.0, 'required_date' => '2024-01-15']],
                // Shortages
                [['stock_id' => 'ITEM001', 'shortage_quantity' => 25.0]],
                // Planned orders
                [['stock_id' => 'ITEM001', 'planned_quantity' => 50.0, 'due_date' => '2024-01-08']]
            );

        $report = $this->mrpService->getMRPReport($calculationId);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('requirements', $report);
        $this->assertArrayHasKey('shortages', $report);
        $this->assertArrayHasKey('planned_orders', $report);
    }

    /**
     * Test demand forecasting
     */
    public function testCalculateDemandForecast(): void
    {
        $stockId = 'ITEM001';
        $forecastPeriod = 30;

        $this->mockDBAL->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['period' => '2024-01', 'quantity' => 100.0],
                ['period' => '2024-02', 'quantity' => 120.0],
                ['period' => '2024-03', 'quantity' => 110.0]
            ]);

        $forecast = $this->mrpService->calculateDemandForecast($stockId, $forecastPeriod);

        $this->assertIsArray($forecast);
        $this->assertArrayHasKey('forecast_quantity', $forecast);
        $this->assertArrayHasKey('confidence_level', $forecast);
        $this->assertGreaterThan(0, $forecast['forecast_quantity']);
    }
}
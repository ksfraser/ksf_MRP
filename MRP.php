<?php
/**
 * FrontAccounting MRP Module Main File
 *
 * @package FA\Modules\MRP
 */

namespace FA\Modules\MRP;

use FA\Module;
use FA\Events\EventDispatcherInterface;
use FA\Database\DBALInterface;
use FA\Services\InventoryService;
use FA\Services\ManufacturingService;
use Psr\Log\LoggerInterface;

/**
 * MRP Module Main Class
 */
class MRP extends Module
{
    private MRPService $mrpService;

    public function __construct(
        DBALInterface $db,
        EventDispatcherInterface $events,
        LoggerInterface $logger,
        InventoryService $inventoryService,
        ManufacturingService $manufacturingService
    ) {
        parent::__construct($db, $events, $logger);

        $this->mrpService = new MRPService(
            $db,
            $events,
            $logger,
            $inventoryService,
            $manufacturingService
        );
    }

    /**
     * Get module name
     */
    public function getName(): string
    {
        return 'MRP';
    }

    /**
     * Get module version
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return 'Material Requirements Planning for manufacturing operations';
    }

    /**
     * Initialize the module
     */
    public function init(): void
    {
        // Register event listeners
        $this->events->addListener('mrp.started', [$this, 'onMRPStarted']);
        $this->events->addListener('mrp.succeeded', [$this, 'onMRPSucceeded']);
        $this->events->addListener('mrp.failed', [$this, 'onMRPFailed']);

        $this->logger->info('MRP module initialized');
    }

    /**
     * Run MRP calculation
     */
    public function runMRP(array $config = []): MRPSummary
    {
        return $this->mrpService->configure($config)->runMRP();
    }

    /**
     * Get last MRP summary
     */
    public function getLastMRPSummary(): ?MRPSummary
    {
        return $this->mrpService->getMRPSummary();
    }

    /**
     * Clean up MRP temporary data
     */
    public function cleanup(): void
    {
        $this->mrpService->cleanup();
    }

    /**
     * Event handler for MRP started
     */
    public function onMRPStarted(MRPSummary $summary): void
    {
        $this->logger->info('MRP calculation started', $summary->getParameters());
    }

    /**
     * Event handler for MRP succeeded
     */
    public function onMRPSucceeded(MRPSummary $summary): void
    {
        $this->logger->info('MRP calculation completed successfully', [
            'planned_orders' => $summary->getPlannedOrdersCount(),
            'total_quantity' => $summary->getTotalPlannedQuantity()
        ]);
    }

    /**
     * Event handler for MRP failed
     */
    public function onMRPFailed(\Exception $exception): void
    {
        $this->logger->error('MRP calculation failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }

    /**
     * Get module menu items
     */
    public function getMenuItems(): array
    {
        return [
            [
                'title' => _('MRP'),
                'href' => 'modules/MRP/mrp_interface.php',
                'access' => 'SA_MRP',
                'icon' => 'fa-cogs'
            ]
        ];
    }

    /**
     * Get module permissions
     */
    public function getPermissions(): array
    {
        return [
            'SA_MRP' => _('MRP Calculation'),
        ];
    }
}
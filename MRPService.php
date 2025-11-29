<?php
/**
 * FrontAccounting MRP (Material Requirements Planning) Module
 *
 * Advanced material requirements planning for manufacturing operations.
 * Based on webERP MRP functionality with modernized architecture.
 *
 * @package FA\Modules\MRP
 * @version 1.0.0
 * @author FrontAccounting Team
 * @license GPL-3.0
 */

namespace FA\Modules\MRP;

use FA\Events\EventDispatcherInterface;
use FA\Database\DBALInterface;
use FA\Services\InventoryService;
use FA\Services\ManufacturingService;
use FA\Exceptions\MRPException;
use Psr\Log\LoggerInterface;

/**
 * Main MRP Service Class
 *
 * Handles Material Requirements Planning calculations and processing
 */
class MRPService
{
    private DBALInterface $db;
    private EventDispatcherInterface $events;
    private LoggerInterface $logger;
    private InventoryService $inventoryService;
    private ManufacturingService $manufacturingService;

    // MRP Configuration
    private array $config = [
        'use_mrp_demands' => true,
        'use_reorder_level_demands' => true,
        'use_eoq' => true,
        'use_pan_size' => true,
        'use_shrinkage' => true,
        'leeway_days' => 0,
        'locations' => []
    ];

    public function __construct(
        DBALInterface $db,
        EventDispatcherInterface $events,
        LoggerInterface $logger,
        InventoryService $inventoryService,
        ManufacturingService $manufacturingService
    ) {
        $this->db = $db;
        $this->events = $events;
        $this->logger = $logger;
        $this->inventoryService = $inventoryService;
        $this->manufacturingService = $manufacturingService;
    }

    /**
     * Configure MRP calculation parameters
     */
    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Run complete MRP calculation
     *
     * @throws MRPException
     */
    public function runMRP(): MRPSummary
    {
        $this->logger->info('Starting MRP calculation', $this->config);

        $this->events->dispatch(new MRPStartedEvent($this->config));

        try {
            // Initialize temporary tables
            $this->initializeTables();

            // Build BOM levels structure
            $this->buildBOMLevels();

            // Load requirements from various sources
            $this->loadRequirements();

            // Load supplies from various sources
            $this->loadSupplies();

            // Process MRP netting by levels
            $summary = $this->processMRPNetting();

            // Save MRP parameters for audit trail
            $this->saveMRPParameters();

            $this->events->dispatch(new MRPSucceededEvent($summary));

            $this->logger->info('MRP calculation completed successfully', [
                'summary' => $summary->toArray()
            ]);

            return $summary;

        } catch (\Exception $e) {
            $this->events->dispatch(new MRPFailedEvent($e, $this->config));
            $this->logger->error('MRP calculation failed', [
                'error' => $e->getMessage(),
                'config' => $this->config
            ]);
            throw new MRPException('MRP calculation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Initialize temporary tables for MRP calculation
     */
    private function initializeTables(): void
    {
        $this->logger->debug('Initializing MRP temporary tables');

        // Drop existing temporary tables
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS tempbom');
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS passbom');
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS passbom2');
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS bomlevels');
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS levels');

        // Create temporary tables
        $this->createTempBOMTable();
        $this->createPassBOMTable();
        $this->createBOMLevelsTable();
        $this->createLevelsTable();
        $this->createRequirementsTable();
        $this->createSuppliesTable();
        $this->createPlannedOrdersTable();
    }

    /**
     * Create temporary BOM table
     */
    private function createTempBOMTable(): void
    {
        $sql = "CREATE TEMPORARY TABLE tempbom (
            parent VARCHAR(20),
            component VARCHAR(20),
            sortpart TEXT,
            level INT
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create pass BOM table
     */
    private function createPassBOMTable(): void
    {
        $sql = "CREATE TEMPORARY TABLE passbom (
            part VARCHAR(20),
            sortpart TEXT
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create BOM levels table
     */
    private function createBOMLevelsTable(): void
    {
        $sql = "CREATE TEMPORARY TABLE bomlevels (
            part VARCHAR(20),
            level INT
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create levels table
     */
    private function createLevelsTable(): void
    {
        $sql = "CREATE TABLE levels (
            part VARCHAR(20),
            level INT,
            leadtime SMALLINT DEFAULT 0,
            pansize DOUBLE DEFAULT 0,
            shrinkfactor DOUBLE DEFAULT 0,
            eoq DOUBLE DEFAULT 0,
            PRIMARY KEY (part)
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create requirements table
     */
    private function createRequirementsTable(): void
    {
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS mrprequirements');

        $sql = "CREATE TEMPORARY TABLE mrprequirements (
            part VARCHAR(20),
            daterequired DATE,
            quantity DOUBLE,
            mrpdemandtype VARCHAR(6),
            orderno INT,
            directdemand TINYINT,
            whererequired VARCHAR(20),
            INDEX part (part)
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create supplies table
     */
    private function createSuppliesTable(): void
    {
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS mrpsupplies');

        $sql = "CREATE TEMPORARY TABLE mrpsupplies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            part VARCHAR(20),
            duedate DATE,
            supplyquantity DOUBLE,
            ordertype VARCHAR(6),
            orderno INT,
            mrpdate DATE,
            updateflag TINYINT DEFAULT 0,
            INDEX part (part)
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Create planned orders table
     */
    private function createPlannedOrdersTable(): void
    {
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS mrpplannedorders');

        $sql = "CREATE TEMPORARY TABLE mrpplannedorders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            part VARCHAR(20),
            duedate DATE,
            supplyquantity DOUBLE,
            ordertype VARCHAR(6),
            orderno INT,
            mrpdate DATE,
            updateflag TINYINT DEFAULT 0
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);
    }

    /**
     * Build BOM levels structure
     */
    private function buildBOMLevels(): void
    {
        $this->logger->debug('Building BOM levels structure');

        // Find top-level assemblies
        $this->buildTopLevelBOM();

        // Build lower levels
        $this->buildLowerLevelBOM();

        // Create levels table
        $this->createLevelsFromBOM();
    }

    /**
     * Build top-level BOM structure
     */
    private function buildTopLevelBOM(): void
    {
        $sql = "INSERT INTO passbom (part, sortpart)
                SELECT bom.component AS part,
                       CONCAT(bom.parent, '%', bom.component) AS sortpart
                FROM bom LEFT JOIN bom AS bom2 ON bom.parent = bom2.component
                WHERE bom2.component IS NULL";
        $this->db->executeStatement($sql);
    }

    /**
     * Build lower-level BOM structure
     */
    private function buildLowerLevelBOM(): void
    {
        $compctr = 1;

        while ($compctr > 0) {
            $this->buildNextBOMLevel();
            $compctr = $this->getComponentCount();
        }
    }

    /**
     * Build next BOM level
     */
    private function buildNextBOMLevel(): void
    {
        static $level = 2;

        $sql = "INSERT INTO tempbom (parent, component, sortpart, level)
                SELECT bom.parent AS parent,
                       bom.component AS component,
                       CONCAT(passbom.sortpart, '%', bom.component) AS sortpart,
                       ? AS level
                FROM bom, passbom
                WHERE bom.parent = passbom.part";
        $this->db->executeStatement($sql, [$level]);

        // Rotate passbom tables
        $this->rotatePassBOMTables();

        $level++;
    }

    /**
     * Rotate passbom tables for next level processing
     */
    private function rotatePassBOMTables(): void
    {
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS passbom2');
        $this->db->executeStatement('ALTER TABLE passbom RENAME TO passbom2');
        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS passbom');

        $this->createPassBOMTable();

        $sql = "INSERT INTO passbom (part, sortpart)
                SELECT bom.component AS part,
                       CONCAT(passbom2.sortpart, '%', bom.component) AS sortpart
                FROM bom, passbom2
                WHERE bom.parent = passbom2.part";
        $this->db->executeStatement($sql);
    }

    /**
     * Get component count for level processing
     */
    private function getComponentCount(): int
    {
        $sql = "SELECT COUNT(*) FROM bom
                INNER JOIN passbom ON bom.parent = passbom.part
                GROUP BY bom.parent";
        $result = $this->db->executeQuery($sql);
        return $result->rowCount();
    }

    /**
     * Create levels from BOM structure
     */
    private function createLevelsFromBOM(): void
    {
        // Build bomlevels from tempbom
        $this->buildBOMLevelsFromTemp();

        // Create final levels table
        $this->buildFinalLevelsTable();
    }

    /**
     * Build BOM levels from temporary BOM data
     */
    private function buildBOMLevelsFromTemp(): void
    {
        $sql = "SELECT * FROM tempbom";
        $results = $this->db->executeQuery($sql);

        foreach ($results as $row) {
            $parts = explode('%', $row['sortpart']);
            $level = $row['level'];
            $ctr = 0;

            foreach ($parts as $part) {
                $ctr++;
                $newlevel = $level - $ctr;
                $this->db->executeStatement(
                    "INSERT INTO bomlevels (part, level) VALUES (?, ?)",
                    [$part, $newlevel]
                );
            }
        }
    }

    /**
     * Build final levels table with item details
     */
    private function buildFinalLevelsTable(): void
    {
        $sql = "INSERT INTO levels (part, level, leadtime, pansize, shrinkfactor, eoq)
                SELECT bomlevels.part,
                       MAX(bomlevels.level),
                       0,
                       pansize,
                       shrinkfactor,
                       eoq
                FROM bomlevels
                INNER JOIN stock_master ON bomlevels.part = stock_master.stock_id
                GROUP BY bomlevels.part, pansize, shrinkfactor, eoq";
        $this->db->executeStatement($sql);

        // Add items not in BOM
        $this->addNonBOMItemsToLevels();

        // Update lead times
        $this->updateLeadTimes();
    }

    /**
     * Add items not in BOM to levels table
     */
    private function addNonBOMItemsToLevels(): void
    {
        $sql = "INSERT INTO levels (part, level, leadtime, pansize, shrinkfactor, eoq)
                SELECT stock_master.stock_id AS part,
                       0,
                       0,
                       stock_master.pansize,
                       stock_master.shrinkfactor,
                       stock_master.eoq
                FROM stock_master
                LEFT JOIN levels ON stock_master.stock_id = levels.part
                WHERE levels.part IS NULL";
        $this->db->executeStatement($sql);
    }

    /**
     * Update lead times from purchase data
     */
    private function updateLeadTimes(): void
    {
        // Update with preferred supplier lead times
        $sql = "UPDATE levels, purch_data
                SET levels.leadtime = purch_data.lead_time
                WHERE levels.part = purch_data.stock_id
                AND purch_data.preferred = 1
                AND purch_data.lead_time > 0";
        $this->db->executeStatement($sql);
    }

    /**
     * Load requirements from various sources
     */
    private function loadRequirements(): void
    {
        $this->logger->debug('Loading MRP requirements');

        $this->loadSalesOrderRequirements();
        $this->loadWorkOrderRequirements();

        if ($this->config['use_mrp_demands']) {
            $this->loadMRPDemands();
        }

        if ($this->config['use_reorder_level_demands']) {
            $this->loadReorderLevelDemands();
        }
    }

    /**
     * Load requirements from sales orders
     */
    private function loadSalesOrderRequirements(): void
    {
        $sql = "INSERT INTO mrprequirements (part, daterequired, quantity, mrpdemandtype, orderno, directdemand, whererequired)
                SELECT stkcode,
                       itemdue,
                       (quantity - qty_invoiced) AS netqty,
                       'SO',
                       sales_order_details.order_no,
                       1,
                       stkcode
                FROM sales_orders
                INNER JOIN sales_order_details ON sales_orders.order_no = sales_order_details.order_no
                INNER JOIN stock_master ON stock_master.stock_id = sales_order_details.stk_code
                WHERE stock_master.discontinued = 0
                AND (quantity - qty_invoiced) > 0
                AND sales_order_details.completed = 0
                AND sales_orders.quotation = 0";
        $this->db->executeStatement($sql);
    }

    /**
     * Load requirements from work orders
     */
    private function loadWorkOrderRequirements(): void
    {
        $sql = "INSERT INTO mrprequirements (part, daterequired, quantity, mrpdemandtype, orderno, directdemand, whererequired)
                SELECT worequirements.stockid,
                       workorders.requiredby,
                       (qtypu * woitems.qtyreqd + COALESCE(stockmoves.qty, 0)) AS netqty,
                       'WO',
                       woitems.wo,
                       1,
                       parentstockid
                FROM woitems
                INNER JOIN worequirements ON woitems.stockid = worequirements.parentstockid
                INNER JOIN workorders ON woitems.wo = workorders.wo AND woitems.wo = worequirements.wo
                INNER JOIN stock_master ON woitems.stockid = stock_master.stock_id
                LEFT JOIN stockmoves ON (stockmoves.stockid = worequirements.stockid
                                       AND stockmoves.reference = woitems.wo
                                       AND stockmoves.type = 28)
                WHERE workorders.closed = 0
                AND stock_master.discontinued = 0
                AND netqty > 0
                GROUP BY workorders.wo, worequirements.stockid, workorders.requiredby,
                         woitems.qtyreqd, worequirements.qtypu, woitems.wo,
                         worequirements.stockid, parentstockid";
        $this->db->executeStatement($sql);
    }

    /**
     * Load MRP demands
     */
    private function loadMRPDemands(): void
    {
        $sql = "INSERT INTO mrprequirements (part, daterequired, quantity, mrpdemandtype, orderno, directdemand, whererequired)
                SELECT mrpdemands.stockid,
                       mrpdemands.duedate,
                       mrpdemands.quantity,
                       mrpdemands.mrpdemandtype,
                       mrpdemands.demandid,
                       1,
                       mrpdemands.stockid
                FROM mrpdemands, stock_master
                WHERE mrpdemands.stockid = stock_master.stock_id
                AND stock_master.discontinued = 0";
        $this->db->executeStatement($sql);
    }

    /**
     * Load reorder level demands
     */
    private function loadReorderLevelDemands(): void
    {
        $locationFilter = $this->buildLocationFilter();

        $sql = "INSERT INTO mrprequirements (part, daterequired, quantity, mrpdemandtype, orderno, directdemand, whererequired)
                SELECT locstock.stockid,
                       CURDATE(),
                       (locstock.reorderlevel - locstock.quantity) AS reordqty,
                       'REORD',
                       1,
                       1,
                       locstock.stockid
                FROM locstock, stock_master
                WHERE stock_master.stock_id = locstock.stockid
                AND stock_master.discontinued = 0
                AND reorderlevel - quantity > 0
                {$locationFilter}";
        $this->db->executeStatement($sql);
    }

    /**
     * Load supplies from various sources
     */
    private function loadSupplies(): void
    {
        $this->logger->debug('Loading MRP supplies');

        $this->loadPurchaseOrderSupplies();
        $this->loadInventorySupplies();
        $this->loadWorkOrderSupplies();
    }

    /**
     * Load supplies from purchase orders
     */
    private function loadPurchaseOrderSupplies(): void
    {
        $sql = "INSERT INTO mrpsupplies (id, part, duedate, supplyquantity, ordertype, orderno, mrpdate, updateflag)
                SELECT NULL,
                       purch_order_details.itemcode,
                       purch_order_details.deliverydate,
                       (quantityord - quantityrecd) AS netqty,
                       'PO',
                       purch_order_details.orderno,
                       purch_order_details.deliverydate,
                       0
                FROM purch_order_details,
                     purch_orders
                WHERE purch_order_details.orderno = purch_orders.orderno
                AND purch_orders.status NOT IN ('Cancelled', 'Rejected', 'Completed')
                AND (quantityord - quantityrecd) > 0
                AND purch_order_details.completed = 0";
        $this->db->executeStatement($sql);
    }

    /**
     * Load supplies from inventory on hand
     */
    private function loadInventorySupplies(): void
    {
        $locationFilter = $this->buildLocationFilter();

        $sql = "INSERT INTO mrpsupplies (id, part, duedate, supplyquantity, ordertype, orderno, mrpdate, updateflag)
                SELECT NULL,
                       stock_id,
                       '0000-00-00',
                       SUM(quantity),
                       'QOH',
                       1,
                       '0000-00-00',
                       0
                FROM stock_moves
                WHERE quantity > 0
                {$locationFilter}
                GROUP BY stock_id";
        $this->db->executeStatement($sql);
    }

    /**
     * Load supplies from work orders
     */
    private function loadWorkOrderSupplies(): void
    {
        $sql = "INSERT INTO mrpsupplies (id, part, duedate, supplyquantity, ordertype, orderno, mrpdate, updateflag)
                SELECT NULL,
                       stockid,
                       workorders.requiredby,
                       (woitems.qtyreqd - woitems.qtyrecd) AS netqty,
                       'WO',
                       woitems.wo,
                       workorders.requiredby,
                       0
                FROM woitems
                INNER JOIN workorders ON woitems.wo = workorders.wo
                WHERE workorders.closed = 0
                AND (woitems.qtyreqd - woitems.qtyrecd) > 0";
        $this->db->executeStatement($sql);
    }

    /**
     * Process MRP netting by levels
     */
    private function processMRPNetting(): MRPSummary
    {
        $this->logger->debug('Processing MRP netting by levels');

        $levelRange = $this->getLevelRange();
        $summary = new MRPSummary();

        for ($level = $levelRange['max']; $level >= $levelRange['min']; $level--) {
            $this->logger->debug("Processing level {$level}");

            $parts = $this->getPartsByLevel($level);

            foreach ($parts as $part) {
                $levelNetter = new LevelNetter(
                    $this->db,
                    $this->config,
                    $part,
                    $this->getPartDetails($part)
                );

                $partSummary = $levelNetter->processLevel();
                $summary->addPartSummary($partSummary);
            }
        }

        return $summary;
    }

    /**
     * Get min and max levels
     */
    private function getLevelRange(): array
    {
        $sql = "SELECT MAX(level) as max_level, MIN(level) as min_level FROM levels";
        $result = $this->db->executeQuery($sql)->fetchAssociative();

        return [
            'max' => (int) $result['max_level'],
            'min' => (int) $result['min_level']
        ];
    }

    /**
     * Get parts by level
     */
    private function getPartsByLevel(int $level): array
    {
        $sql = "SELECT part FROM levels WHERE level = ?";
        $results = $this->db->executeQuery($sql, [$level]);

        return array_column($results->fetchAllAssociative(), 'part');
    }

    /**
     * Get part details for MRP processing
     */
    private function getPartDetails(string $part): array
    {
        $sql = "SELECT eoq, pansize, shrinkfactor, leadtime FROM levels WHERE part = ?";
        $result = $this->db->executeQuery($sql, [$part])->fetchAssociative();

        return [
            'eoq' => (float) ($result['eoq'] ?? 0),
            'pansize' => (float) ($result['pansize'] ?? 0),
            'shrinkfactor' => (float) ($result['shrinkfactor'] ?? 0),
            'leadtime' => (int) ($result['leadtime'] ?? 0)
        ];
    }

    /**
     * Build location filter for queries
     */
    private function buildLocationFilter(): string
    {
        if (empty($this->config['locations'])) {
            return '';
        }

        if (in_array('All', $this->config['locations'])) {
            return '';
        }

        $locations = array_map([$this->db, 'quote'], $this->config['locations']);
        return " AND loccode IN (" . implode(',', $locations) . ")";
    }

    /**
     * Save MRP parameters for audit trail
     */
    private function saveMRPParameters(): void
    {
        $this->db->executeStatement('DROP TABLE IF EXISTS mrpparameters');

        $sql = "CREATE TABLE mrpparameters (
            runtime DATETIME,
            location TEXT,
            pansizeflag VARCHAR(5),
            shrinkageflag VARCHAR(5),
            eoqflag VARCHAR(5),
            usemrpdemands VARCHAR(5),
            userldemands VARCHAR(5),
            leeway SMALLINT
        ) DEFAULT CHARSET=utf8";
        $this->db->executeStatement($sql);

        $locationParam = is_array($this->config['locations'])
            ? implode(' - ', $this->config['locations'])
            : 'All';

        $sql = "INSERT INTO mrpparameters (runtime, location, pansizeflag, shrinkageflag, eoqflag, usemrpdemands, userldemands, leeway)
                VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)";
        $this->db->executeStatement($sql, [
            $locationParam,
            $this->config['use_pan_size'] ? 'y' : 'n',
            $this->config['use_shrinkage'] ? 'y' : 'n',
            $this->config['use_eoq'] ? 'y' : 'n',
            $this->config['use_mrp_demands'] ? 'y' : 'n',
            $this->config['use_reorder_level_demands'] ? 'y' : 'n',
            $this->config['leeway_days']
        ]);
    }

    /**
     * Get MRP calculation summary
     */
    public function getMRPSummary(): ?MRPSummary
    {
        $sql = "SELECT * FROM mrpparameters ORDER BY runtime DESC LIMIT 1";
        $result = $this->db->executeQuery($sql)->fetchAssociative();

        if (!$result) {
            return null;
        }

        // Load planned orders
        $plannedOrders = $this->db->executeQuery("SELECT * FROM mrpplannedorders")
            ->fetchAllAssociative();

        return new MRPSummary($result, $plannedOrders);
    }

    /**
     * Clean up temporary tables
     */
    public function cleanup(): void
    {
        $tempTables = [
            'tempbom', 'passbom', 'passbom2', 'bomlevels',
            'mrprequirements', 'mrpsupplies', 'mrpplannedorders'
        ];

        foreach ($tempTables as $table) {
            $this->db->executeStatement("DROP TEMPORARY TABLE IF EXISTS {$table}");
        }

        $this->db->executeStatement("DROP TABLE IF EXISTS levels");
    }
}
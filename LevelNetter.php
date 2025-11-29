<?php
/**
 * Level Netter - Core MRP Netting Logic
 */

namespace FA\Modules\MRP;

use FA\Database\DBALInterface;

/**
 * Handles MRP netting calculations for individual parts
 */
class LevelNetter
{
    private DBALInterface $db;
    private array $config;
    private string $part;
    private array $partDetails;

    public function __construct(
        DBALInterface $db,
        array $config,
        string $part,
        array $partDetails
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->part = $part;
        $this->partDetails = $partDetails;
    }

    /**
     * Process MRP netting for this part
     */
    public function processLevel(): PartMRPSummary
    {
        $summary = new PartMRPSummary($this->part);

        // Load requirements and supplies
        $requirements = $this->loadRequirements();
        $supplies = $this->loadSupplies();

        if (empty($requirements) && empty($supplies)) {
            return $summary;
        }

        // Sort requirements and supplies by date
        $this->sortRequirements($requirements);
        $this->sortSupplies($supplies);

        // Perform netting calculation
        $nettingResult = $this->performNetting($requirements, $supplies);

        // Create planned orders if needed
        $plannedOrders = $this->createPlannedOrders($nettingResult['unmetRequirements']);

        // Update summary
        $summary->setGrossRequirements($nettingResult['totalRequirements'])
                ->setScheduledReceipts($nettingResult['totalSupplies'])
                ->setProjectedBalance($nettingResult['projectedBalance'])
                ->setNetRequirements($nettingResult['netRequirements']);

        if (!empty($plannedOrders)) {
            $summary->setPlannedOrder(
                array_sum(array_column($plannedOrders, 'quantity')),
                new \DateTime($plannedOrders[0]['duedate'] ?? 'now')
            );
        }

        return $summary;
    }

    /**
     * Load requirements for this part
     */
    private function loadRequirements(): array
    {
        $sql = "SELECT * FROM mrprequirements WHERE part = ? ORDER BY daterequired";
        $results = $this->db->executeQuery($sql, [$this->part]);
        return $results->fetchAllAssociative();
    }

    /**
     * Load supplies for this part
     */
    private function loadSupplies(): array
    {
        $sql = "SELECT * FROM mrpsupplies WHERE part = ? ORDER BY duedate";
        $results = $this->db->executeQuery($sql, [$this->part]);
        return $results->fetchAllAssociative();
    }

    /**
     * Sort requirements by date
     */
    private function sortRequirements(array &$requirements): void
    {
        usort($requirements, fn($a, $b) => strcmp($a['daterequired'], $b['daterequired']));
    }

    /**
     * Sort supplies by date
     */
    private function sortSupplies(array &$supplies): void
    {
        usort($supplies, fn($a, $b) => strcmp($a['duedate'], $b['duedate']));
    }

    /**
     * Perform the core netting calculation
     */
    private function performNetting(array $requirements, array $supplies): array
    {
        $totalRequirements = array_sum(array_column($requirements, 'quantity'));
        $totalSupplies = array_sum(array_column($supplies, 'supplyquantity'));

        $reqIndex = 0;
        $supIndex = 0;
        $projectedBalance = 0;
        $netRequirements = 0;
        $unmetRequirements = [];

        // Process requirements against supplies
        while ($reqIndex < count($requirements) && $supIndex < count($supplies)) {
            $requirement = &$requirements[$reqIndex];
            $supply = &$supplies[$supIndex];

            // Mark supply as updated
            $supply['updateflag'] = 1;

            // Check if supply needs date adjustment
            $this->adjustSupplyDateIfNeeded($supply, $requirement);

            if ($requirement['quantity'] > $supply['supplyquantity']) {
                // Requirement not fully covered
                $requirement['quantity'] -= $supply['supplyquantity'];
                $supply['supplyquantity'] = 0;
                $supIndex++;
            } elseif ($requirement['quantity'] < $supply['supplyquantity']) {
                // Supply exceeds requirement
                $supply['supplyquantity'] -= $requirement['quantity'];
                $requirement['quantity'] = 0;
                $reqIndex++;
            } else {
                // Exact match
                $requirement['quantity'] = 0;
                $supply['supplyquantity'] = 0;
                $reqIndex++;
                $supIndex++;
            }
        }

        // Calculate projected balance and identify unmet requirements
        foreach ($requirements as $req) {
            if ($req['quantity'] > 0) {
                $netRequirements += $req['quantity'];
                $unmetRequirements[] = $req;
            }
        }

        $projectedBalance = $totalSupplies - $totalRequirements;

        return [
            'totalRequirements' => $totalRequirements,
            'totalSupplies' => $totalSupplies,
            'projectedBalance' => $projectedBalance,
            'netRequirements' => $netRequirements,
            'unmetRequirements' => $unmetRequirements
        ];
    }

    /**
     * Adjust supply date if needed based on leeway
     */
    private function adjustSupplyDateIfNeeded(array &$supply, array $requirement): void
    {
        if ($supply['duedate'] === '0000-00-00' || empty($supply['duedate'])) {
            return;
        }

        $supplyDate = new \DateTime($supply['duedate']);
        $reqDate = new \DateTime($requirement['daterequired']);

        $dateDiff = $supplyDate->diff($reqDate)->days;

        if ($dateDiff > $this->config['leeway_days']) {
            // Update supply date in database
            $this->db->executeStatement(
                "UPDATE mrpsupplies SET mrpdate = ? WHERE id = ? AND duedate = mrpdate",
                [$requirement['daterequired'], $supply['id']]
            );
        }
    }

    /**
     * Create planned orders for unmet requirements
     */
    private function createPlannedOrders(array $unmetRequirements): array
    {
        $plannedOrders = [];
        $excessQty = 0;

        foreach ($unmetRequirements as $requirement) {
            // Apply shrinkage factor
            $requiredQty = $this->applyShrinkageFactor($requirement['quantity']);

            // Check if excess quantity covers this requirement
            if ($excessQty >= $requiredQty) {
                $excessQty -= $requiredQty;
                continue;
            }

            $plannedQty = $requiredQty - $excessQty;
            $excessQty = 0;

            // Apply EOQ if configured
            if ($this->config['use_eoq'] && $this->partDetails['eoq'] > $plannedQty) {
                $excessQty = $this->partDetails['eoq'] - $plannedQty;
                $plannedQty = $this->partDetails['eoq'];
            }

            // Apply pan size if configured
            if ($this->config['use_pan_size'] && $this->partDetails['pansize'] > 0) {
                $plannedQty = ceil($plannedQty / $this->partDetails['pansize']) * $this->partDetails['pansize'];
            }

            // Calculate due date with lead time
            $dueDate = $this->calculateDueDate($requirement['daterequired']);

            // Create planned order
            $plannedOrder = $this->createPlannedOrder(
                $plannedQty,
                $dueDate,
                $requirement
            );

            $plannedOrders[] = $plannedOrder;

            // Create lower level requirements if this part has components
            if ($this->hasBOMComponents()) {
                $this->createLowerLevelRequirements($this->part, $dueDate, $plannedQty, $requirement);
            }
        }

        return $plannedOrders;
    }

    /**
     * Apply shrinkage factor to quantity
     */
    private function applyShrinkageFactor(float $quantity): float
    {
        if ($this->config['use_shrinkage'] && $this->partDetails['shrinkfactor'] > 0) {
            $quantity = ($quantity * 100) / (100 - $this->partDetails['shrinkfactor']);
        }
        return round($quantity, 2);
    }

    /**
     * Calculate due date considering lead time
     */
    private function calculateDueDate(string $requiredDate): string
    {
        if ($this->partDetails['leadtime'] <= 0) {
            return $requiredDate;
        }

        $reqDate = new \DateTime($requiredDate);
        $reqDate->modify("-{$this->partDetails['leadtime']} days");

        return $reqDate->format('Y-m-d');
    }

    /**
     * Create a planned order record
     */
    private function createPlannedOrder(float $quantity, string $dueDate, array $requirement): array
    {
        $this->db->executeStatement(
            "INSERT INTO mrpplannedorders (part, duedate, supplyquantity, ordertype, orderno, mrpdate, updateflag)
             VALUES (?, ?, ?, ?, ?, ?, 0)",
            [
                $this->part,
                $dueDate,
                $quantity,
                $requirement['mrpdemandtype'],
                $requirement['orderno'],
                $dueDate
            ]
        );

        return [
            'part' => $this->part,
            'duedate' => $dueDate,
            'quantity' => $quantity,
            'ordertype' => $requirement['mrpdemandtype'],
            'orderno' => $requirement['orderno']
        ];
    }

    /**
     * Check if part has BOM components
     */
    private function hasBOMComponents(): bool
    {
        $sql = "SELECT COUNT(*) FROM bom WHERE parent = ?";
        $count = $this->db->executeQuery($sql, [$this->part])->fetchOne();
        return $count > 0;
    }

    /**
     * Create lower level requirements for BOM components
     */
    private function createLowerLevelRequirements(string $parentPart, string $dueDate, float $parentQuantity, array $requirement): void
    {
        $sql = "SELECT bom.component,
                       bom.quantity,
                       levels.leadtime
                FROM bom
                LEFT JOIN levels ON bom.component = levels.part
                WHERE bom.parent = ?
                AND bom.effectiveafter <= CURDATE()
                AND bom.effectiveto > CURDATE()";

        $components = $this->db->executeQuery($sql, [$parentPart])->fetchAllAssociative();

        foreach ($components as $component) {
            $extendedQty = $component['quantity'] * $parentQuantity;

            // Calculate component due date
            $componentDueDate = $dueDate;
            if ($component['leadtime'] > 0) {
                $dueDateTime = new \DateTime($dueDate);
                $dueDateTime->modify("-{$component['leadtime']} days");
                $componentDueDate = $dueDateTime->format('Y-m-d');
            }

            // Insert lower level requirement
            $this->db->executeStatement(
                "INSERT INTO mrprequirements (part, daterequired, quantity, mrpdemandtype, orderno, directdemand, whererequired)
                 VALUES (?, ?, ?, ?, ?, 0, ?)",
                [
                    $component['component'],
                    $componentDueDate,
                    $extendedQty,
                    $requirement['mrpdemandtype'],
                    $requirement['orderno'],
                    $parentPart
                ]
            );
        }
    }
}
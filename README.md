# MRP (Material Requirements Planning) Module for FrontAccounting

Advanced material requirements planning for manufacturing operations with modern PHP architecture.

## Features

- **Demand Forecasting**: Sales order and work order demand analysis
- **MRP Calculations**: Automatic calculation of material requirements by BOM level
- **Multi-level BOM Support**: Handles complex product structures with multiple levels
- **Supply Analysis**: Considers purchase orders, work orders, and inventory on hand
- **Planned Orders**: Generates purchase and production orders for unmet requirements
- **Lead Time Management**: Considers supplier and manufacturing lead times
- **Shrinkage & Pan Size**: Handles material shrinkage and packaging constraints
- **Reorder Level Integration**: Optional reorder level demand consideration
- **Location-based Planning**: Multi-location inventory planning support

## Architecture

### Core Components

- **MRP.php**: Main module class implementing FA module interface
- **MRPService.php**: Core MRP calculation service with event-driven architecture
- **LevelNetter.php**: Handles netting calculations for individual parts
- **MRPSummary.php**: Calculation results and reporting classes
- **Events.php**: PSR-14 compatible event classes

### Key Classes

```php
// Main service usage
$mrp = new MRPService($db, $events, $logger, $inventory, $manufacturing);
$summary = $mrp->configure(['use_eoq' => true])->runMRP();
```

## Configuration Options

```php
$config = [
    'use_mrp_demands' => true,           // Include MRP demand records
    'use_reorder_level_demands' => true, // Include reorder level demands
    'use_eoq' => true,                   // Use Economic Order Quantity
    'use_pan_size' => true,              // Use pan size rounding
    'use_shrinkage' => true,             // Apply shrinkage factors
    'leeway_days' => 0,                  // Days leeway for supply dates
    'locations' => ['All']               // Locations to include
];
```

## Requirements

- FrontAccounting 2.4+
- PHP 8.0+
- Manufacturing environment with BOM structures
- PSR-14 Event Dispatcher
- Doctrine DBAL
- PSR-3 Logger

## Installation

1. Ensure the module directory is in `modules/MRP/`
2. The module will auto-register through FA's module system
3. Required database tables are created automatically during first run

## Usage

### Basic MRP Run

```php
// Get MRP module instance
$mrp = $fa->getModule('MRP');

// Configure and run MRP
$config = ['locations' => ['MAIN', 'WAREHOUSE']];
$summary = $mrp->runMRP($config);

// Check results
echo "Planned orders: " . $summary->getPlannedOrdersCount();
echo "Total quantity: " . $summary->getTotalPlannedQuantity();
```

### Event-driven Integration

```php
// Listen for MRP events
$events->addListener('mrp.succeeded', function($event) {
    $summary = $event->getSummary();
    // Process results
});
```

## Database Tables

The module creates temporary tables during calculation:

- `mrprequirements`: Demand requirements by part
- `mrpsupplies`: Supply sources by part
- `mrpplannedorders`: Generated planned orders
- `levels`: BOM level structure
- `mrpparameters`: Audit trail of calculation parameters

## Integration Points

### Events
- `mrp.started`: Fired when MRP calculation begins
- `mrp.succeeded`: Fired when calculation completes successfully
- `mrp.failed`: Fired when calculation fails

### Services
- Integrates with InventoryService for stock levels
- Uses ManufacturingService for work order data
- Leverages FA's event system for extensibility

## Algorithm Overview

1. **BOM Level Analysis**: Builds multi-level product structure
2. **Requirements Loading**: Gathers demands from sales orders, work orders, MRP demands
3. **Supply Analysis**: Collects supplies from POs, inventory, work orders
4. **Level-by-Level Netting**: Processes parts starting from finished goods down to raw materials
5. **Planned Order Generation**: Creates orders for unmet requirements
6. **Lower Level Propagation**: Generates requirements for BOM components

## Performance Considerations

- Large BOM structures may require significant processing time
- Consider running MRP during off-peak hours
- Monitor database performance with complex queries
- Use location filtering to reduce scope

## Future Enhancements

- Advanced forecasting algorithms
- Multi-plant MRP
- Integration with ERP systems
- Real-time MRP updates
- AI-powered demand forecasting
- Capacity planning integration
- Scenario planning tools

## Troubleshooting

### Common Issues

1. **Memory Limits**: Large BOMs may exceed PHP memory limits
   - Solution: Increase `memory_limit` in php.ini

2. **Timeout Issues**: Long-running calculations
   - Solution: Increase `max_execution_time`

3. **Database Locks**: Concurrent MRP runs
   - Solution: Schedule runs sequentially

### Logging

All MRP operations are logged through PSR-3 logger:

```php
// Check logs for detailed execution information
$logger->info('MRP calculation details', $context);
```

## License

GPL-3.0 - Same as FrontAccounting core
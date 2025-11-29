<?php
/**
 * MRP Module Web Interface
 */

include_once($path_to_root . "/includes/session.php");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/modules/MRP/MRP.php");
include_once($path_to_root . "/includes/LocationRepository.php");

$page_security = 'SA_MRP';
$path_to_root = "../..";

$js = "";
if ($SysPrefs->use_popup_windows)
    $js .= get_js_open_window(900, 500);
if (user_use_date_picker())
    $js .= get_js_date_picker();

page(_($help_context = "MRP Calculation"), false, false, "", $js);

// Get MRP module instance
$mrp = new \FA\Modules\MRP\MRP(
    $db, // Global DB connection
    $app->getEventDispatcher(),
    $app->getLogger(),
    $app->getService('inventory'),
    $app->getService('manufacturing')
);

// Handle form submission
if (isset($_POST['RunMRP'])) {
    try {
        $config = [
            'use_mrp_demands' => isset($_POST['UseMRPDemands']),
            'use_reorder_level_demands' => isset($_POST['UseReorderLevelDemands']),
            'use_eoq' => isset($_POST['UseEOQ']),
            'use_pan_size' => isset($_POST['UsePanSize']),
            'use_shrinkage' => isset($_POST['UseShrinkage']),
            'leeway_days' => (int)($_POST['LeewayDays'] ?? 0),
            'locations' => $_POST['Locations'] ?? ['All']
        ];

        $summary = $mrp->runMRP($config);

        display_notification(_("MRP calculation completed successfully"));
        display_notification(sprintf(_("Generated %d planned orders for total quantity of %s"),
            $summary->getPlannedOrdersCount(),
            number_format($summary->getTotalPlannedQuantity(), 2)
        ));

    } catch (\Exception $e) {
        display_error(_("MRP calculation failed: ") . $e->getMessage());
    }
}

// Display form
start_form();

start_table(TABLESTYLE2);
table_section_title(_("MRP Calculation Parameters"));

check_row(_("Use MRP Demands:"), 'UseMRPDemands', true);
check_row(_("Use Reorder Level Demands:"), 'UseReorderLevelDemands', true);
check_row(_("Use EOQ:"), 'UseEOQ', true);
check_row(_("Use Pan Size:"), 'UsePanSize', true);
check_row(_("Use Shrinkage:"), 'UseShrinkage', true);

text_row(_("Leeway Days:"), 'LeewayDays', 0, 5, 5);

// Location selection
$locationRepo = new \FA\LocationRepository();
$locations = $locationRepo->getLocationsForSelect(true);

multi_select_row(_("Locations:"), 'Locations', $locations, null, _('Select locations to include in MRP'));

end_table(1);

submit_center('RunMRP', _("Run MRP Calculation"), true, '', 'default');

end_form();

// Display last run summary if available
$lastSummary = $mrp->getLastMRPSummary();
if ($lastSummary) {
    start_table(TABLESTYLE2);
    table_section_title(_("Last MRP Run Summary"));

    label_row(_("Run Time:"), $lastSummary->getRunTime()->format('Y-m-d H:i:s'));
    label_row(_("Planned Orders:"), (string)$lastSummary->getPlannedOrdersCount());
    label_row(_("Total Planned Quantity:"), number_format($lastSummary->getTotalPlannedQuantity(), 2));
    label_row(_("Parts Processed:"), (string)$lastSummary->getPartsProcessedCount());

    end_table(1);
}

end_page();
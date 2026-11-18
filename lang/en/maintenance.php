<?php

/*
|--------------------------------------------------------------------------
| Equipment and maintenance
|--------------------------------------------------------------------------
*/

return [

    'equipment_types' => [
        'filler' => 'Filler',
        'capper' => 'Capper',
        'labeller' => 'Labeller',
        'conveyor' => 'Conveyor',
        'ro_unit' => 'RO unit',
        'uv_steriliser' => 'UV steriliser',
        'ozone_generator' => 'Ozone generator',
        'storage_tank' => 'Storage tank',
        'compressor' => 'Compressor',
        'generator' => 'Generator',
        'other' => 'Other',
    ],

    'equipment_statuses' => [
        'operational' => 'Operational',
        'needs_attention' => 'Needs attention',
        'under_maintenance' => 'Under maintenance',
        'out_of_service' => 'Out of service',
    ],

    'work_order_types' => [
        'preventive' => 'Preventive',
        'corrective' => 'Corrective',
        'inspection' => 'Inspection',
    ],

    'work_order_sources' => [
        'plan' => 'Maintenance plan',
        'request' => 'Request',
        'breakdown' => 'Breakdown report',
    ],

    'work_order_statuses' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],

    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ],

    'pages' => [
        'equipment' => 'Equipment',
        'equipment_subtitle' => 'Machines on each line, their status and what is due next.',
        'work_orders' => 'Work orders',
        'work_orders_subtitle' => 'Preventive, corrective and inspection work, from open to done.',
        'calendar' => 'Maintenance calendar',
        'calendar_subtitle' => 'Preventive tasks coming due, including repeats, for the next eight weeks.',
    ],

    'line_status' => [
        'heading' => 'Line status',
        'downtime' => ':minutes min downtime in 30 days',
        'no_equipment' => 'No equipment recorded',
        'warning' => ':line has equipment that is :status: :equipment. The run can still be scheduled, but check with Maintenance first.',
    ],

    'complete' => [
        'return_question' => ':equipment is :status. Return it to operational?',
        'return_yes' => 'Yes, it is working again',
        'return_no' => 'No, leave the status as it is',
    ],

    'empty' => [
        'equipment' => 'No equipment is recorded yet.',
        'plans' => 'No preventive maintenance plans for this equipment.',
        'work_orders' => 'No work orders match these filters.',
        'history' => 'No work orders for this equipment yet.',
        'calendar' => 'No preventive tasks are due in the next eight weeks.',
    ],

    'request_button' => 'Create work order',
    'request_created' => 'Work order :reference created from this request.',

];

<?php

/*
|--------------------------------------------------------------------------
| In-app help
|--------------------------------------------------------------------------
|
| Interface text for the Help centre, the welcome tour, the "?" popovers on
| page titles and the empty states. The guides themselves live as Markdown
| in resources/help/.
|
*/

return [

    'centre' => [
        'title' => 'Help',
        'subtitle' => 'Guides for the parts of AquaOps your role can use.',
        'search_label' => 'Search the guides',
        'search_placeholder' => 'Search, for example "dispatch" or "stock count"',
        'search_results' => ':count result for “:query”|:count results for “:query”',
        'search_empty' => 'Nothing in your guides matches “:query”. Try a shorter word, or ask another department for help.',
        'guides_heading' => 'Guides',
        'general_heading' => 'General',
        'modules_heading' => 'Your modules',
        'capabilities_heading' => 'What you can do',
        'capabilities_intro' => 'Worked out from your role, :role, so it always matches what you can open.',
        'replay_tour' => 'Replay the welcome tour',
        'on_this_page' => 'On this page',
        'open_guide' => 'Open guide',
        'ask_for_access' => 'Ask for access',
    ],

    'levels' => [
        'none' => 'No access',
        'view' => 'View',
        'full' => 'Full',
    ],

    /*
     * One line per module and access level for the "What you can do" summary.
     */
    'capabilities' => [
        'none' => 'You can’t open this. Send the administrators an access request if you need it regularly.',
        'dashboard' => [
            'view' => 'See the dashboard widgets and alerts for the modules you can use.',
            'full' => 'See the dashboard widgets and alerts for the modules you can use.',
        ],
        'production' => [
            'view' => 'See schedules, open runs and production history.',
            'full' => 'Schedule runs, log production and complete runs.',
        ],
        'inventory' => [
            'view' => 'See stock levels, batches, leakage reports and stock movements.',
            'full' => 'Record stock-in, counts and adjustments, and review leakage reports.',
        ],
        'orders' => [
            'view' => 'See orders and customers.',
            'full' => 'Create, confirm, dispatch and deliver orders, and manage customers.',
        ],
        'equipment' => [
            'view' => 'See equipment and maintenance work once that area is available.',
            'full' => 'Manage equipment and maintenance work once that area is available.',
        ],
        'forecasting' => [
            'view' => 'See tomorrow’s demand forecast and stock cover per product.',
            'full' => 'See forecasts and request a fresh forecast run.',
        ],
        'invoicing' => [
            'view' => 'See invoices, payments and what is overdue.',
            'full' => 'Raise and issue invoices, record payments and void invoices.',
        ],
        'revenue' => [
            'view' => 'See what was invoiced, collected and is still owed, and export it as CSV.',
            'full' => 'See what was invoiced, collected and is still owed, and export it as CSV.',
        ],
        'requests' => [
            'view' => 'Read requests you sent and requests addressed to your team.',
            'full' => 'Send requests to other teams, reply, and act on requests sent to your team.',
        ],
        'users-roles' => [
            'view' => 'See the staff directory and each role’s access.',
            'full' => 'Invite staff, change roles, suspend accounts and edit permissions.',
        ],
        'settings' => [
            'view' => 'See organisation settings and the audit trail.',
            'full' => 'Change organisation settings and thresholds.',
        ],
        'error-logs' => [
            'view' => 'See recorded errors and their references.',
            'full' => 'See recorded errors and mark them resolved with a note.',
        ],
    ],

    /*
     * The "?" popover next to page titles: two sentences and the guide it links to.
     */
    'popover' => [
        'button' => 'Help for this page',
        'link' => 'Read the guide',
        'pages' => [
            'dashboard' => ['guide' => 'dashboard', 'text' => 'Your dashboard summarises the modules your role can use and lists what needs attention first. Use Customise to choose and reorder the widgets you see.'],
            'production' => ['guide' => 'production', 'text' => 'Production covers the daily schedule, the runs on each line and what was produced. Schedule runs ahead, then log output against each run as it happens.'],
            'inventory' => ['guide' => 'inventory', 'text' => 'Inventory shows stock on hand per product and warehouse line, and every movement that changed it. Record stock-in, counts and adjustments here so stock levels stay true.'],
            'orders' => ['guide' => 'orders', 'text' => 'Orders track customer demand from draft to delivery. Confirming reserves stock and dispatching deducts it, oldest batch first.'],
            'invoicing' => ['guide' => 'invoicing', 'text' => 'Invoices are raised from dispatched orders and record what each customer owes. Issued invoices are locked, so corrections are made by voiding and reissuing.'],
            'revenue' => ['guide' => 'revenue', 'text' => 'Revenue shows what was invoiced, what was collected and what is still owed. It is read only and built from invoices and payments.'],
            'forecasting' => ['guide' => 'forecasting', 'text' => 'Forecasting shows tomorrow’s expected demand per product from the forecast service. When a forecast is missing, the page explains why.'],
            'requests' => ['guide' => 'requests', 'text' => 'Requests let you ask another team to act without needing access to their area. Every request has an owner, a status and a thread for replies.'],
            'users-roles' => ['guide' => 'users-roles', 'text' => 'This is where staff accounts and their roles are managed. Each role has None, View or Full access to every module.'],
            'settings' => ['guide' => 'settings', 'text' => 'Settings hold organisation details, operating thresholds and defaults. Every change is recorded in the audit trail.'],
            'error-logs' => ['guide' => 'error-logs', 'text' => 'Error logs list the errors people saw, grouped with a reference code. Use the reference a colleague quotes to find what went wrong.'],
        ],
    ],

    /*
     * Welcome tour. Steps whose target the user cannot see are dropped before rendering.
     */
    'tour' => [
        'label' => 'Welcome tour',
        'step_count' => 'Step :current of :total',
        'next' => 'Next',
        'back' => 'Back',
        'skip' => 'Skip tour',
        'finish' => 'Finish',
        'close' => 'Close tour',
        'steps' => [
            'welcome' => [
                'title' => 'Welcome to AquaOps, :name',
                'body' => 'You’re signed in as :role. This short tour shows where things are. It takes under a minute, and you can replay it from Help.',
            ],
            'sidebar' => [
                'title' => 'Your modules',
                'body' => 'The sidebar lists everything your role can open: :modules. Areas you don’t have access to are hidden.',
            ],
            'requests' => [
                'title' => 'Ask other teams',
                'body' => 'Need another team to check stock, run production or fix a record? Send them a request here instead of asking for access to their area.',
            ],
            'notifications' => [
                'title' => 'Notifications',
                'body' => 'The bell shows alerts for your work, such as low stock, replies to your requests and overdue items. A number means something is unread.',
            ],
            'profile' => [
                'title' => 'Your profile',
                'body' => 'Open this menu for your profile, notification choices, Help and signing out.',
            ],
            'dashboard' => [
                'title' => 'Your dashboard',
                'body' => 'The dashboard opens first. It shows what needs attention in your modules, and you can customise its widgets.',
            ],
            'help' => [
                'title' => 'Help is always close',
                'body' => 'Select the ? next to a page title for a quick explanation, or open Help from the footer or your profile menu for full guides.',
            ],
        ],
    ],

    /*
     * Empty lists: what the list is, and the action that fills it (shown only with permission).
     */
    'empty' => [
        'filtered' => 'Nothing matches these filters. Try clearing them.',
        'orders' => ['title' => 'No orders yet', 'body' => 'Customer orders appear here from draft to delivery.', 'action' => 'Create an order'],
        'customers' => ['title' => 'No customers yet', 'body' => 'Customers are the shops, distributors and institutions you sell to.', 'action' => 'Add a customer'],
        'invoices' => ['title' => 'No invoices yet', 'body' => 'Invoices are raised from dispatched orders and track what each customer owes.', 'action' => 'Go to orders'],
        'production_runs' => ['title' => 'No runs scheduled for this day', 'body' => 'Runs are planned on the production schedule, then logged here as they happen.', 'action' => 'Open the schedule'],
        'production_history' => ['title' => 'No completed runs in this period', 'body' => 'Finished runs appear here with their output and any process loss.', 'action' => 'Open the schedule'],
        'stock_lines' => ['title' => 'No stock recorded yet', 'body' => 'Stock lines appear when bottled stock is received into the warehouse.', 'action' => 'Record stock-in'],
        'batches' => ['title' => 'No batches in this period', 'body' => 'A batch is created each time a production run is completed.', 'action' => 'Open production runs'],
        'leakage' => ['title' => 'No leakage reports', 'body' => 'Reports appear when output or stock falls short of what was expected.'],
        'movements' => ['title' => 'No stock movements in this period', 'body' => 'Every stock-in, sale, count and adjustment is listed here.'],
        'notifications' => ['title' => 'You’re all caught up', 'body' => 'Alerts for your work, such as low stock or replies to your requests, will appear here.'],
        'error_logs' => ['title' => 'No errors recorded', 'body' => 'Errors people see are logged here with a reference code, so they can be traced.'],
        'requests' => ['title' => 'Nothing here yet', 'body' => 'Requests let you ask another team to act without needing access to their area.', 'action' => 'Send a request'],
    ],

];

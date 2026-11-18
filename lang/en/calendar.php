<?php

/*
|--------------------------------------------------------------------------
| Business Calendar
|--------------------------------------------------------------------------
|
| Text for the Business Calendar page and the forecast context it feeds.
|
*/

return [

    'title' => 'Business Calendar',
    'subtitle' => 'Public holidays, school terms and promotions. The forecast reads these for every product and day.',

    'tabs' => [
        'month' => 'Month',
        'holidays' => 'Public holidays',
        'terms' => 'School terms',
        'promotions' => 'Promotions',
    ],

    'legend' => [
        'holiday' => 'Public holiday',
        'term' => 'School term',
        'promotion' => 'Promotion',
    ],

    'term_label' => 'Term :term',
    'previous_month' => 'Previous month',
    'next_month' => 'Next month',
    'this_month' => 'This month',
    'weekdays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],

    'missing_terms' => 'No school terms are recorded for :years. Days in those years count as outside term, which can lower the forecast. Add the terms once the dates are published.',

    'add' => [
        'holiday' => 'Add holiday',
        'school_term' => 'Add school term',
        'promotion' => 'Add promotion',
    ],

    'edit' => [
        'holiday' => 'Edit holiday',
        'school_term' => 'Edit school term',
        'promotion' => 'Edit promotion',
    ],

    'delete' => [
        'title' => 'Delete this entry?',
        'body' => 'The forecast context for the dates it covered is rebuilt straight away.',
        'confirm' => 'Delete',
    ],

    'fields' => [
        'name' => 'Name',
        'date' => 'Date',
        'recurring' => 'Same date every year',
        'recurring_hint' => 'Use for fixed-date holidays such as 1 May. Movable holidays such as Good Friday or Eid are entered for each year once announced.',
        'year' => 'Year',
        'term' => 'Term',
        'start_date' => 'Starts',
        'end_date' => 'Ends',
        'product' => 'Product',
        'all_products' => 'All products',
        'type' => 'Type',
        'discount_percent' => 'Discount (%)',
        'notes' => 'Notes',
        'reason' => 'Reason for changing history',
        'reason_hint' => 'Recorded in the audit log.',
    ],

    'promotion_note' => 'A promotion only tells the forecast to expect higher demand on these days. It does not change order prices.',

    'promotion_types' => [
        'price_discount' => 'Price discount',
        'bundle' => 'Bundle',
        'display' => 'In-store display',
        'other' => 'Other',
    ],

    'promotion_status' => [
        'planned' => 'Planned',
        'active' => 'Active',
        'ended' => 'Ended',
    ],

    'every_year' => 'Every year',
    'one_off' => 'This year only',
    'all_products' => 'All products',

    'actions' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'empty' => [
        'holidays' => 'No public holidays are recorded.',
        'terms' => 'No school terms are recorded yet. Add each term once the Ministry of Education publishes the dates.',
        'promotions' => 'No promotions are recorded. Add one so the forecast expects the extra demand.',
    ],

    'forecasting' => [
        'promotion' => 'Promotion planned',
        'holiday' => 'Public holiday',
        'temperature_source' => 'Temperature: :source',
        'temperature_sources' => [
            'monthly_mean' => 'monthly average from the model',
        ],
        'manage' => 'Manage the Business Calendar',
    ],

];

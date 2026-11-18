<?php

namespace App\Services\Demo;

/**
 * The invented people, customers, machines and stories the demo business is built from. Every name here
 * is made up; none is a real company, school or person.
 */
final class DemoCatalog
{
    public const EMAIL_DOMAIN = 'demo.aquaops.test';

    /**
     * Shared by every demo account and printed by demo:seed. Local and testing only.
     */
    public const PASSWORD = 'aquaops-demo';

    /**
     * @var list<array{name: string, email: string, role: string}>
     */
    public const USERS = [
        ['name' => 'Wanjiru Kamau (Demo)', 'email' => 'wanjiru.admin', 'role' => 'administrator'],
        ['name' => 'Brian Otieno (Demo)', 'email' => 'brian.admin', 'role' => 'administrator'],
        ['name' => 'Achieng Odhiambo (Demo)', 'email' => 'achieng.prodmgr', 'role' => 'production-manager'],
        ['name' => 'Kevin Mutua (Demo)', 'email' => 'kevin.prodmgr', 'role' => 'production-manager'],
        ['name' => 'Daniel Kiprop (Demo)', 'email' => 'daniel.production', 'role' => 'production-staff'],
        ['name' => 'Faith Wambui (Demo)', 'email' => 'faith.production', 'role' => 'production-staff'],
        ['name' => 'Grace Njeri (Demo)', 'email' => 'grace.inventory', 'role' => 'inventory-staff'],
        ['name' => 'Samuel Omondi (Demo)', 'email' => 'samuel.inventory', 'role' => 'inventory-staff'],
        ['name' => 'Peter Kariuki (Demo)', 'email' => 'peter.maintenance', 'role' => 'maintenance-staff'],
        ['name' => 'Mercy Chebet (Demo)', 'email' => 'mercy.maintenance', 'role' => 'maintenance-staff'],
    ];

    /**
     * How each kind of customer buys: relative volume, which SKUs, how often (days), payment terms and how
     * it pays (method => weight).
     *
     * @var array<string, array{weight: array{0: float, 1: float}, skus: list<string>, every: list<int>, terms: list<int>, methods: array<string, int>}>
     */
    public const CUSTOMER_PROFILES = [
        'distributor' => ['weight' => [5.0, 9.0], 'skus' => ['500ml', '1L', '5L', '10L', '20L'], 'every' => [1, 2], 'terms' => [30], 'methods' => ['bank_transfer' => 70, 'mpesa' => 30]],
        'retailer' => ['weight' => [1.0, 2.0], 'skus' => ['500ml', '1L', '5L', '10L'], 'every' => [2, 3], 'terms' => [7, 14], 'methods' => ['mpesa' => 70, 'cash' => 30]],
        'office' => ['weight' => [0.6, 1.0], 'skus' => ['20L', '500ml', '1L'], 'every' => [5], 'terms' => [30], 'methods' => ['bank_transfer' => 50, 'mpesa' => 50]],
        'school' => ['weight' => [1.5, 3.0], 'skus' => ['500ml', '20L', '10L'], 'every' => [3], 'terms' => [30, 60], 'methods' => ['bank_transfer' => 60, 'mpesa' => 40]],
    ];

    /**
     * Listed so that the first few already include every kind of customer.
     *
     * @var list<array{name: string, type: string, area: string}>
     */
    public const CUSTOMERS = [
        ['name' => 'Savanna Springs Distributors', 'type' => 'distributor', 'area' => 'Industrial Area, Nairobi'],
        ['name' => 'Mama Wanjiku Mini-Mart', 'type' => 'retailer', 'area' => 'Kawangware, Nairobi'],
        ['name' => 'Kilimani Business Park', 'type' => 'office', 'area' => 'Kilimani, Nairobi'],
        ['name' => 'Ridgeview Valley Academy', 'type' => 'school', 'area' => 'Ngong Road, Nairobi'],
        ['name' => 'Baraka Corner Shop', 'type' => 'retailer', 'area' => 'Kayole, Nairobi'],
        ['name' => 'Rift Valley Water Traders', 'type' => 'distributor', 'area' => 'Nakuru'],
        ['name' => 'Neema Fresh Supermarket', 'type' => 'retailer', 'area' => 'Rongai, Kajiado'],
        ['name' => 'Upper Hill Advisory Partners', 'type' => 'office', 'area' => 'Upper Hill, Nairobi'],
        ['name' => 'St. Imani Girls High School', 'type' => 'school', 'area' => 'Limuru, Kiambu'],
        ['name' => 'Jirani Grocers', 'type' => 'retailer', 'area' => 'Githurai 45, Nairobi'],
        ['name' => 'Pwani Coast Beverage Supply', 'type' => 'distributor', 'area' => 'Mombasa'],
        ['name' => 'Upendo Self-Service Store', 'type' => 'retailer', 'area' => 'Kitengela, Kajiado'],
        ['name' => 'Mombasa Road Logistics Centre', 'type' => 'office', 'area' => 'Mombasa Road, Nairobi'],
        ['name' => 'Mwangaza Primary School', 'type' => 'school', 'area' => 'Kiambu'],
        ['name' => 'Kilele Fresh Mart', 'type' => 'retailer', 'area' => 'Ngong, Kajiado'],
        ['name' => 'Lakeside Drinks Wholesalers', 'type' => 'distributor', 'area' => 'Kisumu'],
        ['name' => 'Sokoni Express Store', 'type' => 'retailer', 'area' => 'Embakasi, Nairobi'],
        ['name' => 'Gigiri Conference Suites', 'type' => 'office', 'area' => 'Gigiri, Nairobi'],
        ['name' => 'Baobab Junior School', 'type' => 'school', 'area' => 'Kitengela, Kajiado'],
        ['name' => 'Bidii Traders', 'type' => 'retailer', 'area' => 'Kangemi, Nairobi'],
        ['name' => 'Mt Kenya Fresh Supplies', 'type' => 'distributor', 'area' => 'Nyeri'],
        ['name' => 'Faraja Groceries', 'type' => 'retailer', 'area' => 'Kahawa West, Nairobi'],
        ['name' => 'Riverside Drive Law Chambers', 'type' => 'office', 'area' => 'Riverside, Nairobi'],
        ['name' => 'Lake View Boys High School', 'type' => 'school', 'area' => 'Naivasha, Nakuru'],
        ['name' => 'Amani Mini Supermarket', 'type' => 'retailer', 'area' => 'Juja, Kiambu'],
        ['name' => 'Athi River Bulk Water Agencies', 'type' => 'distributor', 'area' => 'Mlolongo, Machakos'],
        ['name' => 'Nuru Shop & Deli', 'type' => 'retailer', 'area' => 'Westlands, Nairobi'],
        ['name' => 'Lavington Medical Plaza', 'type' => 'office', 'area' => 'Lavington, Nairobi'],
        ['name' => 'Kijani Preparatory School', 'type' => 'school', 'area' => 'Runda, Nairobi'],
        ['name' => 'Kona Kibanda Stores', 'type' => 'retailer', 'area' => 'Umoja, Nairobi'],
        ['name' => 'Eldoret Highland Distributors', 'type' => 'distributor', 'area' => 'Eldoret'],
        ['name' => 'Mwangaza Family Supermarket', 'type' => 'retailer', 'area' => 'Kikuyu, Kiambu'],
        ['name' => 'Westlands Tech Hub Offices', 'type' => 'office', 'area' => 'Westlands, Nairobi'],
        ['name' => 'Nyota Day Secondary School', 'type' => 'school', 'area' => 'Ruaka, Kiambu'],
        ['name' => 'Pendo Fresh Corner', 'type' => 'retailer', 'area' => 'South B, Nairobi'],
        ['name' => 'Thika Road Wholesale Hub', 'type' => 'distributor', 'area' => 'Ruiru, Kiambu'],
        ['name' => 'Riverbank Kiosk Supplies', 'type' => 'retailer', 'area' => 'Kasarani, Nairobi'],
        ['name' => 'Karen Office Village', 'type' => 'office', 'area' => 'Karen, Nairobi'],
        ['name' => 'Tumaini Montessori Centre', 'type' => 'school', 'area' => 'Lang\'ata, Nairobi'],
        ['name' => 'Zawadi Quick Mart', 'type' => 'retailer', 'area' => 'Donholm, Nairobi'],
    ];

    /**
     * Where each SKU is bottled and stored.
     *
     * @var array<string, array{line: string, location: string, time: string}>
     */
    public const SKU_PRODUCTION = [
        '500ml' => ['line' => 'Line 01', 'location' => 'Bay A1', 'time' => '06:00'],
        '1L' => ['line' => 'Line 01', 'location' => 'Bay A2', 'time' => '07:30'],
        '20L' => ['line' => 'Line 02', 'location' => 'Bay D1', 'time' => '06:00'],
        '10L' => ['line' => 'Line 02', 'location' => 'Bay C1', 'time' => '07:00'],
        '5L' => ['line' => 'Line 02', 'location' => 'Bay B1', 'time' => '08:00'],
    ];

    /**
     * @var list<array{code: string, name: string, type: string, line: ?string, location: string, manufacturer: string, model: string}>
     */
    public const EQUIPMENT = [
        ['code' => 'L1-FIL-01', 'name' => 'Rotary filler (500ml / 1L)', 'type' => 'filler', 'line' => 'Line 01', 'location' => 'Bottling hall', 'manufacturer' => 'Tana Packaging Systems', 'model' => 'RF-24'],
        ['code' => 'L1-CAP-01', 'name' => 'Screw capper', 'type' => 'capper', 'line' => 'Line 01', 'location' => 'Bottling hall', 'manufacturer' => 'Tana Packaging Systems', 'model' => 'SC-8'],
        ['code' => 'L1-LAB-01', 'name' => 'Wrap-around labeller', 'type' => 'labeller', 'line' => 'Line 01', 'location' => 'Bottling hall', 'manufacturer' => 'Kilifi Label Works', 'model' => 'WL-200'],
        ['code' => 'L1-CNV-01', 'name' => 'Bottle conveyor', 'type' => 'conveyor', 'line' => 'Line 01', 'location' => 'Bottling hall', 'manufacturer' => 'Rift Valley Engineering', 'model' => 'BC-30'],
        ['code' => 'L2-FIL-01', 'name' => 'Large-format filler (5L to 20L)', 'type' => 'filler', 'line' => 'Line 02', 'location' => 'Bulk hall', 'manufacturer' => 'Tana Packaging Systems', 'model' => 'LF-6'],
        ['code' => 'L2-CAP-01', 'name' => 'Press capper', 'type' => 'capper', 'line' => 'Line 02', 'location' => 'Bulk hall', 'manufacturer' => 'Tana Packaging Systems', 'model' => 'PC-4'],
        ['code' => 'L2-LAB-01', 'name' => 'Sleeve labeller', 'type' => 'labeller', 'line' => 'Line 02', 'location' => 'Bulk hall', 'manufacturer' => 'Kilifi Label Works', 'model' => 'SL-90'],
        ['code' => 'L2-CNV-01', 'name' => 'Jerrican conveyor', 'type' => 'conveyor', 'line' => 'Line 02', 'location' => 'Bulk hall', 'manufacturer' => 'Rift Valley Engineering', 'model' => 'JC-12'],
        ['code' => 'L2-WSH-01', 'name' => '20L bottle washer', 'type' => 'other', 'line' => 'Line 02', 'location' => 'Bulk hall', 'manufacturer' => 'Rift Valley Engineering', 'model' => 'BW-120'],
        ['code' => 'PL-RO-01', 'name' => 'Reverse osmosis unit', 'type' => 'ro_unit', 'line' => null, 'location' => 'Treatment room', 'manufacturer' => 'Athi Water Treatment Co.', 'model' => 'RO-8000'],
        ['code' => 'PL-UV-01', 'name' => 'UV steriliser', 'type' => 'uv_steriliser', 'line' => null, 'location' => 'Treatment room', 'manufacturer' => 'Athi Water Treatment Co.', 'model' => 'UV-55'],
        ['code' => 'PL-OZN-01', 'name' => 'Ozone generator', 'type' => 'ozone_generator', 'line' => null, 'location' => 'Treatment room', 'manufacturer' => 'Athi Water Treatment Co.', 'model' => 'OZ-20'],
        ['code' => 'PL-TNK-01', 'name' => 'Treated water tank (20,000 L)', 'type' => 'storage_tank', 'line' => null, 'location' => 'Tank yard', 'manufacturer' => 'Kerio Tanks', 'model' => 'TT-20K'],
        ['code' => 'PL-CMP-01', 'name' => 'Air compressor', 'type' => 'compressor', 'line' => null, 'location' => 'Utility room', 'manufacturer' => 'Rift Valley Engineering', 'model' => 'AC-15'],
        ['code' => 'PL-GEN-01', 'name' => 'Standby generator (100 kVA)', 'type' => 'generator', 'line' => null, 'location' => 'Generator house', 'manufacturer' => 'Kerio Power', 'model' => 'SG-100'],
    ];

    /**
     * @var list<array{equipment: string, task: string, every: int, checklist: list<string>}>
     */
    public const PLANS = [
        ['equipment' => 'L1-FIL-01', 'task' => 'Clean and sanitise filling nozzles', 'every' => 7, 'checklist' => ['Drain and rinse nozzles', 'Run CIP sanitiser cycle', 'Check for drips at rest']],
        ['equipment' => 'L1-FIL-01', 'task' => 'Replace filler valve seals', 'every' => 90, 'checklist' => ['Isolate and drain filler', 'Replace valve seals', 'Pressure test', 'Record fill-level check']],
        ['equipment' => 'L2-FIL-01', 'task' => 'Clean and sanitise filling heads', 'every' => 7, 'checklist' => ['Rinse filling heads', 'Run CIP sanitiser cycle', 'Check head alignment']],
        ['equipment' => 'L1-CAP-01', 'task' => 'Check capping torque', 'every' => 14, 'checklist' => ['Measure torque on 10 bottles', 'Adjust clutch if out of range']],
        ['equipment' => 'L2-CAP-01', 'task' => 'Check press capper alignment', 'every' => 14, 'checklist' => ['Check press head alignment', 'Inspect cap chute for wear']],
        ['equipment' => 'L1-LAB-01', 'task' => 'Clean glue rollers and check sensors', 'every' => 30, 'checklist' => ['Clean glue rollers', 'Test label sensor', 'Check label registration']],
        ['equipment' => 'L2-LAB-01', 'task' => 'Clean sleeve applicator and check sensors', 'every' => 30, 'checklist' => ['Clean applicator', 'Test sleeve sensor', 'Check heat tunnel temperature']],
        ['equipment' => 'L1-CNV-01', 'task' => 'Lubricate conveyor chain', 'every' => 30, 'checklist' => ['Lubricate chain', 'Check guide rails', 'Check motor current']],
        ['equipment' => 'L2-CNV-01', 'task' => 'Lubricate jerrican conveyor', 'every' => 30, 'checklist' => ['Lubricate chain', 'Check rollers', 'Check motor current']],
        ['equipment' => 'L2-WSH-01', 'task' => 'Descale bottle washer', 'every' => 30, 'checklist' => ['Run descaling cycle', 'Check spray nozzles', 'Check caustic concentration']],
        ['equipment' => 'PL-RO-01', 'task' => 'Flush RO membranes', 'every' => 14, 'checklist' => ['Record feed and permeate TDS', 'Flush membranes', 'Check pressure differential']],
        ['equipment' => 'PL-RO-01', 'task' => 'Replace pre-filter cartridges', 'every' => 60, 'checklist' => ['Isolate pre-filters', 'Replace cartridges', 'Record pressure before and after']],
        ['equipment' => 'PL-UV-01', 'task' => 'Clean quartz sleeve and check lamp hours', 'every' => 30, 'checklist' => ['Clean quartz sleeve', 'Record lamp hours', 'Check UV intensity reading']],
        ['equipment' => 'PL-OZN-01', 'task' => 'Service ozone generator', 'every' => 90, 'checklist' => ['Clean dielectric cells', 'Check ozone output', 'Inspect air dryer']],
        ['equipment' => 'PL-TNK-01', 'task' => 'Clean and disinfect storage tank', 'every' => 90, 'checklist' => ['Drain tank', 'Scrub and disinfect', 'Rinse and sample water']],
        ['equipment' => 'PL-CMP-01', 'task' => 'Drain condensate and change oil', 'every' => 60, 'checklist' => ['Drain condensate', 'Change oil', 'Replace air intake filter']],
        ['equipment' => 'PL-GEN-01', 'task' => 'Generator service and load test', 'every' => 180, 'checklist' => ['Change oil and filters', 'Check battery', 'Run one-hour load test']],
    ];

    /**
     * The plan left overdue at the end of the period.
     */
    public const OVERDUE_PLAN_TASK = 'Replace pre-filter cartridges';

    /**
     * @var list<string>
     */
    public const BREAKDOWNS = [
        'Filler stopped mid-run: level sensor fault',
        'Capper jamming on every tenth bottle',
        'Conveyor motor tripped on overload',
        'Labeller misaligning labels after a changeover',
        'Bottle washer pump leaking at the seal',
        'RO high-pressure pump making a grinding noise',
        'Compressor not reaching cut-out pressure',
        'Filler valve sticking open, bottles overflowing',
        'Conveyor chain snapped near the capper',
        'Sleeve labeller heat tunnel not reaching temperature',
    ];

    /**
     * @var list<string>
     */
    public const PARTS = [
        'Valve seal kit',
        'Proximity sensor',
        'Drive belt',
        'Conveyor chain link set',
        'Pump mechanical seal',
        'Contactor and overload relay',
        'Heater element',
        'Pre-filter cartridges (x4)',
    ];

    /**
     * Promotions placed by share of the period; the last two are the one running now and one planned.
     *
     * @var list<array{name: string, sku: ?string, type: string, discount: ?int, at: float, days: int}>
     */
    public const PROMOTIONS = [
        ['name' => 'New Year 20L refill offer', 'sku' => '20L', 'type' => 'price_discount', 'discount' => 10, 'at' => 0.05, 'days' => 10],
        ['name' => 'Back-to-school 500ml packs', 'sku' => '500ml', 'type' => 'bundle', 'discount' => null, 'at' => 0.2, 'days' => 14],
        ['name' => 'Easter family 5L deal', 'sku' => '5L', 'type' => 'price_discount', 'discount' => 8, 'at' => 0.35, 'days' => 7],
        ['name' => 'Supermarket end-cap display', 'sku' => '1L', 'type' => 'display', 'discount' => null, 'at' => 0.5, 'days' => 21],
        ['name' => 'Mid-year distributor bundle', 'sku' => null, 'type' => 'bundle', 'discount' => null, 'at' => 0.65, 'days' => 14],
        ['name' => 'Heatwave 10L discount', 'sku' => '10L', 'type' => 'price_discount', 'discount' => 12, 'at' => 0.8, 'days' => 10],
        ['name' => 'Office hydration month', 'sku' => '20L', 'type' => 'other', 'discount' => null, 'at' => 0.97, 'days' => 14],
        ['name' => 'Festive season 500ml bundle', 'sku' => '500ml', 'type' => 'bundle', 'discount' => null, 'at' => 1.05, 'days' => 14],
    ];
}

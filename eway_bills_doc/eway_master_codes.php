<?php
/**
 * e-Way Bill Master Codes (from e_Waybill_preparation_tools - Master Codes.csv)
 * Use for dropdowns and validation.
 */

return [
    // Supply Type: Inward / Outward
    'supplyType' => [
        'O' => 'Outward',
        'I' => 'Inward',
    ],

    // Sub Supply Type
    'subSupplyType' => [
        '1' => 'Supply',
        '2' => 'Import',
        '3' => 'Export',
        '4' => 'Job Work',
        '5' => 'For Own Use',
        '6' => 'Job work Returns',
        '7' => 'Sales Return',
        '8' => 'Others',
        '9' => 'SKD/CKD/Lots',
        '10' => 'Line Sales',
        '11' => 'Recipient Not Known',
        '12' => 'Exhibition or Fairs',
    ],

    // Document Type
    'docType' => [
        'INV' => 'Tax Invoice',
        'BIL' => 'Bill of Supply',
        'BOE' => 'Bill of Entry',
        'CHL' => 'Delivery Challan',
        'OTH' => 'Others',
    ],

    // Transportation Mode
    'transMode' => [
        '1' => 'Road',
        '2' => 'Rail',
        '3' => 'Air',
        '4' => 'Ship',
        '5' => 'In Transit',
    ],

    // Vehicle Type (Regular / ODC)
    'vehicleType' => [
        'R' => 'Regular',
        'O' => 'ODC (Over Dimensional Cargo)',
    ],

    // Transaction Type
    'transactionType' => [
        1 => 'Regular',
        2 => 'Bill To - Ship To',
        3 => 'Bill From - Dispatch From',
        4 => 'Combination of 2 and 3',
    ],

    // Unit (UQC) - for item list
    'qtyUnit' => [
        'BAG' => 'BAGS',
        'BAL' => 'BALE',
        'BDL' => 'BUNDLES',
        'BKL' => 'BUCKLES',
        'BOU' => 'BILLION OF UNITS',
        'BOX' => 'BOX',
        'BTL' => 'BOTTLES',
        'BUN' => 'BUNCHES',
        'CAN' => 'CANS',
        'CBM' => 'CUBIC METERS',
        'CCM' => 'CUBIC CENTIMETERS',
        'CMS' => 'CENTI METERS',
        'CTN' => 'CARTONS',
        'DOZ' => 'DOZENS',
        'DRM' => 'DRUMS',
        'GGK' => 'GREAT GROSS',
        'GMS' => 'GRAMMES',
        'GRS' => 'GROSS',
        'GYD' => 'GROSS YARDS',
        'KGS' => 'KILOGRAMS',
        'KLR' => 'KILOLITRE',
        'KME' => 'KILOMETRE',
        'LTR' => 'LITRES',
        'MTR' => 'METERS',
        'MLT' => 'MILILITRE',
        'MTS' => 'METRIC TON',
        'NOS' => 'NUMBERS',
        'OTH' => 'OTHERS',
        'PAC' => 'PACKS',
        'PCS' => 'PIECES',
        'PRS' => 'PAIRS',
        'QTL' => 'QUINTAL',
        'ROL' => 'ROLLS',
        'SET' => 'SETS',
        'SQF' => 'SQUARE FEET',
        'SQM' => 'SQUARE METERS',
        'SQY' => 'SQUARE YARDS',
        'TBS' => 'TABLETS',
        'TGM' => 'TEN GROSS',
        'THD' => 'THOUSANDS',
        'TON' => 'TONNES',
        'TUB' => 'TUBES',
        'UGS' => 'US GALLONS',
        'UNT' => 'UNITS',
        'YDS' => 'YARDS',
    ],

    // GST State Codes (Code => State/UT Name) - for fromStateCode, toStateCode, actFromStateCode, actToStateCode
    'stateCode' => [
        1 => 'Jammu & Kashmir',
        2 => 'Himachal Pradesh',
        3 => 'Punjab',
        4 => 'Chandigarh',
        5 => 'Uttarakhand',
        6 => 'Haryana',
        7 => 'Delhi',
        8 => 'Rajasthan',
        9 => 'Uttar Pradesh',
        10 => 'Bihar',
        11 => 'Sikkim',
        12 => 'Arunachal Pradesh',
        13 => 'Nagaland',
        14 => 'Manipur',
        15 => 'Mizoram',
        16 => 'Tripura',
        17 => 'Meghalaya',
        18 => 'Assam',
        19 => 'West Bengal',
        20 => 'Jharkhand',
        21 => 'Odisha',
        22 => 'Chhattisgarh',
        23 => 'Madhya Pradesh',
        24 => 'Gujarat',
        25 => 'Daman & Diu',
        26 => 'Dadra & Nagar Haveli',
        27 => 'Maharashtra',
        28 => 'Andhra Pradesh (Old)',
        29 => 'Karnataka',
        30 => 'Goa',
        31 => 'Lakshadweep',
        32 => 'Kerala',
        33 => 'Tamil Nadu',
        34 => 'Puducherry',
        35 => 'Andaman & Nicobar Islands',
        36 => 'Telangana',
        37 => 'Andhra Pradesh',
        38 => 'Ladakh',
        96 => 'Other Country',
        99 => 'Other Territory / Centre',
    ],
];

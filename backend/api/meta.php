<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
check_request_origin();

json_response([
    'datorii_types' => DATORII_TYPES,
    'asset_types' => ASSET_TYPES,
    'judete' => JUDETE,
    'domenii' => DOMENII,
    'sexe' => SEXE,
]);

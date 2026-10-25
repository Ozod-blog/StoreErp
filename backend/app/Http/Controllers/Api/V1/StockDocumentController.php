<?php

namespace App\Http\Controllers\Api\V1;

/** Chiqim, omborlararo ko'chirish, inventarizatsiya — ruxsatlar: warehouses.* */
class StockDocumentController extends AbstractDocumentController
{
    protected array $types = ['issue', 'transfer', 'inventory'];
}

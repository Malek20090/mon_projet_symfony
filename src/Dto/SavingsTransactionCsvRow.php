<?php

namespace App\Dto;

class SavingsTransactionCsvRow
{
    public function __construct(
        public int $id,
        public string $type,
        public string $amount,
        public string $date,
        public string $description
    ) {
    }
}

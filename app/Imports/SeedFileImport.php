<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SeedFileImport implements ToArray, WithHeadingRow
{
    /**
     * @param  array<string, mixed>  $array
     */
    public function array(array $array): void
    {
        // Data is read via Excel::toArray(); this import is used for row structure only (WithHeadingRow).
    }
}

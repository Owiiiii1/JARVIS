<?php

namespace App\Services\Reports;

use RuntimeException;

class ScheduledReportException extends RuntimeException
{
    /**
     * @param  list<mixed>  $candidates
     */
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly array $candidates = [],
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}

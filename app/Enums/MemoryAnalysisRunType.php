<?php

namespace App\Enums;

enum MemoryAnalysisRunType: string
{
    case Turn = 'turn';
    case Summary = 'summary';
    case Profile = 'profile';
    case Backfill = 'backfill';
}

<?php

namespace App\Enums;

enum ScheduledReportType: string
{
    case DailyPlan = 'daily_plan';
    case TomorrowPlan = 'tomorrow_plan';
    case MailGroupsDigest = 'mail_groups_digest';
    case CustomComposite = 'custom_composite';
}

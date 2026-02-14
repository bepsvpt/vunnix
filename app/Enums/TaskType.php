<?php

namespace App\Enums;

enum TaskType: string
{
    case CodeReview = 'code_review';
    case IssueDiscussion = 'issue_discussion';
    case FeatureDev = 'feature_dev';
    case UiAdjustment = 'ui_adjustment';
    case PrdCreation = 'prd_creation';
    case SecurityAudit = 'security_audit';
}

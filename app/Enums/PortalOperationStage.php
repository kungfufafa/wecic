<?php

declare(strict_types=1);

namespace App\Enums;

enum PortalOperationStage: string
{
    case Connecting = 'connecting';
    case Relogin = 'relogin';
    case FetchingSchedule = 'fetching_schedule';
    case Completed = 'completed';
    case Failed = 'failed';
    case FileReceived = 'file_received';
    case PortalUploading = 'portal_uploading';
    case ValidationSucceeded = 'validation_succeeded';
    case SubmissionSaved = 'submission_saved';
}

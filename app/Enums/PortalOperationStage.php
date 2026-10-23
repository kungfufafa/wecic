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

    /**
     * Final stage of an upload whose outcome the portal has not settled
     * (no reply, or a page that neither shows nor rules out the file): the
     * student checks the page before uploading again.
     */
    case Unverified = 'unverified';

    /**
     * Stages after which the operation reports nothing more.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Unverified], true);
    }
}

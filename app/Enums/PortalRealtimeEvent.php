<?php

declare(strict_types=1);

namespace App\Enums;

enum PortalRealtimeEvent: string
{
    case SyncStatus = 'portal.sync.status';
    case DataInvalidated = 'portal.data.invalidated';
    case UploadProgress = 'portal.upload.progress';
    case SessionNotice = 'portal.session.notice';
    case Notification = 'portal.notification';
}

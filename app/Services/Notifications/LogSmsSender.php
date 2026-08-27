<?php

namespace App\Services\Notifications;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogSmsSender implements SmsSender
{
    public function send(string $recipient, string $message): string
    {
        $reference = 'sms_'.Str::lower(Str::random(12));
        Log::info('SMS notification', compact('reference', 'recipient', 'message'));

        return $reference;
    }
}

<?php

namespace App\Jobs;

use App\Contracts\SmsSender;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendSmsNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $recipient,
        public string $template,
        public string $message,
    ) {}

    public function handle(SmsSender $sender): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContext::class)->initialize($tenant);
        $reference = $sender->send($this->recipient, $this->message);
        DB::connection('tenant')->table('notification_logs')->insert([
            'id' => (string) Str::uuid(), 'channel' => 'sms', 'recipient' => $this->recipient,
            'template' => $this->template, 'message' => $this->message, 'provider' => 'log',
            'status' => 'sent', 'metadata' => json_encode(['reference' => $reference]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

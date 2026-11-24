<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Notifications\TestEmail;
use App\Pipstream\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Admin → Email (superadmin): the Resend API key and sender, stored encrypted. The key is
 *  write-only: the screen only ever sees its last 4 characters. */
class MailController extends Controller
{
    public function show()
    {
        return MailSettings::present();
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'resend_key' => ['nullable', 'string', 'max:200', 'starts_with:re_'],
            'clear_key' => ['sometimes', 'boolean'],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name' => ['required', 'string', 'max:80'],
            'test_to' => ['nullable', 'email', 'max:190'],
        ]);
        $s = MailSettings::stored();
        if (! empty($data['clear_key'])) {
            unset($s['resend_key']);
        } elseif (! empty($data['resend_key'])) {
            $s['resend_key'] = trim($data['resend_key']);
        }
        $s['from_address'] = strtolower($data['from_address']);
        $s['from_name'] = $data['from_name'];
        $s['test_to'] = $data['test_to'] ?? null;
        PlatformSetting::write(MailSettings::KEY, $s);
        MailSettings::apply();
        try {
            Artisan::call('queue:restart'); // queue workers pick up the new key on their next job
        } catch (Throwable) {
        }
        AuditLog::record($r->user()->id, 'mail_settings_changed', 'platform_setting', MailSettings::KEY,
            ['key_changed' => ! empty($data['resend_key']) || ! empty($data['clear_key']), 'from' => $s['from_address']]);

        return MailSettings::present();
    }

    /** Sends a test email to the signed-in superadmin right away, so any error shows here. */
    public function test(Request $r)
    {
        $to = $r->validate(['to' => ['nullable', 'email']])['to'] ?? null;
        MailSettings::apply();
        abort_if(MailSettings::source() === 'none', 422, 'Add a Resend API key first.');
        try {
            $to ? Notification::route('mail', $to)->notifyNow(new TestEmail) : $r->user()->notifyNow(new TestEmail);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Resend refused it: '.mb_substr($e->getMessage(), 0, 300)], 422);
        }
        $sent = $to ?: (config('mail.resend_test_to') ?: $r->user()->email);
        AuditLog::record($r->user()->id, 'mail_test', 'platform_setting', MailSettings::KEY, ['to' => $sent]);

        return ['ok' => true, 'to' => $sent];
    }
}

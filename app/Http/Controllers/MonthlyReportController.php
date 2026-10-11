<?php

namespace App\Http\Controllers;

use App\Mail\MonthlyItSecurityReportMail;
use App\Models\MonthlyReportSetting;
use App\Services\MonthlyItSecurityReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MonthlyReportController extends Controller
{
    public function settings()
    {
        $settings = MonthlyReportSetting::current();

        return view('monthly-reports.settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate(['manager_email' => ['required', 'email', 'max:255']]);
        $settings = MonthlyReportSetting::current();
        $settings->update(['manager_email' => $validated['manager_email']]);

        return back()->with('status', 'Monthly report recipient saved.');
    }

    public function rotateToken()
    {
        MonthlyReportSetting::current()->rotatePublicToken();

        return back()->with('status', 'Public report link rotated. The previous link no longer works.');
    }

    public function publicReport(string $token, MonthlyItSecurityReport $report)
    {
        $hash = hash('sha256', $token);
        $settings = MonthlyReportSetting::query()->where('public_token_hash', $hash)->first();
        abort_unless($settings && hash_equals((string) $settings->public_token_hash, $hash), 404);

        return response()->view('monthly-reports.public', ['report' => $report->build()])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
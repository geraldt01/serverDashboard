<?php

namespace App\Http\Controllers;

use App\Models\WebpageCheck;
use App\Services\WebpageHealthChecker;
use Illuminate\Http\Request;

class WebpageCheckController extends Controller
{
    public function __construct(private readonly WebpageHealthChecker $checker)
    {
    }

    public function index()
    {
        return view('webpage-checks.index', [
            'webpageChecks' => WebpageCheck::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048', 'url', 'regex:/^https?:\/\//i'],
            'requiredElements' => ['nullable', 'string', 'max:1000'],
        ]);

        $webpageCheck = WebpageCheck::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'required_elements' => $validated['requiredElements'] ?? null,
        ]);

        $this->runAndStore($webpageCheck);

        return redirect()->route('webpage-checks.index')
            ->with('status', "Added {$webpageCheck->name} and ran its first frontend check: {$webpageCheck->last_status}.");
    }

    public function run(WebpageCheck $webpageCheck)
    {
        $this->runAndStore($webpageCheck);

        return redirect()->route('webpage-checks.index')
            ->with('status', "Frontend check for {$webpageCheck->name} completed: {$webpageCheck->last_status}.");
    }

    public function toggleActive(WebpageCheck $webpageCheck)
    {
        $webpageCheck->update(['is_active' => ! $webpageCheck->is_active]);
        $state = $webpageCheck->is_active ? 'enabled' : 'disabled';

        return redirect()->route('webpage-checks.index')->with('status', "Scheduled checks for {$webpageCheck->name} are {$state}.");
    }

    public function updateRequiredElements(Request $request, WebpageCheck $webpageCheck)
    {
        $validated = $request->validate([
            'requiredElements' => ['nullable', 'string', 'max:1000'],
        ]);

        $webpageCheck->update(['required_elements' => $validated['requiredElements'] ?? null]);

        return redirect()->route('webpage-checks.index')->with('status', "Updated required elements for {$webpageCheck->name}.");
    }

    public function destroy(WebpageCheck $webpageCheck)
    {
        $webpageCheck->delete();

        return redirect()->route('webpage-checks.index')->with('status', "Removed {$webpageCheck->name} from frontend checks.");
    }

    private function runAndStore(WebpageCheck $webpageCheck): void
    {
        $webpageCheck->applyCheckResult(
            $this->checker->check($webpageCheck->url, $webpageCheck->requiredElementsList())
        );
    }
}

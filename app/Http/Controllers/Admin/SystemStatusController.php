<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class SystemStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/System', [
            'health' => [
                'database' => $this->check(fn () => DB::select('select 1')),
                'redis' => $this->check(fn () => Redis::connection()->ping()),
                'cache' => $this->check(fn () => Cache::store('redis')->put('health', 1, 5)),
            ],
            'queues' => [
                'pending' => $this->safe(fn () => Queue::size('default'), 0),
                'failed' => (int) DB::table('failed_jobs')->count(),
            ],
            'failedJobs' => DB::table('failed_jobs')->latest('id')->limit(10)
                ->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(fn ($j) => [
                    'id' => $j->id,
                    'queue' => $j->queue,
                    'failed_at' => $j->failed_at,
                    'error' => str($j->exception)->before("\n")->limit(180)->toString(),
                ]),
            'logLines' => $this->tailLog(80),
            'activity' => Activity::with('causer:id,name')->latest()->limit(15)->get()
                ->map(fn (Activity $a) => [
                    'id' => $a->id,
                    'log' => $a->log_name,
                    'description' => $a->description,
                    'causer' => $a->causer?->name,
                    'at' => $a->created_at?->toIso8601String(),
                ]),
            'versions' => ['php' => PHP_VERSION, 'laravel' => app()->version()],
        ]);
    }

    public function retryFailed(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Trabajos fallidos reenviados a la cola.');
    }

    public function flushFailed(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Trabajos fallidos eliminados.');
    }

    private function check(callable $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $default;
        }
    }

    /** @return list<string> */
    private function tailLog(int $lines): array
    {
        $path = storage_path('logs/laravel.log');
        if (! File::exists($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        fseek($handle, max(0, filesize($path) - 64 * 1024));
        $content = stream_get_contents($handle);
        fclose($handle);

        return collect(explode("\n", (string) $content))
            ->filter(fn ($l) => preg_match('/^\[\d{4}-\d{2}-\d{2}/', $l))
            ->map(fn ($l) => str($l)->limit(300)->toString())
            ->take(-$lines)->values()->all();
    }
}

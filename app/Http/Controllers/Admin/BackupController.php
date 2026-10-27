<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackup;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(private DatabaseBackup $backup) {}

    public function index(): View
    {
        return view('admin.backups.index', [
            'backups' => $this->backup->all(),
            'keepDays' => config('backup.keep_days'),
            'time' => config('backup.time'),
            'offsite' => $this->backup->offsiteEnabled() ? ($this->backup->offsiteStatus() ?? ['ok' => null]) : null,
            'offsiteKeepDays' => config('backup.offsite.keep_days'),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $name = $this->backup->create();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Backup gagal: '.$e->getMessage());
        }

        Activity::record('created', 'backups', "Backup database {$name} dibuat");

        if ($this->backup->offsiteEnabled()) {
            try {
                $this->backup->upload($name);
            } catch (Throwable $e) {
                report($e);

                return back()->with('error', "Backup {$name} dibuat di server, tapi gagal disalin ke offsite: ".$e->getMessage());
            }

            return back()->with('success', "Backup {$name} berhasil dibuat dan disalin ke offsite.");
        }

        return back()->with('success', "Backup {$name} berhasil dibuat.");
    }

    public function download(string $name): BinaryFileResponse
    {
        return response()->download($this->pathOr404($name));
    }

    public function destroy(string $name): RedirectResponse
    {
        $this->pathOr404($name);
        $this->backup->delete($name);
        Activity::record('deleted', 'backups', "Backup database {$name} dihapus");

        return back()->with('success', "Backup {$name} dihapus.");
    }

    private function pathOr404(string $name): string
    {
        try {
            return $this->backup->path($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}

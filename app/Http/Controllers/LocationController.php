<?php

namespace App\Http\Controllers;

use App\Models\BackupLocation;
use App\Services\Audit;
use App\Support\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Backup locations: named destinations for BMS-managed backups (rsync, robocopy, manual copies, ...). */
class LocationController extends Controller
{
    private function present(BackupLocation $l): array
    {
        return [...$l->toArray(), 'jobs' => $l->jobs()->count(), 'readable_by_bms' => $l->path !== null && $l->path !== ''];
    }

    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:2', 'max:255'],
            'loc_key' => [$req, 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'kind' => ['sometimes', Rule::in(BackupLocation::KINDS)],
            'path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    private const MESSAGES = ['loc_key.regex' => 'Use lowercase letters, digits and single hyphens, e.g. "nas-main"'];

    /** A path BMS will read from its own server must be absolute, with no ".." steps, and inside the allowed roots if any are set. */
    private function checkPath(?string $path): ?string
    {
        $path = $path === null ? null : trim($path);
        if ($path === null || $path === '') {
            return null;
        }
        $norm = str_replace('\\', '/', $path);
        $absolute = str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:/#', $norm) || str_starts_with($norm, '//');
        if (! $absolute || in_array('..', explode('/', $norm), true) || str_contains($path, "\0")) {
            throw ApiException::badRequest('The path must be absolute and must not contain "..".');
        }
        $roots = config('bms.location_path_roots');
        if ($roots && ! collect($roots)->contains(fn ($r) => str_starts_with(strtolower(rtrim($norm, '/').'/'), strtolower(rtrim(str_replace('\\', '/', $r), '/').'/')))) {
            throw ApiException::badRequest('That path is outside the folders this BMS server allows for backup locations.');
        }

        return $path;
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => BackupLocation::orderBy('name')->get()->map(fn ($l) => $this->present($l))->values()]);
    }

    public function store(Request $request)
    {
        $b = $request->validate($this->rules(false), self::MESSAGES);
        $location = DB::transaction(function () use ($b, $request) {
            $this->assertUnique($b['name'], $b['loc_key']);
            $l = BackupLocation::create([
                'name' => $b['name'], 'loc_key' => $b['loc_key'], 'kind' => $b['kind'] ?? 'other',
                'path' => $this->checkPath($b['path'] ?? null), 'notes' => $b['notes'] ?? null,
            ]);
            Audit::log($request, 'location_created', 'backup_location', $l->id, ['name' => $l->name, 'key' => $l->loc_key]);

            return $l->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($location)], 201);
    }

    private function assertUnique(string $name, string $key, ?int $exceptId = null): void
    {
        $q = fn () => BackupLocation::when($exceptId, fn ($x) => $x->where('id', '<>', $exceptId));
        if ($q()->where('loc_key', $key)->exists()) {
            throw ApiException::conflict('LOCATION_002', 'A location with that key already exists.');
        }
        if ($q()->where('name', $name)->exists()) {
            throw ApiException::conflict('LOCATION_003', 'A location with that name already exists.');
        }
    }

    public function update(Request $request, int $id)
    {
        $b = $request->validate($this->rules(true), self::MESSAGES);
        if (! $b) {
            throw ApiException::badRequest('No fields to update');
        }
        $location = DB::transaction(function () use ($id, $b, $request) {
            $l = BackupLocation::lockForUpdate()->find($id) ?? throw ApiException::notFound('Backup location', 'LOCATION_001');
            // The key is what agents map to a local path. Changing it under running jobs would silently break their tests.
            if (isset($b['loc_key']) && $b['loc_key'] !== $l->loc_key && $l->jobs()->exists()) {
                throw ApiException::conflict('LOCATION_004', 'The key cannot change while backup jobs use this location. Create a new location instead.');
            }
            $this->assertUnique($b['name'] ?? $l->name, $b['loc_key'] ?? $l->loc_key, $l->id);
            if (array_key_exists('path', $b)) {
                $b['path'] = $this->checkPath($b['path']);
            }
            $l->fill($b)->save();
            Audit::log($request, 'location_updated', 'backup_location', $l->id, $b);

            return $l->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($location)]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($id, $request) {
            $l = BackupLocation::lockForUpdate()->find($id) ?? throw ApiException::notFound('Backup location', 'LOCATION_001');
            if ($l->jobs()->withTrashed()->exists()) {
                throw ApiException::conflict('LOCATION_005', 'Backup jobs use this location. Move or delete them first.');
            }
            Audit::log($request, 'location_removed', 'backup_location', $l->id, ['name' => $l->name]);
            $l->delete();
        });

        return response()->noContent();
    }
}

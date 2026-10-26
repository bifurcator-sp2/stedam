<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAccessController extends Controller
{
    /**
     * Публичный метод: проверяет, разрешен ли вход по паролю для устройства.
     */
    public function check(string $deviceId): JsonResponse
    {
        $isAllowed = AllowedDevice::where('device_id', $deviceId)->exists();

        return response()->json([
            'device_id' => $deviceId,
            'allowed' => $isAllowed,
        ]);
    }

    /**
     * Админский метод: список всех разрешенных устройств.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'Forbidden');
        }

        return response()->json(AllowedDevice::latest()->get());
    }

    /**
     * Админский метод: добавить устройство в белый список.
     */
    public function store(Request $request, string $deviceId): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'Forbidden');
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $device = AllowedDevice::firstOrCreate(
            ['device_id' => $deviceId],
            ['description' => $validated['description'] ?? null]
        );

        return response()->json([
            'message' => 'Устройство разрешено.',
            'device' => $device,
        ], 201);
    }

    /**
     * Админский метод: удалить устройство из белого списка (запретить).
     */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            abort(403, 'Forbidden');
        }

        AllowedDevice::where('device_id', $deviceId)->delete();

        return response()->json([
            'message' => 'Устройство запрещено.',
        ]);
    }
}

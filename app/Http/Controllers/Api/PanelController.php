<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Services\PanelDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /**
     * List panels available to the API key's user.
     */
    public function index(Request $request): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $apiKey = $request->attributes->get('api_key');

        // Get panels the reseller has access to via the pivot table
        $panels = $reseller->panels()->where('is_active', true)->get();

        // If no panels available via pivot, check primary panel
        if ($panels->isEmpty() && $reseller->primary_panel_id) {
            $primaryPanel = \App\Models\Panel::where('id', $reseller->primary_panel_id)
                ->where('is_active', true)
                ->first();

            if ($primaryPanel) {
                $panels = collect([$primaryPanel]);
            }
        }

        // Use PanelDataService to get detailed panel info
        $panelDataService = new PanelDataService;
        $panelsData = [];

        foreach ($panels as $panel) {
            // Get panel access data from pivot
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedNodeIds = $panelAccess && $panelAccess->allowed_node_ids
                ? json_decode($panelAccess->allowed_node_ids, true)
                : null;
            $allowedServiceIds = $panelAccess && $panelAccess->allowed_service_ids
                ? json_decode($panelAccess->allowed_service_ids, true)
                : null;

            $panelData = $panelDataService->getPanelDataForJs($panel, $allowedNodeIds, $allowedServiceIds);

            $panelsData[] = [
                'id' => $panel->id,
                'name' => $panel->name,
                'panel_type' => $panel->panel_type,
                'nodes' => $panelData['nodes'] ?? [],
                'services' => $panelData['services'] ?? [],
            ];
        }

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'panels.list',
            'panel',
            null,
            ['count' => count($panelsData)]
        );

        return response()->json([
            'data' => $panelsData,
        ]);
    }
}

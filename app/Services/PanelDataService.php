<?php

namespace App\Services;

use App\Models\Panel;
use Illuminate\Support\Facades\Log;

/**
 * Centralized service for fetching and caching panel-specific data (nodes, services)
 */
class PanelDataService
{
    /**
     * Get nodes for an Eylandoo panel with caching
     *
     * @return array Array of nodes with id and name
     */
    public function getNodes(Panel $panel): array
    {
        if (strtolower(trim($panel->panel_type ?? '')) !== 'eylandoo') {
            return [];
        }

        try {
            // Use the panel's own cached method
            $nodes = $panel->getCachedEylandooNodes() ?? [];

            if (! is_array($nodes)) {
                $nodes = [];
            }

            return $nodes;
        } catch (\Exception $e) {
            Log::warning('PanelDataService: Failed to fetch nodes', [
                'panel_id' => $panel->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get services for a Marzneshin panel
     *
     * Note: Current implementation doesn't have remote service fetching,
     * so we return empty array. This is a placeholder for future implementation.
     *
     * @return array Array of services with id and name
     */
    public function getServices(Panel $panel): array
    {
        if (strtolower(trim($panel->panel_type ?? '')) !== 'marzneshin') {
            return [];
        }

        // TODO: Implement remote service fetching when available
        // For now, services are configured at reseller level via allowed_service_ids
        return [];
    }

    /**
     * Get panel data formatted for JavaScript consumption
     *
     * @param  array|null  $allowedNodeIds  Whitelist of node IDs from pivot table
     * @param  array|null  $allowedServiceIds  Whitelist of service IDs from pivot table
     * @return array Panel data with nodes/services
     */
    public function getPanelDataForJs(Panel $panel, ?array $allowedNodeIds = null, ?array $allowedServiceIds = null): array
    {
        $panelType = strtolower(trim($panel->panel_type ?? ''));

        $data = [
            'id' => $panel->id,
            'name' => $panel->name,
            'panel_type' => $panelType,
            'nodes' => [],
            'services' => [],
        ];

        // Fetch and filter nodes for Eylandoo panels
        if ($panelType === 'eylandoo') {
            $allNodes = $this->getNodes($panel);

            // Filter by whitelist if provided
            if ($allowedNodeIds !== null && ! empty($allowedNodeIds)) {
                $allowedNodeIds = array_map('intval', (array) $allowedNodeIds);
                $allNodes = array_filter($allNodes, function ($node) use ($allowedNodeIds) {
                    if (! is_array($node) || ! isset($node['id'])) {
                        return false;
                    }

                    return in_array((int) $node['id'], $allowedNodeIds, true);
                });
            }

            // Use defaults if no nodes available
            if (empty($allNodes)) {
                $defaultNodeIds = config('panels.eylandoo.default_node_ids', [1, 2]);
                $allNodes = array_map(function ($id) {
                    return [
                        'id' => (int) $id,
                        'name' => "Node {$id} (default)",
                        'is_default' => true,
                    ];
                }, (array) $defaultNodeIds);
            }

            $data['nodes'] = array_values($allNodes);
        }

        // Fetch and filter services for Marzneshin panels
        if ($panelType === 'marzneshin') {
            $allServices = $this->getServices($panel);

            // Filter by whitelist if provided
            if ($allowedServiceIds !== null && ! empty($allowedServiceIds)) {
                $allowedServiceIds = array_map('intval', (array) $allowedServiceIds);
                $allServices = array_filter($allServices, function ($service) use ($allowedServiceIds) {
                    if (! is_array($service) || ! isset($service['id'])) {
                        return false;
                    }

                    return in_array((int) $service['id'], $allowedServiceIds, true);
                });
            }

            // Convert allowed service IDs to service objects if no remote data
            if (empty($allServices) && $allowedServiceIds !== null && ! empty($allowedServiceIds)) {
                $allServices = array_map(function ($id) {
                    return [
                        'id' => (int) $id,
                        'name' => "Service {$id}",
                    ];
                }, $allowedServiceIds);
            }

            $data['services'] = array_values($allServices);
        }

        return $data;
    }

    /**
     * Build panels array for all reseller-accessible panels
     *
     * @param  \App\Models\Reseller  $reseller
     * @return array Array of panel data for JavaScript
     */
    public function getPanelsForReseller($reseller): array
    {
        $panels = $reseller->panels()->where('is_active', true)->get();
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

            $panelsData[] = $this->getPanelDataForJs($panel, $allowedNodeIds, $allowedServiceIds);
        }

        return $panelsData;
    }
}

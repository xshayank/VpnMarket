<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Api\ApiDocumentationService;
use App\Services\Api\ApiResponseMapper;
use App\Services\Api\PanelHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller for API documentation and cheat sheets
 */
class ApiDocumentationController extends Controller
{
    protected ApiDocumentationService $documentationService;
    protected PanelHealthService $healthService;

    public function __construct()
    {
        $this->documentationService = new ApiDocumentationService();
        $this->healthService = new PanelHealthService();
    }

    /**
     * Get API documentation for a specific style
     */
    public function documentation(Request $request): JsonResponse
    {
        $style = $request->input('style', ApiKey::STYLE_FALCO);

        if (!in_array($style, ApiKey::ALL_STYLES)) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid API style. Available: ' . implode(', ', ApiKey::ALL_STYLES),
            ], 400);
        }

        $documentation = $this->documentationService->getDocumentation($style);
        $scopes = $this->documentationService->getScopes($style);

        return response()->json([
            'data' => array_merge($documentation, ['scopes' => $scopes]),
        ]);
    }

    /**
     * Get API documentation for both styles (cheat sheet)
     */
    public function cheatSheet(): JsonResponse
    {
        return response()->json([
            'data' => [
                'falco' => $this->documentationService->getDocumentation(ApiKey::STYLE_FALCO),
                'marzneshin' => $this->documentationService->getDocumentation(ApiKey::STYLE_MARZNESHIN),
                'webhook_events' => $this->documentationService->getWebhookEvents(),
                'field_mapping' => ApiResponseMapper::getNodeToServiceMappingTable(),
            ],
        ]);
    }

    /**
     * Get available scopes for API key creation
     */
    public function scopes(Request $request): JsonResponse
    {
        $style = $request->input('style');

        if ($style) {
            if (!in_array($style, ApiKey::ALL_STYLES)) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invalid API style',
                ], 400);
            }
            return response()->json([
                'data' => $this->documentationService->getScopes($style),
            ]);
        }

        return response()->json([
            'data' => [
                'falco' => $this->documentationService->getScopes(ApiKey::STYLE_FALCO),
                'marzneshin' => $this->documentationService->getScopes(ApiKey::STYLE_MARZNESHIN),
            ],
        ]);
    }

    /**
     * Export OpenAPI spec
     */
    public function openApiSpec(Request $request): JsonResponse
    {
        $style = $request->input('style', ApiKey::STYLE_FALCO);

        if (!in_array($style, ApiKey::ALL_STYLES)) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid API style',
            ], 400);
        }

        return response()->json(
            $this->documentationService->exportOpenApiSpec($style)
        );
    }

    /**
     * Export markdown documentation
     */
    public function markdownDoc(Request $request): Response
    {
        $style = $request->input('style', ApiKey::STYLE_FALCO);

        if (!in_array($style, ApiKey::ALL_STYLES)) {
            return response('Invalid API style', 400);
        }

        $markdown = $this->documentationService->exportMarkdown($style);

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="api-documentation-' . $style . '.md"',
        ]);
    }

    /**
     * Get field mapping table (Eylandoo nodes to Marzneshin services)
     */
    public function fieldMapping(): JsonResponse
    {
        return response()->json([
            'data' => ApiResponseMapper::getNodeToServiceMappingTable(),
        ]);
    }

    /**
     * Get webhook events documentation
     */
    public function webhookEvents(): JsonResponse
    {
        return response()->json([
            'data' => $this->documentationService->getWebhookEvents(),
        ]);
    }

    /**
     * Get panel health status
     */
    public function panelHealth(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $reseller = $user->reseller;

        // Get panels the reseller has access to
        $panels = $reseller->panels()->where('is_active', true)->get();

        if ($panels->isEmpty() && $reseller->primary_panel_id) {
            $panels = \App\Models\Panel::where('id', $reseller->primary_panel_id)
                ->where('is_active', true)
                ->get();
        }

        $healthResults = [];
        foreach ($panels as $panel) {
            $healthResults[] = $this->healthService->checkHealth($panel);
        }

        return response()->json([
            'data' => [
                'panels' => $healthResults,
                'summary' => [
                    'total' => count($healthResults),
                    'healthy' => collect($healthResults)->where('is_healthy', true)->count(),
                    'unhealthy' => collect($healthResults)->where('is_healthy', false)->count(),
                ],
            ],
        ]);
    }

    /**
     * Refresh panel health status
     */
    public function refreshPanelHealth(Request $request, int $panelId): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $reseller = $user->reseller;

        // Verify access to panel
        $hasAccess = $reseller->hasPanelAccess($panelId) || $reseller->primary_panel_id == $panelId;

        if (!$hasAccess) {
            return response()->json([
                'error' => true,
                'message' => 'You do not have access to this panel',
            ], 403);
        }

        $panel = \App\Models\Panel::find($panelId);
        if (!$panel) {
            return response()->json([
                'error' => true,
                'message' => 'Panel not found',
            ], 404);
        }

        $health = $this->healthService->refreshHealth($panel);

        return response()->json([
            'data' => $health,
        ]);
    }

    /**
     * Get API usage analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $hours = $request->input('hours', 24);

        // Get analytics for all of user's API keys
        $apiKeys = $user->apiKeys()->pluck('id');
        $analytics = [];

        foreach ($apiKeys as $keyId) {
            $analytics[$keyId] = \App\Models\ApiAuditLog::getKeyAnalytics($keyId, $hours);
        }

        // Get overall stats
        $popularEndpoints = \App\Models\ApiAuditLog::getPopularEndpoints($hours);
        $errorRateByStyle = \App\Models\ApiAuditLog::getErrorRateByStyle($hours);

        return response()->json([
            'data' => [
                'by_key' => $analytics,
                'popular_endpoints' => $popularEndpoints,
                'error_rate_by_style' => $errorRateByStyle,
                'period_hours' => $hours,
            ],
        ]);
    }

    /**
     * Get available API styles
     */
    public function styles(): JsonResponse
    {
        return response()->json([
            'data' => [
                [
                    'value' => ApiKey::STYLE_FALCO,
                    'label' => 'Falco (Native)',
                    'description' => 'VPNMarket\'s native API format with full feature access',
                ],
                [
                    'value' => ApiKey::STYLE_MARZNESHIN,
                    'label' => 'Marzneshin',
                    'description' => 'Marzneshin-compatible format for drop-in panel replacement',
                ],
            ],
        ]);
    }
}

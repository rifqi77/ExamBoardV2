<?php

namespace App\Http\Controllers;

use App\Services\AiProviders;
use App\Services\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiSettingsController extends Controller
{
    public function page(Request $r)
    {
        return Inertia::render('Admin/AiSettings', [
            'settings' => AiProviders::getSettings(),
            'keyStatus' => AiProviders::keyStatus(),
            'providers' => [
                'text' => AiProviders::TEXT_PROVIDERS,
                'models' => AiProviders::TEXT_MODELS,
                'labels' => AiProviders::PROVIDER_LABELS,
            ],
        ]);
    }

    public function saveSettings(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $provider = $r->input('textProvider');
        if (! in_array($provider, AiProviders::TEXT_PROVIDERS, true)) {
            return response()->json(['error' => 'Invalid text provider.'], 400);
        }
        $model = $r->input('textModel');
        if (! in_array($model, AiProviders::TEXT_MODELS[$provider] ?? [], true)) {
            $model = AiProviders::defaultModelFor($provider);
        }
        $temp = max(0.0, min(2.0, (float) $r->input('temperature', 0.7)));
        $image = $r->input('imageProvider', 'off');
        if (! in_array($image, ['off', 'pollinations', 'gemini', 'openai'], true)) {
            $image = 'off';
        }
        AiProviders::saveSettings([
            'textProvider' => $provider, 'textModel' => $model,
            'temperature' => $temp, 'imageProvider' => $image,
        ], $u->id);

        Audit::log($r, 'ai.settings', null, null, 'Updated AI settings', ['textProvider' => $provider, 'textModel' => $model, 'imageProvider' => $image]);
        return response()->json(['ok' => true, 'settings' => AiProviders::getSettings(), 'keyStatus' => AiProviders::keyStatus()]);
    }

    public function saveKeys(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $keys = $r->input('keys', []);
        if (! is_array($keys)) {
            return response()->json(['error' => '`keys` object required.'], 400);
        }
        $patch = [];
        foreach (['gemini', 'claude', 'openai'] as $p) {
            if (array_key_exists($p, $keys)) {
                $patch[$p] = $keys[$p];
            }
        }
        AiProviders::saveKeys($patch, $u->id);
        Audit::log($r, 'ai.keys', null, null, 'Updated AI API keys', ['providers' => array_keys($patch)]);
        return response()->json(['ok' => true, 'keyStatus' => AiProviders::keyStatus()]);
    }
}

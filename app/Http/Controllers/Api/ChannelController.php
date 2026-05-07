<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChannelController extends Controller
{
    /**
     * Get Telegram channel by chat ID
     */
    public function getTelegramChannel($chatId)
    {
        try {
            $channel = Channel::where('type', 'telegram')
                ->where('channel_account', $chatId)
                ->first();

            if (! $channel) {
                return response()->json([
                    'success' => false,
                    'error' => 'Channel not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tenant_id' => $channel->tenant_id,
                    'channel_id' => $channel->id,
                    'type' => $channel->type,
                    'channel_account' => $channel->channel_account,
                    'is_active' => $channel->is_active,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Slack channel by team ID
     */
    public function getSlackChannel($teamId)
    {
        try {
            $channel = Channel::where('type', 'slack')
                ->where('channel_account', $teamId)
                ->first();

            if (! $channel) {
                return response()->json([
                    'success' => false,
                    'error' => 'Channel not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tenant_id' => $channel->tenant_id,
                    'channel_id' => $channel->id,
                    'type' => $channel->type,
                    'channel_account' => $channel->channel_account,
                    'is_active' => $channel->is_active,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update channel
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
            'type' => 'required|in:whatsapp,telegram,slack',
            'channel_account' => 'required|string',
            'name' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $channel = Channel::firstOrCreate(
                [
                    'tenant_id' => $request->input('tenant_id'),
                    'type' => $request->input('type'),
                    'channel_account' => $request->input('channel_account'),
                ],
                [
                    'name' => $request->input('name') ?? ucfirst($request->input('type')).': '.$request->input('channel_account'),
                    'is_active' => $request->input('is_active', true),
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $channel,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

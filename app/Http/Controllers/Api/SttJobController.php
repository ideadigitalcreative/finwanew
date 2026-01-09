<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SttJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SttJobController extends Controller
{
    /**
     * Update STT job status
     */
    public function update(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'transcribed_text' => 'nullable|string',
            'confidence_score' => 'nullable|numeric|min:0|max:1',
            'processing_time_ms' => 'nullable|integer',
            'duration_seconds' => 'nullable|numeric',
            'status' => 'required|in:pending,processing,completed,failed',
            'error_message' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $sttJob = SttJob::findOrFail($id);

            $sttJob->update([
                'transcribed_text' => $request->input('transcribed_text'),
                'metadata' => array_merge($sttJob->metadata ?? [], [
                    'confidence_score' => $request->input('confidence_score', 0),
                    'processing_time_ms' => $request->input('processing_time_ms', 0),
                    'duration_seconds' => $request->input('duration_seconds'),
                    'updated_at' => now()->toIso8601String()
                ]),
                'status' => $request->input('status'),
                'error_message' => $request->input('error_message'),
                'completed_at' => $request->input('status') === 'completed' ? now() : null
            ]);

            // If STT completed, process transcribed text with AI
            if ($request->input('status') === 'completed' && $request->input('transcribed_text')) {
                // Update message content with transcribed text
                $message = $sttJob->message;
                $message->update([
                    'content' => $request->input('transcribed_text'),
                    'type' => 'text' // Change type to text after transcription
                ]);

                // Dispatch job to process transcribed text
                \App\Jobs\ProcessIncomingMessage::dispatch($message);
            }

            return response()->json([
                'success' => true,
                'message' => 'STT job updated'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating STT job', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

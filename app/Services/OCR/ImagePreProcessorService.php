<?php

namespace App\Services\OCR;

use Illuminate\Support\Facades\Log;

/**
 * ImagePreProcessorService - Handles image preprocessing before OCR
 *
 * Typically uses OpenCV (via python or shell) to:
 * 1. Grayscale
 * 2. Thresholding (Binarization)
 * 3. Deskewing
 * 4. Resizing
 */
class ImagePreProcessorService
{
    /**
     * Preprocess image for better OCR accuracy using OpenCV (Python script)
     *
     * @param  string  $imageContent  Raw image content
     * @return string Preprocessed image content
     */
    public function preprocess(string $imageContent): string
    {
        Log::info('ImagePreProcessorService: Preprocessing image using OpenCV...');

        try {
            // Create temporary files
            $tempIn = tempnam(sys_get_temp_dir(), 'ocr_in_');
            $tempOut = $tempIn.'_out.jpg'; // OpenCV imwrite extension matters

            file_put_contents($tempIn, $imageContent);

            $scriptPath = base_path('scripts/preprocess_receipt.py');
            $command = "python3 \"$scriptPath\" \"$tempIn\" \"$tempOut\" 2>&1";

            Log::debug('Executing OCR Preprocessing', ['command' => $command]);

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempOut)) {
                $processed = file_get_contents($tempOut);
                Log::info('ImagePreProcessorService: Preprocessing successful.');

                // Cleanup
                @unlink($tempIn);
                @unlink($tempOut);

                return $processed;
            } else {
                Log::error('ImagePreProcessorService: OpenCV script failed.', [
                    'return_code' => $returnCode,
                    'output' => $output,
                ]);
                @unlink($tempIn);

                return $imageContent;
            }
        } catch (\Exception $e) {
            Log::error('ImagePreProcessorService error: '.$e->getMessage());

            return $imageContent;
        }
    }
}

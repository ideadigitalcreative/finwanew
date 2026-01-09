# FinWa-AI Speech-to-Text (STT) Endpoint
# Add this endpoint to your existing FastAPI server at ai.finwa.web.id

"""
Requirements:
pip install faster-whisper torch torchaudio

For CPU-only (no GPU):
pip install faster-whisper

The faster-whisper library is 4x faster than OpenAI Whisper and uses less memory.
It uses CTranslate2 for optimized inference.
"""

import base64
import tempfile
import os
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from typing import Optional
import logging

logger = logging.getLogger(__name__)

# Initialize Whisper model (do this once at startup)
# Model options: tiny, base, small, medium, large-v2, large-v3
# For Indonesian, use at least "small" for good accuracy
whisper_model = None

def load_whisper_model():
    global whisper_model
    if whisper_model is None:
        try:
            from faster_whisper import WhisperModel
            
            # Use "small" for balance of speed/accuracy on CPU
            # Use "large-v3" for best accuracy (requires GPU or more RAM)
            model_size = os.getenv("WHISPER_MODEL_SIZE", "small")
            
            # For CPU: compute_type="int8" is fastest
            # For GPU: compute_type="float16" is best
            device = os.getenv("WHISPER_DEVICE", "cpu")
            compute_type = "int8" if device == "cpu" else "float16"
            
            logger.info(f"Loading Whisper model: {model_size} on {device}")
            whisper_model = WhisperModel(model_size, device=device, compute_type=compute_type)
            logger.info("Whisper model loaded successfully")
            
        except Exception as e:
            logger.error(f"Failed to load Whisper model: {e}")
            raise

# Load model on startup
# load_whisper_model()  # Uncomment this in production

router = APIRouter()

class AudioRequest(BaseModel):
    audio: Optional[str] = None         # Base64 encoded audio
    audio_base64: Optional[str] = None  # Alternative field
    file_type: Optional[str] = "audio/ogg"
    extension: Optional[str] = "ogg"
    language: Optional[str] = "id"      # Indonesian default

class AudioResponse(BaseModel):
    success: bool
    text: Optional[str] = None
    transcription: Optional[str] = None  # Alias
    language: Optional[str] = None
    confidence: Optional[float] = None
    error: Optional[str] = None

@router.post("/process/audio", response_model=AudioResponse)
async def process_audio(request: AudioRequest):
    """
    Transcribe audio to text using Whisper
    
    Accepts:
    - audio: Base64 encoded audio file
    - language: Language code (default: "id" for Indonesian)
    
    Returns:
    - text: Transcribed text
    - confidence: Average confidence score
    """
    try:
        # Ensure model is loaded
        if whisper_model is None:
            load_whisper_model()
        
        # Get base64 audio
        audio_b64 = request.audio or request.audio_base64
        if not audio_b64:
            raise HTTPException(status_code=400, detail="No audio data provided")
        
        # Decode base64
        try:
            audio_bytes = base64.b64decode(audio_b64)
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Invalid base64 audio: {e}")
        
        # Save to temp file
        extension = request.extension or "ogg"
        with tempfile.NamedTemporaryFile(suffix=f".{extension}", delete=False) as tmp:
            tmp.write(audio_bytes)
            tmp_path = tmp.name
        
        try:
            # Transcribe
            logger.info(f"Transcribing audio: {len(audio_bytes)} bytes, language={request.language}")
            
            segments, info = whisper_model.transcribe(
                tmp_path,
                language=request.language,
                beam_size=5,
                vad_filter=True,  # Filter out silence
                vad_parameters=dict(min_silence_duration_ms=500)
            )
            
            # Collect all segments
            text_parts = []
            total_confidence = 0
            segment_count = 0
            
            for segment in segments:
                text_parts.append(segment.text)
                total_confidence += segment.avg_logprob
                segment_count += 1
            
            full_text = " ".join(text_parts).strip()
            avg_confidence = (total_confidence / segment_count) if segment_count > 0 else 0
            
            # Convert logprob to 0-1 confidence (rough approximation)
            # logprob is typically between -1 (high confidence) and -3 (low confidence)
            confidence_score = max(0, min(1, 1 + avg_confidence))
            
            logger.info(f"Transcription complete: '{full_text[:100]}...'")
            
            return AudioResponse(
                success=True,
                text=full_text,
                transcription=full_text,  # Alias
                language=info.language,
                confidence=round(confidence_score, 2)
            )
            
        finally:
            # Clean up temp file
            if os.path.exists(tmp_path):
                os.unlink(tmp_path)
                
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"STT processing error: {e}", exc_info=True)
        return AudioResponse(
            success=False,
            error=str(e)
        )

# Health check for STT
@router.get("/health/stt")
async def stt_health():
    """Check if Whisper model is loaded and ready"""
    return {
        "status": "healthy" if whisper_model is not None else "not_loaded",
        "model": os.getenv("WHISPER_MODEL_SIZE", "small"),
        "device": os.getenv("WHISPER_DEVICE", "cpu")
    }


"""
=== INSTALLATION INSTRUCTIONS ===

1. Install faster-whisper:
   pip install faster-whisper

2. Add environment variables to your server:
   WHISPER_MODEL_SIZE=small   # Options: tiny, base, small, medium, large-v2, large-v3
   WHISPER_DEVICE=cpu         # Options: cpu, cuda

3. Add this router to your main FastAPI app:
   from stt_endpoint import router as stt_router
   app.include_router(stt_router)

4. Model sizes and requirements:
   - tiny:     ~75MB RAM, fastest, basic accuracy
   - base:     ~150MB RAM, fast, decent accuracy
   - small:    ~500MB RAM, good balance (RECOMMENDED for Indonesian)
   - medium:   ~1.5GB RAM, better accuracy
   - large-v3: ~3GB RAM, best accuracy (RECOMMENDED if you have GPU)

5. First run will download the model (~500MB for small)

=== TESTING ===

curl -X POST https://ai.finwa.web.id/process/audio \
  -H "Content-Type: application/json" \
  -d '{"audio_base64": "<base64_audio>", "language": "id"}'

Expected response:
{
  "success": true,
  "text": "beli makan siang dua puluh lima ribu",
  "language": "id",
  "confidence": 0.85
}
"""

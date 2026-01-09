#!/usr/bin/env python3
"""
Test Ollama Integration untuk Keuangan AI
Script ini untuk testing apakah Ollama sudah berjalan dengan baik
"""

import requests
import json
import sys
from typing import Dict, Any

# Configuration
OLLAMA_BASE_URL = "http://localhost:11434"
OLLAMA_MODEL = "qwen2.5:3b"

def test_ollama_connection() -> bool:
    """Test koneksi ke Ollama server"""
    print("🔍 Testing Ollama connection...")
    try:
        response = requests.get(f"{OLLAMA_BASE_URL}/api/tags", timeout=5)
        response.raise_for_status()
        print("✅ Ollama server is running")
        
        # Check available models
        data = response.json()
        models = data.get("models", [])
        print(f"📦 Available models: {len(models)}")
        for model in models:
            print(f"   - {model.get('name', 'unknown')}")
        
        return True
    except requests.exceptions.ConnectionError:
        print("❌ Cannot connect to Ollama server")
        print("   Run: ollama serve")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

def test_ollama_model() -> bool:
    """Test apakah model sudah di-pull"""
    print(f"\n🔍 Checking if model '{OLLAMA_MODEL}' is available...")
    try:
        response = requests.get(f"{OLLAMA_BASE_URL}/api/tags", timeout=5)
        response.raise_for_status()
        data = response.json()
        models = data.get("models", [])
        model_names = [m.get("name", "") for m in models]
        
        if OLLAMA_MODEL in model_names:
            print(f"✅ Model '{OLLAMA_MODEL}' is available")
            return True
        else:
            print(f"❌ Model '{OLLAMA_MODEL}' not found")
            print(f"   Run: ollama pull {OLLAMA_MODEL}")
            return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

def test_ollama_chat() -> bool:
    """Test chat dengan Ollama"""
    print(f"\n🔍 Testing chat with model '{OLLAMA_MODEL}'...")
    
    test_message = "Ekstrak transaksi dari pesan ini: beli bensin 50000"
    
    try:
        response = requests.post(
            f"{OLLAMA_BASE_URL}/api/chat",
            json={
                "model": OLLAMA_MODEL,
                "messages": [
                    {
                        "role": "system",
                        "content": "Anda adalah asisten AI untuk ekstraksi transaksi keuangan. Kembalikan JSON dengan format: {\"transactions\": [{\"type\": \"expense\", \"amount\": 50000, \"description\": \"Beli bensin\"}]}"
                    },
                    {
                        "role": "user",
                        "content": test_message
                    }
                ],
                "stream": False,
                "options": {
                    "temperature": 0,
                    "num_predict": 500
                }
            },
            timeout=60
        )
        response.raise_for_status()
        data = response.json()
        
        if "message" in data and "content" in data["message"]:
            content = data["message"]["content"]
            print(f"✅ Chat successful!")
            print(f"📝 Response preview (first 200 chars):")
            print(f"   {content[:200]}...")
            return True
        else:
            print("❌ Unexpected response format")
            print(f"   Response: {data}")
            return False
            
    except requests.exceptions.Timeout:
        print("❌ Request timeout (model might be loading)")
        print("   Try again in a few seconds")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

def test_ai_processor() -> bool:
    """Test AI Processor endpoint"""
    print("\n🔍 Testing AI Processor endpoint...")
    
    try:
        response = requests.post(
            "http://localhost:3003/extract-transaction",
            headers={
                "Content-Type": "application/json",
                "X-API-Key": "ai_processor_api_key_123"
            },
            json={
                "tenant_id": 1,
                "message_id": 1,
                "message_text": "beli bensin 50000",
                "message_type": "text"
            },
            timeout=60
        )
        
        if response.status_code == 200:
            data = response.json()
            print("✅ AI Processor is working!")
            print(f"📊 Extracted {len(data.get('extracted_transactions', []))} transaction(s)")
            return True
        else:
            print(f"❌ AI Processor returned status {response.status_code}")
            print(f"   Response: {response.text[:200]}")
            return False
            
    except requests.exceptions.ConnectionError:
        print("❌ Cannot connect to AI Processor")
        print("   Make sure AI Processor is running on port 3003")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

def main():
    """Run all tests"""
    print("=" * 60)
    print("🦙 Ollama Integration Test for Keuangan AI")
    print("=" * 60)
    
    results = []
    
    # Test 1: Ollama connection
    results.append(("Ollama Connection", test_ollama_connection()))
    
    # Test 2: Model availability
    if results[-1][1]:  # Only if connection successful
        results.append(("Model Availability", test_ollama_model()))
    
    # Test 3: Chat functionality
    if results[-1][1]:  # Only if model available
        results.append(("Chat Functionality", test_ollama_chat()))
    
    # Test 4: AI Processor (optional)
    results.append(("AI Processor", test_ai_processor()))
    
    # Summary
    print("\n" + "=" * 60)
    print("📊 Test Summary")
    print("=" * 60)
    
    for test_name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status} - {test_name}")
    
    total_tests = len(results)
    passed_tests = sum(1 for _, result in results if result)
    
    print(f"\n🎯 Result: {passed_tests}/{total_tests} tests passed")
    
    if passed_tests == total_tests:
        print("\n🎉 All tests passed! Ollama is ready to use.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please check the errors above.")
        sys.exit(1)

if __name__ == "__main__":
    main()

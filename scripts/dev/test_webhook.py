"""
Test webhook endpoint untuk memastikan Laravel menerima pesan
"""
import requests
import json

url = "https://keuangan-ai.test/api/webhooks/whatsapp/from-engine"
headers = {
    "Content-Type": "application/json",
    "X-API-Key": "whatsapp_gateway_api_key_123"
}

payload = {
    "id": "test_message_123",
    "from": "6285242766676@c.us",
    "body": "Test beli bensin 50rb",
    "type": "chat",
    "session_id": "wa_1_6285159205506"
}

print("Testing webhook endpoint...")
print(f"URL: {url}")
print(f"Payload: {json.dumps(payload, indent=2)}")
print("="*60)

try:
    response = requests.post(url, json=payload, headers=headers, timeout=30, verify=False)
    print(f"\nStatus: {response.status_code}")
    print(f"Response: {response.text}")
except Exception as e:
    print(f"\nError: {e}")

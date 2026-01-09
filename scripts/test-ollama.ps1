# Test Ollama Integration untuk Keuangan AI (Windows PowerShell)
# Script ini untuk testing apakah Ollama sudah berjalan dengan baik

$OLLAMA_BASE_URL = "http://localhost:11434"
$OLLAMA_MODEL = "qwen2.5:3b"

Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "🦙 Ollama Integration Test for Keuangan AI" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

# Test 1: Ollama Connection
Write-Host "🔍 Testing Ollama connection..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "$OLLAMA_BASE_URL/api/tags" -Method Get -TimeoutSec 5
    Write-Host "✅ Ollama server is running" -ForegroundColor Green
    
    $models = $response.models
    Write-Host "📦 Available models: $($models.Count)" -ForegroundColor Cyan
    foreach ($model in $models) {
        Write-Host "   - $($model.name)" -ForegroundColor Gray
    }
    $ollamaConnected = $true
}
catch {
    Write-Host "❌ Cannot connect to Ollama server" -ForegroundColor Red
    Write-Host "   Run: ollama serve" -ForegroundColor Yellow
    $ollamaConnected = $false
}

Write-Host ""

# Test 2: Model Availability
if ($ollamaConnected) {
    Write-Host "🔍 Checking if model '$OLLAMA_MODEL' is available..." -ForegroundColor Yellow
    try {
        $response = Invoke-RestMethod -Uri "$OLLAMA_BASE_URL/api/tags" -Method Get -TimeoutSec 5
        $modelNames = $response.models | ForEach-Object { $_.name }
        
        if ($modelNames -contains $OLLAMA_MODEL) {
            Write-Host "✅ Model '$OLLAMA_MODEL' is available" -ForegroundColor Green
            $modelAvailable = $true
        }
        else {
            Write-Host "❌ Model '$OLLAMA_MODEL' not found" -ForegroundColor Red
            Write-Host "   Run: ollama pull $OLLAMA_MODEL" -ForegroundColor Yellow
            $modelAvailable = $false
        }
    }
    catch {
        Write-Host "❌ Error checking model: $_" -ForegroundColor Red
        $modelAvailable = $false
    }
}

Write-Host ""

# Test 3: Chat Functionality
if ($ollamaConnected -and $modelAvailable) {
    Write-Host "🔍 Testing chat with model '$OLLAMA_MODEL'..." -ForegroundColor Yellow
    
    $chatBody = @{
        model = $OLLAMA_MODEL
        messages = @(
            @{
                role = "system"
                content = "Anda adalah asisten AI untuk ekstraksi transaksi keuangan. Kembalikan JSON dengan format: {`"transactions`": [{`"type`": `"expense`", `"amount`": 50000, `"description`": `"Beli bensin`"}]}"
            },
            @{
                role = "user"
                content = "Ekstrak transaksi dari pesan ini: beli bensin 50000"
            }
        )
        stream = $false
        options = @{
            temperature = 0
            num_predict = 500
        }
    } | ConvertTo-Json -Depth 10
    
    try {
        $response = Invoke-RestMethod -Uri "$OLLAMA_BASE_URL/api/chat" -Method Post -Body $chatBody -ContentType "application/json" -TimeoutSec 60
        
        if ($response.message -and $response.message.content) {
            $content = $response.message.content
            Write-Host "✅ Chat successful!" -ForegroundColor Green
            Write-Host "📝 Response preview (first 200 chars):" -ForegroundColor Cyan
            $preview = if ($content.Length -gt 200) { $content.Substring(0, 200) + "..." } else { $content }
            Write-Host "   $preview" -ForegroundColor Gray
            $chatWorking = $true
        }
        else {
            Write-Host "❌ Unexpected response format" -ForegroundColor Red
            $chatWorking = $false
        }
    }
    catch {
        Write-Host "❌ Error: $_" -ForegroundColor Red
        if ($_.Exception.Message -like "*timeout*") {
            Write-Host "   Request timeout (model might be loading)" -ForegroundColor Yellow
            Write-Host "   Try again in a few seconds" -ForegroundColor Yellow
        }
        $chatWorking = $false
    }
}

Write-Host ""

# Test 4: AI Processor
Write-Host "🔍 Testing AI Processor endpoint..." -ForegroundColor Yellow

$aiProcessorBody = @{
    tenant_id = 1
    message_id = 1
    message_text = "beli bensin 50000"
    message_type = "text"
} | ConvertTo-Json

try {
    $headers = @{
        "Content-Type" = "application/json"
        "X-API-Key" = "ai_processor_api_key_123"
    }
    
    $response = Invoke-RestMethod -Uri "http://localhost:3003/extract-transaction" -Method Post -Body $aiProcessorBody -Headers $headers -TimeoutSec 60
    
    Write-Host "✅ AI Processor is working!" -ForegroundColor Green
    $transactionCount = if ($response.extracted_transactions) { $response.extracted_transactions.Count } else { 0 }
    Write-Host "📊 Extracted $transactionCount transaction(s)" -ForegroundColor Cyan
    $aiProcessorWorking = $true
}
catch {
    Write-Host "❌ AI Processor error: $_" -ForegroundColor Red
    if ($_.Exception.Message -like "*Unable to connect*") {
        Write-Host "   Make sure AI Processor is running on port 3003" -ForegroundColor Yellow
    }
    $aiProcessorWorking = $false
}

Write-Host ""

# Summary
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "📊 Test Summary" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan

$tests = @(
    @{ Name = "Ollama Connection"; Result = $ollamaConnected },
    @{ Name = "Model Availability"; Result = $modelAvailable },
    @{ Name = "Chat Functionality"; Result = $chatWorking },
    @{ Name = "AI Processor"; Result = $aiProcessorWorking }
)

foreach ($test in $tests) {
    if ($test.Result) {
        Write-Host "✅ PASS - $($test.Name)" -ForegroundColor Green
    }
    else {
        Write-Host "❌ FAIL - $($test.Name)" -ForegroundColor Red
    }
}

$passedTests = ($tests | Where-Object { $_.Result }).Count
$totalTests = $tests.Count

Write-Host ""
Write-Host "🎯 Result: $passedTests/$totalTests tests passed" -ForegroundColor Cyan

if ($passedTests -eq $totalTests) {
    Write-Host ""
    Write-Host "🎉 All tests passed! Ollama is ready to use." -ForegroundColor Green
    exit 0
}
else {
    Write-Host ""
    Write-Host "⚠️  Some tests failed. Please check the errors above." -ForegroundColor Yellow
    exit 1
}

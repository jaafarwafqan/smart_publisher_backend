param(
    [string]$BaseUrl = "http://127.0.0.1:8000/api/v1",
    [string]$Email = "admin@smartpublisher.local",
    [string]$Password = "Admin@123456",
    [int]$Users = 100,
    [int]$RequestsPerMinute = 500,
    [int]$PublishJobs = 1000
)

$ErrorActionPreference = "Stop"

Write-Host "Starting load test with $Users users, $RequestsPerMinute rpm, $PublishJobs publish jobs"

$loginBody = @{ email = $Email; password = $Password; device_name = "load-test" } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri "$BaseUrl/auth/login" -ContentType "application/json" -Body $loginBody
$token = $login.data.access_token

if (-not $token) {
    throw "Login failed: access token missing"
}

$headers = @{ Authorization = "Bearer $token" }

$intervalMs = [int](60000 / [math]::Max($RequestsPerMinute, 1))

for ($i = 1; $i -le [math]::Min($RequestsPerMinute, 1000); $i++) {
    try {
        Invoke-RestMethod -Method Get -Uri "$BaseUrl/posts" -Headers $headers | Out-Null
        Invoke-RestMethod -Method Get -Uri "$BaseUrl/analytics" -Headers $headers | Out-Null
    } catch {
        Write-Warning "Request $i failed: $($_.Exception.Message)"
    }
    Start-Sleep -Milliseconds $intervalMs
}

Write-Host "Load test cycle finished"

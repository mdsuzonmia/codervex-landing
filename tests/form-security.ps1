param([int]$Port = 8765)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$rateDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ('codervex-form-test-' + [guid]::NewGuid())
New-Item -ItemType Directory -Path $rateDirectory | Out-Null

$env:APP_ENV = 'testing'
$env:TURNSTILE_SITE_KEY = '1x00000000000000000000AA'
$env:TURNSTILE_SECRET_KEY = 'test-secret'
$env:TURNSTILE_EXPECTED_HOSTNAME = 'localhost'
$env:TURNSTILE_TEST_RESPONSE = '{"success":true,"hostname":"localhost","action":"contact_form"}'
$env:TRUSTED_PROXIES = '127.0.0.1'
$env:FORM_RATE_LIMIT_DIR = $rateDirectory

$server = Start-Process -FilePath 'php' -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $projectRoot) -PassThru -WindowStyle Hidden
$baseUrl = "http://127.0.0.1:$Port/send-form.php"

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Host "PASS: $Message"
}

function New-FormSession([string]$CookieFile) {
    $json = curl.exe -s -c $CookieFile "$baseUrl`?config=1" | ConvertFrom-Json
    Assert-True ($json.success -eq $true) 'configuration and CSRF endpoint'
    return $json.csrfToken
}

function Submit-Contact([string]$CookieFile, [string]$Csrf, [string]$Ip, [string]$Token = 'valid-token', [string]$Honey = '', [string]$Email = 'visitor@example.com', [string]$Message = 'Please review https://example.com/path') {
    $response = curl.exe -s -b $CookieFile -H "X-Forwarded-For: $Ip" -w "`n%{http_code}" -X POST $baseUrl `
        --data-urlencode 'form_type=contact' `
        --data-urlencode "csrf_token=$Csrf" `
        --data-urlencode "website_field=$Honey" `
        --data-urlencode "cf-turnstile-response=$Token" `
        --data-urlencode 'Name=Test Visitor' `
        --data-urlencode "Email=$Email" `
        --data-urlencode 'Company=Example' `
        --data-urlencode 'Website=https://example.com' `
        --data-urlencode 'Consent=Yes' `
        --data-urlencode 'Service=Website fixes & troubleshooting' `
        --data-urlencode "Message=$Message"
    $parts = $response -split "`n"
    return @{ Status = [int]$parts[-1]; Body = (($parts[0..($parts.Length - 2)] -join "`n") | ConvertFrom-Json) }
}

try {
    Start-Sleep -Milliseconds 700

    $cookie = Join-Path $rateDirectory 'valid.cookies'
    $csrf = New-FormSession $cookie
    $multilingual = (-join ((1047, 1076, 1088, 1072, 1074, 1089, 1090, 1074, 1091, 1081, 1090, 1077) | ForEach-Object { [char]$_ })) + ' - please review https://example.com/path'
    $result = Submit-Contact $cookie $csrf '198.51.100.1' 'valid-token' '' 'visitor@example.com' $multilingual
    Assert-True ($result.Status -eq 200 -and $result.Body.success) 'valid multilingual message containing a URL'

    $cookie = Join-Path $rateDirectory 'turnstile.cookies'
    $csrf = New-FormSession $cookie
    $result = Submit-Contact $cookie $csrf '198.51.100.2' '__invalid_test_token__'
    Assert-True ($result.Status -eq 422 -and $result.Body.resetTurnstile) 'failed Turnstile verification'

    $cookie = Join-Path $rateDirectory 'honeypot.cookies'
    $csrf = New-FormSession $cookie
    $result = Submit-Contact $cookie $csrf '198.51.100.3' 'valid-token' 'spam'
    Assert-True ($result.Status -eq 422 -and -not $result.Body.success) 'honeypot detection'

    $cookie = Join-Path $rateDirectory 'validation.cookies'
    $csrf = New-FormSession $cookie
    $result = Submit-Contact $cookie $csrf '198.51.100.4' 'valid-token' '' 'bad-address'
    Assert-True ($result.Status -eq 422 -and $result.Body.message -match 'valid email') 'server-side validation error'

    $fourth = $null
    1..4 | ForEach-Object {
        $cookie = Join-Path $rateDirectory "rate-$_.cookies"
        $csrf = New-FormSession $cookie
        $fourth = Submit-Contact $cookie $csrf '198.51.100.5'
    }
    Assert-True ($fourth.Status -eq 429) 'three submissions per ten minutes per IP'
} finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    Remove-Item -LiteralPath $rateDirectory -Recurse -Force
}

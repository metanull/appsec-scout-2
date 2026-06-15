#!/usr/bin/env pwsh
[CmdletBinding()]
param(
    [Parameter(Mandatory, Position=0)]
    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential
)
$ErrorActionPreference = "Stop"

$hu  = "https://eesc-cor.atlassian.net"
$em  = $Credential.GetNetworkCredential().Username
$tk  = $Credential.GetNetworkCredential().Password
$cr  = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${em}:${tk}"))
$hd  = @{ Authorization = "Basic $cr"; "Content-Type" = "application/json"; Accept = "application/json" }
$aid = "557058:9387698f-8ff5-4ad0-8406-75338709a21d"

function Get-HttpErrorBody {
    param($ex)
    try {
        $resp = $ex.Exception.Response
        # PowerShell 7 wraps HttpClient responses differently
        if ($resp -is [System.Net.Http.HttpResponseMessage]) {
            return $resp.Content.ReadAsStringAsync().Result
        }
        if ($resp -ne $null) {
            $stream = $resp.GetResponseStream()
            $reader = New-Object System.IO.StreamReader($stream)
            return $reader.ReadToEnd()
        }
    } catch {}
    return $ex.ToString()
}

function Invoke-JiraPost($label, $body) {
    Write-Host "`n=== $label ==="
    try {
        $r = Invoke-WebRequest -Uri "$hu/rest/api/3/issue" -Headers $hd -Method POST -Body $body -ErrorAction Stop
        $j = $r.Content | ConvertFrom-Json
        Write-Host "OK: $($j.key)"
        return $j.key
    } catch {
        $text = Get-HttpErrorBody $_
        Write-Host "FAIL $($_.Exception.Response.StatusCode): $text"
        return $null
    }
}

function Invoke-JiraGet($label, $uri) {
    Write-Host "`n=== $label ==="
    try {
        $r = Invoke-WebRequest -Uri $uri -Headers $hd -Method GET -ErrorAction Stop
        return $r.Content | ConvertFrom-Json
    } catch {
        $text = Get-HttpErrorBody $_
        Write-Host "FAIL $($_.Exception.Response.StatusCode): $text"
        return $null
    }
}

# ─────────────────────────────────────────────────────────────────────────────
# CREATE-ISSUE tests
# ─────────────────────────────────────────────────────────────────────────────

$adfDesc = @{
    type    = "doc"
    version = 1
    content = @(@{
        type    = "paragraph"
        content = @(@{ type = "text"; text = "AppSec Scout test - ADF description." })
    })
}

# T2: with ADF description
$b = @{ fields = @{ project = @{ key = "SEC" }; summary = "[AppSec-Test-2] ADF description"; issuetype = @{ name = "Task" }; description = $adfDesc } } | ConvertTo-Json -Depth 10
Invoke-JiraPost "T2 - ADF description" $b | Out-Null

# T3: with assignee using 'accountId' (our current code)
$b = @{ fields = @{ project = @{ key = "SEC" }; summary = "[AppSec-Test-3] assignee via accountId"; issuetype = @{ name = "Task" }; assignee = @{ accountId = $aid } } } | ConvertTo-Json -Depth 10
Invoke-JiraPost "T3 - assignee via accountId" $b | Out-Null

# T4: with assignee using 'id' (API docs format)
$b = @{ fields = @{ project = @{ key = "SEC" }; summary = "[AppSec-Test-4] assignee via id"; issuetype = @{ name = "Task" }; assignee = @{ id = $aid } } } | ConvertTo-Json -Depth 10
Invoke-JiraPost "T4 - assignee via id" $b | Out-Null

# T5: with priority by name (our code)
$b = @{ fields = @{ project = @{ key = "SEC" }; summary = "[AppSec-Test-5] priority by name"; issuetype = @{ name = "Task" }; priority = @{ name = "High" } } } | ConvertTo-Json -Depth 10
Invoke-JiraPost "T5 - priority by name" $b | Out-Null

# T6: labels + ADF + assignee + priority (full payload like our code)
$b = @{
    fields = @{
        project     = @{ key = "SEC" }
        summary     = "[AppSec-Test-6] Full payload"
        issuetype   = @{ name = "Task" }
        description = $adfDesc
        labels      = @("security", "appsec-scout")
        priority    = @{ name = "High" }
        assignee    = @{ accountId = $aid }
    }
} | ConvertTo-Json -Depth 10
Invoke-JiraPost "T6 - full payload (labels+ADF+assignee+priority)" $b | Out-Null

# ─────────────────────────────────────────────────────────────────────────────
# SEARCH tests
# ─────────────────────────────────────────────────────────────────────────────

Write-Host "`n=== T7 - search/jql by summary (our current approach) ==="
$jql  = 'project = "SEC" AND summary ~ "AppSec-Test-1" ORDER BY created DESC'
$enc  = [Uri]::EscapeDataString($jql)
$uri  = "$hu/rest/api/3/search/jql?jql=$enc&maxResults=5&fields=summary,status"
$res  = Invoke-JiraGet "T7 - search by summary" $uri
if ($res) { Write-Host "Issues found: $($res.issues.Count)"; $res.issues | ForEach-Object { Write-Host "  $($_.key): $($_.fields.summary)" } }

Write-Host "`n=== T8 - search/jql by key (issue SEC-542 if it exists) ==="
$jql = 'key = "SEC-542"'
$enc = [Uri]::EscapeDataString($jql)
$uri = "$hu/rest/api/3/search/jql?jql=$enc&maxResults=5&fields=summary,status"
$res = Invoke-JiraGet "T8 - search by key" $uri
if ($res) { Write-Host "Issues found: $($res.issues.Count)"; $res.issues | ForEach-Object { Write-Host "  $($_.key): $($_.fields.summary)" } }

Write-Host "`n=== T9 - search/jql by key OR summary ==="
$jql = 'project = "SEC" AND (key = "SEC-542" OR summary ~ "SEC-542") ORDER BY created DESC'
$enc = [Uri]::EscapeDataString($jql)
$uri = "$hu/rest/api/3/search/jql?jql=$enc&maxResults=5&fields=summary,status"
$res = Invoke-JiraGet "T9 - search by key OR summary" $uri
if ($res) { Write-Host "Issues found: $($res.issues.Count)"; $res.issues | ForEach-Object { Write-Host "  $($_.key): $($_.fields.summary)" } }

Write-Host "`n=== T10 - issue picker API (correct tool for autocomplete) ==="
$q   = [Uri]::EscapeDataString("SEC-898")
$uri = "$hu/rest/api/3/issue/picker?query=$q&currentProjectId=SEC&showSubTasks=true"
$res = Invoke-JiraGet "T10 - issue picker for SEC-898" $uri
if ($res) { $res.sections | ForEach-Object { Write-Host "  Section: $($_.label)"; $_.issues | ForEach-Object { Write-Host "    $($_.key): $($_.summaryText)" } } }

Write-Host "`nDone."

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
$cr = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${em}:${tk}"))
$hd = @{ Authorization = "Basic $cr"; "Content-Type" = "application/json"; Accept = "application/json" }
$aid = "557058:9387698f-8ff5-4ad0-8406-75338709a21d"

function Post([string]$label, [string]$body) {
    Write-Host "`n=== $label ==="
    try {
        $response = Invoke-WebRequest -Uri "$hu/rest/api/3/issue" -Headers $hd -Method POST -Body $body -ErrorAction Stop
        $json = $response.Content | ConvertFrom-Json
        Write-Host "OK: $($json.key)"
    } catch {
        $errMsg = $_.ErrorDetails.Message
        if (-not $errMsg) { $errMsg = $_.Exception.Message }
        Write-Host "FAIL [$($_.Exception.Response.StatusCode)]: $errMsg"
    }
}

# Available priorities
Write-Host "`n=== Fetching available priorities ==="
try {
    $prio = (Invoke-WebRequest -Uri "$hu/rest/api/3/priority" -Headers $hd -EA Stop).Content | ConvertFrom-Json
    $prio | ForEach-Object { Write-Host "  - $($_.name) (id=$($_.id))" }
} catch {
    Write-Host "FAIL: $($_.ErrorDetails.Message)"
}

$adf = @{type="doc";version=1;content=@(@{type="paragraph";content=@(@{type="text";text="Test."})})}

# T-A: priority as plain string
Post "T-A priority as plain string 'High'" (
    @{fields=@{project=@{key="SEC"};summary="[AppSec-Test-A] priority=string";issuetype=@{name="Task"};priority="High"}} | ConvertTo-Json -Depth 10
)

# T-B: priority as {name}  (current code)
Post "T-B priority as {name:'High'} -- current code" (
    @{fields=@{project=@{key="SEC"};summary="[AppSec-Test-B] priority=obj-name";issuetype=@{name="Task"};priority=@{name="High"}}} | ConvertTo-Json -Depth 10
)

# T-D: full correct payload with priority as string
Post "T-D Full payload (priority as string, accountId, ADF desc)" (
    @{
        fields=@{
            project=@{key="SEC"}
            summary="[AppSec-Test-D] Full correct payload"
            issuetype=@{name="Task"}
            description=$adf
            labels=@("security","appsec-scout")
            priority="High"
            assignee=@{accountId=$aid}
        }
    } | ConvertTo-Json -Depth 10
)

Write-Host "`nDone."

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
$cr=[Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${em}:${tk}"))
$hd=@{Authorization="Basic $cr";"Content-Type"="application/json";Accept="application/json"}
$aid="557058:9387698f-8ff5-4ad0-8406-75338709a21d"

function Get([string]$label, [string]$uri) {
    Write-Host "`n=== $label ==="
    try {
        $r=Invoke-WebRequest -Uri $uri -Headers $hd -Method GET -EA Stop
        return $r.Content | ConvertFrom-Json
    } catch {
        Write-Host "FAIL [$($_.Exception.Response.StatusCode)]: $($_.ErrorDetails.Message)"
        return $null
    }
}
function Post([string]$label, [string]$body) {
    Write-Host "`n=== $label ==="
    try {
        $r=Invoke-WebRequest -Uri "$hu/rest/api/3/issue" -Headers $hd -Method POST -Body $body -EA Stop
        $j=$r.Content|ConvertFrom-Json; Write-Host "OK: $($j.key)"
    } catch {
        Write-Host "FAIL [$($_.Exception.Response.StatusCode)]: $($_.ErrorDetails.Message)"
    }
}

# Fetch createmeta for Task issuetype in SEC project
$meta = Get "createmeta - issuetypes for SEC" "$hu/rest/api/3/issue/createmeta/SEC/issuetypes"
if ($meta) {
    Write-Host "Issue types:"
    $meta.issueTypes | ForEach-Object { Write-Host "  $($_.id): $($_.name)" }
    $taskType = $meta.issueTypes | Where-Object { $_.name -eq "Task" }
    if ($taskType) {
        Write-Host "`nTask type id: $($taskType.id)"
        $fields = Get "createmeta - fields for Task in SEC" "$hu/rest/api/3/issue/createmeta/SEC/issuetypes/$($taskType.id)?maxResults=100"
        if ($fields) {
            Write-Host "Fields available on create screen:"
            $fields.fields | ForEach-Object { 
                Write-Host "  $($_.key) / $($_.name) [required=$($_.required), schema=$($_.schema.type)]"
            }
        }
    }
}

$adf=@{type="doc";version=1;content=@(@{type="paragraph";content=@(@{type="text";text="Test."})})}

# T-F: full payload WITHOUT priority (to confirm base case works)
Post "T-F Full payload WITHOUT priority" (@{
    fields=@{
        project=@{key="SEC"}
        summary="[AppSec-Test-F] Full payload no priority"
        issuetype=@{name="Task"}
        description=$adf
        labels=@("security","appsec-scout")
        assignee=@{accountId=$aid}
    }
}|ConvertTo-Json -Depth 10)

# T-G: priority with valid name as object {name}
Post "T-G priority={name:'Major'} valid name object" (@{
    fields=@{
        project=@{key="SEC"}
        summary="[AppSec-Test-G] priority object valid name"
        issuetype=@{name="Task"}
        priority=@{name="Major"}
    }
}|ConvertTo-Json -Depth 10)

# T-H: priority with valid id as object {id}
Post "T-H priority={id:'10002'} valid id object" (@{
    fields=@{
        project=@{key="SEC"}
        summary="[AppSec-Test-H] priority object valid id"
        issuetype=@{name="Task"}
        priority=@{id="10002"}
    }
}|ConvertTo-Json -Depth 10)

Write-Host "`nDone."

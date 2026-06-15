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

function Post($label, $b) {
    Write-Host "`n=== $label ==="
    try {
        $r=Invoke-WebRequest -Uri "$hu/rest/api/3/issue" -Headers $hd -Method POST -Body $b -EA Stop
        Write-Host "OK: $(($r.Content|ConvertFrom-Json).key)"
    } catch {
        $err = $_.Exception.Response.Content.ReadAsStringAsync().Result
        Write-Host "FAIL $($_.Exception.Response.StatusCode): $err"
    }
}

$adf=@{type="doc";version=1;content=@(@{type="paragraph";content=@(@{type="text";text="Test."})})}

# T-A: priority as plain string (proposed fix)
Post "T-A: priority as plain string 'High'" (@{fields=@{project=@{key="SEC"};summary="[AppSec-Test-A] priority=string";issuetype=@{name="Task"};priority="High"}}|ConvertTo-Json -Depth 10)

# T-B: priority as object {name} (current broken code)
Post "T-B: priority as object {name:'High'}" (@{fields=@{project=@{key="SEC"};summary="[AppSec-Test-B] priority=obj-name";issuetype=@{name="Task"};priority=@{name="High"}}}|ConvertTo-Json -Depth 10)

# T-C: priority as object {id} (API docs suggest id)
Post "T-C: priority as object {id}" (@{fields=@{project=@{key="SEC"};summary="[AppSec-Test-C] priority=obj-id";issuetype=@{name="Task"};priority=@{id="2"}}}|ConvertTo-Json -Depth 10)

# T-D: full correct payload with priority as string (proposed fix applied)
Post "T-D: Full correct payload" (@{
    fields=@{
        project=@{key="SEC"}
        summary="[AppSec-Test-D] Full correct payload with priority as string"
        issuetype=@{name="Task"}
        description=$adf
        labels=@("security","appsec-scout")
        priority="High"
        assignee=@{accountId=$aid}
    }
}|ConvertTo-Json -Depth 10)

Write-Host "`nDone."

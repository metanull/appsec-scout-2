<#
.SYNOPSIS
    Tests an Azure DevOps Personal Access Token.
.DESCRIPTION
    Tests the validity of an Azure DevOps Personal Access Token by making a single
    lightweight request to the Azure DevOps REST API (listing one project), then looks
    up the real identity (display name + email) the PAT belongs to via the profile API.
    Use this to isolate a credential/organization/network problem before running a full
    invoke-ops.ps1 -SbomScan, which can take a long time before surfacing the
    same failure.
.PARAMETER Credential
    Azure DevOps credential. Password = Personal Access Token with "Code (Read)" scope
    across the organization. UserName is not used for authentication (Azure DevOps PATs
    authenticate via Basic auth with an empty username) — the script instead resolves
    and displays the real account the PAT belongs to via the profile API, since the
    UserName you type into Get-Credential is just a free-text label with no bearing on
    who the token actually authenticates as.
.PARAMETER Organization
    Azure DevOps organization name. Defaults to "EESC-CoR".
.EXAMPLE
    .\test-AzureDevOpsToken.ps1 -Credential (Get-Credential -UserName 'PAT' -Message 'Enter your Azure DevOps PAT')
.EXAMPLE
    .\test-AzureDevOpsToken.ps1 -Credential (Get-Secret -Name 'AzureDevOps') -Organization 'EESC-CoR'
#>
[CmdletBinding()]
param(
    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential,

    [string]$Organization = 'EESC-CoR'
)

$pat = $Credential.GetNetworkCredential().Password

if ([string]::IsNullOrWhiteSpace($pat)) {
    Write-Host "No PAT provided (Credential password was empty)."
    exit 1
}

$basicAuth = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes(":$pat"))
$headers = @{ Authorization = "Basic $basicAuth" }
$uri = "https://dev.azure.com/$Organization/_apis/projects?api-version=7.1&`$top=1"

try {
    $res = Invoke-RestMethod -Uri $uri -Headers $headers -Method GET -ErrorAction Stop
    Write-Host "Token is valid for organization '$Organization'"
    Write-Host "  Total projects visible: $($res.count)"
    if ($res.value.Count -gt 0) {
        Write-Host "  Sample project: $($res.value[0].name)"
    }

    $profileUri = "https://vssps.dev.azure.com/$Organization/_apis/profile/profiles/me?api-version=7.1"
    try {
        $profile = Invoke-RestMethod -Uri $profileUri -Headers $headers -Method GET -ErrorAction Stop
        Write-Host "  Belongs to: $($profile.displayName) <$($profile.emailAddress)>"
    } catch {
        Write-Host "  Belongs to: could not resolve identity (profile API call failed)."
    }
} catch {
    Write-Host "Token invalid, expired, insufficiently scoped, or the organization name is wrong."

    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode) {
        Write-Host "  HTTP status: $statusCode"
    }

    if ($_.ErrorDetails.Message) {
        Write-Host "  Response body (first 500 chars):"
        Write-Host "  $($_.ErrorDetails.Message.Substring(0, [Math]::Min(500, $_.ErrorDetails.Message.Length)))"
    } else {
        Write-Host "  $($_.Exception.Message)"
    }

    Write-Host ""
    Write-Host "Common causes: PAT expired or missing 'Code (Read)' scope, wrong -Organization name,"
    Write-Host "or a corporate proxy intercepting the request (check for an HTML sign-in/block page above)."

    exit 1
}

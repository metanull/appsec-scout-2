<#
.SYNOPSIS
    Tests a GitHub Personal Access Token.
.DESCRIPTION
    Tests the validity of a GitHub Personal Access Token by making a request to the GitHub API.
.PARAMETER Credential
    GitHub credential for testing the token.
.EXAMPLE
    .\test-GitHubToken -Credential (Get-Credential -UserName 'PAT' -Message 'Enter your GitHub Personal Access Token')
.EXAMPLE
    .\test-GitHubToken -Credential (Get-Secret -Name 'GitHub PAT')
#>
[CmdletBinding()]
param(
    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential
)
try {
    $token = $Credential.GetNetworkCredential().Password
    $headers = @{
        Authorization = "token $token"
        Accept        = "application/vnd.github+json"
    }

    $res = Invoke-RestMethod -Uri "https://api.github.com/user" -Headers $headers -Method GET -ErrorAction Stop
    Write-Host "✅ Token is valid"
    $res.login   # shows which account

    $responseHeaders = $null
    Invoke-RestMethod `
        -Uri "https://api.github.com" `
        -Headers $headers `
        -Method GET `
        -ResponseHeadersVariable responseHeaders `

    $responseHeaders["X-OAuth-Scopes"]
}
catch {
    Write-Host "❌ Token invalid or insufficient permissions"
    $_.Exception.Response.StatusCode.value__
}
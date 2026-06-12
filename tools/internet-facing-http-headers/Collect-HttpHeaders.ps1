<#
    .SYNOPSIS
        Collect unusual headers from a collection of URLs.
    .DESCRIPTION
        Send a short series of HTTP requests to each URL, trying different http protocols and HTTP methods
    .PARAMETER UrlListDataFile
        A PSD1 file defining the list of URL to test as UrlList (see example UrlList.psd1.example)
    .PARAMETER Url
        An Url to test. The paramter can be used in a pipepine to test multiple addresses
    .EXAMPLE
        . .\Collect-HttpHeaders.ps1 -UrlListDataFile UrlList.psd1.example
    .EXAMPLE
        . .\Collect-HttpHeaders.ps1 -Url 'https://aka.ms'
    .EXAMPLE
        @('https://google.com','aka.ms','gmail.com') | . .\Collect-HttpHeaders.ps1
#>
[CmdletBinding()]
param(
    [Parameter(ParameterSetName = 'File',Mandatory)]
    [ValidateScript({Test-Path $_})]
    [string] $UrlListDataFile,

    [Parameter(ParameterSetName = 'List',Mandatory,ValueFromPipeline)]
    [string] $Url

)
Begin {
    if (-not $ErrorActionPreference) {
        $ErrorActionPreference = "Stop"
    }
    if (-not $InformationPreference) {
        $InformationPreference = "SilentlyContinue"
    }
    if (-not $VerbosePreference) {
        $VerbosePreference = "SilentlyContinue"
    }

    $Settings = Import-PowerShellDataFile -Path "$($PSScriptRoot)\Settings.psd1"
}
Process {
    if( $PSCmdlet.ParameterSetName -eq 'File' ) {
        $UrlListData = Import-PowerShellDataFile -Path $UrlListDataFile
        $UrlList = $UrlListData.UrlList
    } else {
        $UrlList = ,$Url |? { $_ }
    }

    $UrlListCount = $UrlList.Count
    $UrlListIndex = 0
    Write-Progress -Activity "Processing URLs" -Status 'Starting' -PercentComplete 0
    $UrlList | Where-Object { $_ } | ForEach-Object {
        $Url = $_.Trim()
        $UrlListIndex++
        Write-Progress -Activity "Processing URLs" -Status "Processing $UrlListIndex of $UrlListCount" -PercentComplete (($UrlListIndex / $UrlListCount) * 100) 

        try {
            # Some URL in the input are just domains without scheme, add https:// as default
            if (-not ($Url.StartsWith('http://', [StringComparison]::OrdinalIgnoreCase) -or $Url.StartsWith('https://', [StringComparison]::OrdinalIgnoreCase))) {
                $Url = "https://$Url"
            }

            # Check that hostname is valid and can be resolved before making requests
            $Uri = [Uri]$Url
            $HostEntry = [System.Net.Dns]::GetHostEntry($Uri.Host)
            if (-not $HostEntry.AddressList -or $HostEntry.AddressList.Count -eq 0) {
                throw "Hostname '$($Uri.Host)' could not be resolved to any IP addresses."
            }

            # Send multiple requests with different HTTP versions and methods to capture a wide range of headers
            # Executes each request (a failure does not stop the others) and captures unique headers from successful responses
            $UniqueHeaders = @()
            $MaxRedirections = 0..3
            foreach ($HttpVersion in $Settings.HttpVersions) {
                foreach ($Method in $Settings.Methods) {
                    foreach ($MaxRedirection in $MaxRedirections) {
                        Write-Progress -Activity "Processing URLs" -Status "Processing $($UrlListIndex) of $($UrlListCount): HTTP/$($HttpVersion) $($Method) $($Url) (MaxRedirection=$($MaxRedirection))" -PercentComplete (($UrlListIndex / $UrlListCount) * 100) 
                        try {
                            $Response = Invoke-WebRequest -HttpVersion $HttpVersion -Uri $Url -UseBasicParsing -SkipHttpErrorCheck -DisableKeepAlive -MaximumRedirection $MaxRedirection -Method $Method -ErrorAction Stop
                            $Response.Headers.GetEnumerator() | ForEach-Object { 
                                Write-Debug "HTTP/$($HttpVersion) $($Method) $($Url) > $($_.Key): $($_.Value)"
                                if ($Settings.IgnoredHeaders -notcontains $_.Key) {
                                    $HeaderLine = "$($_.Key): $($_.Value)"
                                    Write-Debug $HeaderLine
                                    if ($UniqueHeaders -notcontains $HeaderLine) {
                                        $UniqueHeaders += $HeaderLine
                                        Write-Information -ForegroundColor Green "HTTP/$($HttpVersion) $($Method) $($Url) > $($HeaderLine)"
                                    }
                                }
                            }
                        } catch {
                            Write-Debug "$Method request failed for $($Url) with HTTP/$($HttpVersion): $($_)"
                        }
                    }
                }
            }
            Write-Debug "Retrieved $($UniqueHeaders.Count) unique headers for $Url"
            [PSCustomObject]@{
                Url = $Url
                Headers = $UniqueHeaders
            }
        }
        catch {
            Write-Warning "Failed to retrieve headers for $($Url): $($_)"
        }
    } | Where-Object {
        $_.Headers -ne $null -and $_.Headers.Count -gt 0
    } | Tee-Object -Variable Results

    # $Results | % {"`n> **$($_.Url)**"; $_.Headers |% {"> * $_"}} | Write-File "$($Env:Temp)\UrlList-Headers.md"
}
End {
    Write-Progress -Activity "Processing URLs" -Status 'Done' -PercentComplete 100 -Completed
}

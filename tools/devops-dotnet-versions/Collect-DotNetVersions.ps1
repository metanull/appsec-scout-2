#Requires -Module AzureDevOpsIngest
<#
.SYNOPSIS
    Collects the .Net versions used in the projects of an Azure DevOps organization and optionally writes the results to an Excel file.
.DESCRIPTION
    This script connects to an Azure DevOps organization using the provided credentials, retrieves all projects and their repositories, clones each repository, searches for .csproj files, extracts the target framework versions, and compiles a report of the findings. If the WriteXls switch is specified, it also writes the results to an Excel file with details about each project and repository.
.PARAMETER Credential
    The credentials to use for connecting to the Azure DevOps organization. This should be a PSCredential object containing a personal access token with appropriate permissions.
.PARAMETER WriteXls
    If specified, the script will create an Excel file and write the collected data into it. The Excel file will contain columns for Project, Repository, Framework Version, and other relevant details.
.PARAMETER Organization
    The name of the Azure DevOps organization to connect to. Defaults to "EESC-CoR".
.PARAMETER ProjectFilter
    An optional filter to apply when selecting projects to analyze.
.PARAMETER RepositoryFilter
    An optional filter to apply when selecting repositories to analyze.
.PARAMETER CSProjFilter
    An optional filter to apply when selecting .csproj files to analyze.
.EXAMPLE
    # Connects to the Azure DevOps organization using the provided credentials, collects .Net version information from the projects, and writes the results to an Excel file.
    .\Collect-DotNetVersions.ps1 -Credential (Get-Credential -Message 'DevOps Personal Access Token' -UserName 'PAT') -WriteXls
.EXAMPLE
    # Connects to the Azure DevOps organization using the provided credentials, collects .Net version information from the projects, and writes the results to an Excel file.
    .\Collect-DotNetVersions.ps1 -Credential (Get-Credential -Message 'DevOps Personal Access Token' -UserName 'PAT') -WriteXls
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory, Position=0)]
    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential,

    [Parameter(Mandatory = $false)]
    [switch]
    $WriteXls,

    [Parameter(Mandatory = $false)]
    [string]
    $Organization = "EESC-CoR",

    [Parameter(Mandatory = $false)]
    [string]
    $WorkDirectory = $Env:TEMP,

    [Parameter(Mandatory = $false)]
    [string]
    $ProjectFilter,

    [Parameter(Mandatory = $false)]
    [string]
    $RepositoryFilter,

    [Parameter(Mandatory = $false)]
    [string]
    $CSProjFilter
)
if (-not $ErrorActionPreference) {
    $ErrorActionPreference = "Stop"
}
if (-not $InformationPreference) {
    $InformationPreference = "SilentlyContinue"
}
if (-not $VerbosePreference) {
    $VerbosePreference = "SilentlyContinue"
}

$SupportPolicy = @{
    # .Net Core
    'net10.0' = Get-Date "2028-11-14"
    'net9.0' = Get-Date "2026-11-10"
    'net9.0-windows' = Get-Date "2026-11-10"
    'net9.0-windows10.0.19041.0' = Get-Date "2026-11-10"
    'net8.0' = Get-Date "2026-11-10"

    # .Net Framework
    'v4.8.1' = Get-Date "2028-08-05"
    'v4.8' = Get-Date "2028-08-05"
    'v4.7.2' = Get-Date "2028-08-05"
    'v4.7.1' = Get-Date "2028-08-05"
    'v4.7' = Get-Date "2028-08-05"
    'v4.6.2' = Get-Date "2027-01-12"
    'v3.5.1' = Get-Date "2029-01-09"

    # .Net Standard
    'netstandard2.1' = Get-Date "2028-08-05"
    'netstandard2.0' = Get-Date "2028-08-05"
    'netstandard1.0' = Get-Date "2028-08-05"

    # Legacy .Net Core
    'netcoreapp3.1' = Get-Date "2022-12-01"
    'netcoreapp3.0' = Get-Date "2020-03-01"
    'netcoreapp2.2' = Get-Date "2019-12-01"
    'netcoreapp2.1' = Get-Date "2021-08-01"
    'netcoreapp2.0' = Get-Date "2018-10-01"
    'netcoreapp1.1' = Get-Date "2019-11-01"
    'netcoreapp1.0' = Get-Date "2019-06-01"
}

if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
    try {
        $excel = New-Object -ComObject Excel.Application
    } catch {
        throw "Excel is not installed or COM automation is disabled.Error: $_"
    }
    $workbook = $excel.Workbooks.Add()
    $sheet = $workbook.Worksheets.Item(1)
    $sheet.Name = "Azure DevOps .Net Versions"

    $sheet.Cells.Item(1,1).Value2 = "Project"
    $sheet.Cells.Item(1,2).Value2 = "Repository"
    $sheet.Cells.Item(1,3).Value2 = "Framework"
    $sheet.Cells.Item(1,4).Value2 = "Version"
    $sheet.Cells.Item(1,5).Value2 = "End of Support"
    $sheet.Cells.Item(1,6).Value2 = "OutputType"
    $sheet.Cells.Item(1,7).Value2 = "AssemblyName"
    $sheet.Cells.Item(1,8).Value2 = "Git"
    $sheet.Cells.Item(1,9).Value2 = "CSProj"
    $sheet.Cells.Item(1,10).Value2 = "SupportRisk"
    
}
$Projects = Get-Project -Organization $Organization -Credential $Credential | Where-Object {
    if ($ProjectFilter) {
        $_.Name -match $ProjectFilter
    } else {
        $true
    }
}
Write-Information "Found $($Projects.Count) projects in organization $Organization"
$CurrentProjectIndex = 0
Write-Progress -Activity "Processing projects" -Status 'Starting' -PercentComplete 0
$Counters = @{
    Projects = 0
    Repositories = 0
    DisabledRepositories = 0
    ClonedRepositories = 0
    CloneFailures = 0
    CsProjFiles = 0
    DotNetCoreProjects = 0
    DotNetFrameworkProjects = 0
    DotNetUnknownProjects = 0
    DotNetSupportPolicyMatches = 0
    DotNetSupportPolicyMismatches = 0
}
$Projects <#| Where-Object {$_.Name -match 'Portal'}#> <#| Select-Object -First 2 -Skip 2#> | ForEach-Object {
    $Counters.Projects++
    $CurrentProject = $_
    Write-Information "Project: $($CurrentProject.Name) ($($CurrentProject.Id))"

    Write-Progress -Activity "Processing projects" -Status $CurrentProject.Name -PercentComplete ($CurrentProjectIndex / $Projects.Count * 100)
    $CurrentProjectIndex++

    $Repositories = Get-ProjectRepository -Organization $Organization -ProjectId $CurrentProject.Id -Credential $Credential | Where-Object {
        if ($RepositoryFilter) {
            $_.Name -match $RepositoryFilter
        } else {
            $true
        }
    }
    $CurrentRepositoryIndex = 0
    Write-Information "  Found $($Repositories.Count) repositories in project $($CurrentProject.Name)"
    $Repositories <#| Where-Object {$_.Name -match 'Portal$'}#> | ForEach-Object {
        $Counters.Repositories++
        $CurrentRepository = $_
        Write-Verbose "  Repository: $($CurrentRepository.Name) ($($CurrentRepository.Id)) - $($CurrentRepository.webUrl)"

        Write-Progress -Activity "Processing projects" -Status "$($CurrentProject.Name) ($($CurrentRepositoryIndex + 1) of $($Repositories.Count)) - $($CurrentRepository.Name)" -PercentComplete ($CurrentProjectIndex / $Projects.Count * 100)
        $CurrentRepositoryIndex++

        if ($CurrentRepository.isDisabled -eq $true) {
            Write-Warning "Repository $($CurrentRepository.Name) is disabled, skipping."
            $Counters.DisabledRepositories++
            return
        }

        $SCMDirectory = [guid]::NewGuid().ToString()
        $SCMPath = Join-Path $WorkDirectory $SCMDirectory

        git clone --depth 1 --no-tags --shallow-submodules $CurrentRepository.webUrl $SCMPath 2>&1 | Write-Debug
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Failed to clone repository $($CurrentRepository.Name) ($($CurrentRepository.webUrl))"
            $Counters.CloneFailures++
            return
        }
        $Counters.ClonedRepositories++

        $ProjectFiles = Get-ChildItem -File -Path $SCMPath -Filter "*.csproj" -Recurse
        Write-Debug "    Found $($ProjectFiles.Count) .csproj files in $($SCMPath) - repository $($CurrentRepository.Name)"

        $BuildPropsFiles = Get-ChildItem -File -Path $SCMPath -Filter "Directory.Build.props" -Recurse
        Write-Debug "    Found $($BuildPropsFiles.Count) Directory.Build.props files in $($SCMPath) - repository $($CurrentRepository.Name)"

        $AllFiles = @()
        $ProjectFiles | Foreach-Object {
            if ($_ -ne $null) {
                $AllFiles += $_
            }
        }
        $BuildPropsFiles | Foreach-Object {
            if ($_ -ne $null) {
                $AllFiles += $_
            }
        }
        Write-Verbose "    Found $($AllFiles.Count) .csproj and Directory.Build.props files in $($SCMPath) - repository $($CurrentRepository.Name)"
        $AllFiles | Where-Object {
            $_ -ne $null
        } | Where-Object {
            if ($CSProjFilter) {
                "$($_)" -match $CSProjFilter
            } else {
                "$($_)" -notmatch "Test[^\\]*$"
            }
        } | ForEach-Object {
            $CurrentCSProj = $_
            $Counters.CsProjFiles++

            $CSProj = [xml](Get-Content $CurrentCSProj.FullName)

            try {
                $CsProjRelativePath = [System.IO.Path]::GetRelativePath($SCMPath, $CurrentCSProj.FullName)
            } catch {
                Write-Warning "Failed to resolve paths for repository $($CurrentRepository.Name) - $($CurrentCSProj.FullName): $($_)"
                $CsProjRelativePath = $CurrentCSProj.FullName
            }

            $OutputObject = [pscustomobject]@{
                Project = $CurrentProject.Name
                Repository = $CurrentRepository.Name
                FrameworkVersion = @()
                DotNetCore = $null
                DotNetSupportPolicy = $null
                More = @{
                    CSProj = $CsProjRelativePath
                    OutputType = $null
                    AssemblyName = $null
                    Project = $CurrentProject
                    Repository = $CurrentRepository
                }
            }

            $CSProj.Project.PropertyGroup.TargetFrameworks | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Debug "      Target Frameworks: $_"
                $OutputObject.FrameworkVersion += $_
                $OutputObject.DotNetCore = $true
                $Counters.DotNetCoreProjects++
            }
            $CSProj.Project.PropertyGroup.TargetFramework | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Debug "      Target Framework: $_"
                $OutputObject.FrameworkVersion += $_
                $OutputObject.DotNetCore = $true
                $Counters.DotNetCoreProjects++
            }
            $CSProj.Project.PropertyGroup.TargetFrameworkVersion | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Debug "      Target Framework Version: $_"
                $OutputObject.FrameworkVersion += $_
                # If TargetFrameworkVersion is used, it's a legacy (pre .net core) project file format
                $OutputObject.DotNetCore = $false
                $Counters.DotNetFrameworkProjects++
            }

            $FirstFrameworkVersion = $OutputObject.FrameworkVersion | Where-Object {
                    $_ -ne $null
                } | Select-Object -First 1 | Foreach-Object {
                    $_.Trim()
                }
            if ($FirstFrameworkVersion -ne $null -and $SupportPolicy.ContainsKey($FirstFrameworkVersion)) {
                $OutputObject.DotNetSupportPolicy = $SupportPolicy[$FirstFrameworkVersion]
                if ($OutputObject.DotNetSupportPolicy -lt (Get-Date)) {
                    $Counters.DotNetSupportPolicyMismatches++
                } else {
                    $Counters.DotNetSupportPolicyMatches++
                }
            } else {
                Write-Debug "No support policy information found for framework version '$($FirstFrameworkVersion)' in project file $($CurrentCSProj.FullName)"
                $Counters.DotNetSupportPolicyMismatches++
            }
            <#
            $CSProj.Project.PropertyGroup.Version | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Debug "      Version: $_"
                $OutputObject.FrameworkVersion += $_
                $OutputObject.DotNetCore = $null
                $Counters.DotNetUnknownProjects++
            }
            #>
            if ($OutputObject.FrameworkVersion.Count -ne 0) {
                $OutputObject.FrameworkVersion = $OutputObject.FrameworkVersion | Foreach-Object {$_.Trim()} | Select-Object -Unique | Join-String -Separator ", "
                $OutputObject.More.OutputType = $CSProj.Project.PropertyGroup.OutputType | Where-Object {$_ -ne $null}
                $OutputObject.More.AssemblyName = $CSProj.Project.PropertyGroup.AssemblyName | Where-Object {$_ -ne $null}
            } else {
                Write-Debug "No Target Framework found in project file $($CurrentCSProj.FullName)"
            }

            $OutputObject | Write-Output
        }
        Start-Job -ScriptBlock {
            param($path)
            # Start-Sleep -Seconds 5
            Remove-Item -Path $path -Recurse -Force -ErrorAction SilentlyContinue
        } -ArgumentList $SCMPath | Out-Null
    }
} | Tee-Object -Variable Results |
    Where-Object {
        "$($_.FrameworkVersion)" -ne ""
    } |
    Foreach-Object {
        if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
            $Row = $sheet.UsedRange.Rows.Count + 1
            $CurrentValues = @{
                Project = "$($_.Project)"
                Repository = "$($_.Repository)"
                Framework = $_.DotNetCore -eq $true ? ".Net Core" : ($_.DotNetCore -eq $false ? ".Net" : "")
                FrameworkVersion = "$($_.FrameworkVersion)"
                SupportPolicy = $_.DotNetSupportPolicy -ne $null ? $_.DotNetSupportPolicy : "Not Supported"
                SupportRisk = 
                    ($_.DotNetSupportPolicy -ne $null -and $_.DotNetSupportPolicy -ge (Get-Date).AddMonths(12)) ? 0 : (
                        ($_.DotNetSupportPolicy -ne $null -and $_.DotNetSupportPolicy -ge (Get-Date)) ? 1 : (
                            ($_.DotNetSupportPolicy -ne $null -and $_.DotNetSupportPolicy -lt (Get-Date)) ? 2 : (3)))
                OutputType =  $_.More -ne $null -and $_.More.OutputType -ne $null ? "$($_.More.OutputType)" : $null
                AssemblyName =  $_.More -ne $null -and $_.More.AssemblyName -ne $null ? "$($_.More.AssemblyName)" : $null
                Git = $_.More -ne $null -and $_.More.Repository -ne $null -and $_.More.Repository.webUrl -ne $null ? "$($_.More.Repository.webUrl)" : $null
                CSProj = $_.More -ne $null -and $_.More.CSProj -ne $null ? "$($_.More.CSProj)" : $null
            }
            
            $sheet.Cells.Item($Row,1).Value2 = [string]($CurrentValues.Project)
            $sheet.Cells.Item($Row,2).Value2 = [string]($CurrentValues.Repository)
            $sheet.Cells.Item($Row,3).Value2 = [string]($CurrentValues.Framework)
            $sheet.Cells.Item($Row,4).Value2 = [string]($CurrentValues.FrameworkVersion)
            if($CurrentValues.SupportPolicy -is [DateTime]) {
                $sheet.Cells.Item($Row,5).NumberFormat = $Settings.ExcelDateFormat
                $sheet.Cells.Item($Row,5).Value2 = [DateTime]($CurrentValues.SupportPolicy)
            } else {
                $sheet.Cells.Item($Row,5).Value2 = [string]($CurrentValues.SupportPolicy)
            }
            $sheet.Cells.Item($Row,6).Value2 = [string]($CurrentValues.OutputType)
            $sheet.Cells.Item($Row,7).Value2 = [string]($CurrentValues.AssemblyName)
            $sheet.Cells.Item($Row,8).Value2 = [string]($CurrentValues.Git)
            $sheet.Cells.Item($Row,9).Value2 = [string]($CurrentValues.CSProj)
            $sheet.Cells.Item($Row,10).Value2 = [string]($CurrentValues.SupportRisk)

            switch ($CurrentValues.SupportRisk) {
                0 { $InteriorColor = [System.Drawing.Color]::Green }
                1 { $InteriorColor = [System.Drawing.Color]::Yellow }
                2 { $InteriorColor = [System.Drawing.Color]::Red }
                default { $InteriorColor = [System.Drawing.Color]::Red }
            }
            @(3,4,5,10) | ForEach-Object {
                $sheet.Cells.Item($Row,$_).Interior.Color = [System.Drawing.ColorTranslator]::ToOle($InteriorColor)
            }
        } else {
            $_ | Write-Output
        }
    }
if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
    $excel.Visible = $true
    $excel.UserControl = $true
    $workbook.Saved = $false
}
Write-Progress -Activity "Processing projects" -Status "Completed" -Completed
Write-Verbose "Data collection complete and available in `$Results, `$Repositories and `$Projects variables."

Write-Warning "Summary of collected data:"
Write-Warning "  Total Projects: $($Counters.Projects)"
Write-Warning "  Total Repositories: $($Counters.Repositories)"
Write-Warning "  Disabled Repositories: $($Counters.DisabledRepositories)"
Write-Warning "  Cloned Repositories: $($Counters.ClonedRepositories)"
Write-Warning "  Clone Failures: $($Counters.CloneFailures)"
Write-Warning "  Total .csproj and Directory.Build.props files: $($Counters.CsProjFiles)"
Write-Warning "  Projects targeting .Net Core: $($Counters.DotNetCoreProjects)"
Write-Warning "  Projects targeting .Net Framework: $($Counters.DotNetFrameworkProjects)"
Write-Warning "  Projects with unknown .Net version: $($Counters.DotNetUnknownProjects)"
Write-Warning "  Projects with supported .Net versions: $($Counters.DotNetSupportPolicyMatches)"
Write-Warning "  Projects with unsupported or unknown .Net versions: $($Counters.DotNetSupportPolicyMismatches)"

[System.Runtime.Interopservices.Marshal]::ReleaseComObject($sheet) | Out-Null
[System.Runtime.Interopservices.Marshal]::ReleaseComObject($workbook) | Out-Null
[System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) | Out-Null
[GC]::Collect()
[GC]::WaitForPendingFinalizers()
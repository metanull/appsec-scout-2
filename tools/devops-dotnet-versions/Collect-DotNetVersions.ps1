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

    [switch]
    $WriteXls,

    [string]
    $Organization = "EESC-CoR"
)
if (-not $ErrorActionPreference) {
    $ErrorActionPreference = "Stop"
}
if (-not $InformationPreference) {
    $InformationPreference = "Continue"
}
if (-not $VerbosePreference) {
    $VerbosePreference = "SilentlyContinue"
}

if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
    $excel = New-Object -ComObject Excel.Application
    $workbook = $excel.Workbooks.Add()
    $sheet = $workbook.Worksheets.Item(1)
    $sheet.Name = "DataSheet"

    $sheet.Cells.Item(1,1).Value2 = "Project"
    $sheet.Cells.Item(1,2).Value2 = "Repository"
    $sheet.Cells.Item(1,3).Value2 = "Framework"
    $sheet.Cells.Item(1,4).Value2 = "Version"
    $sheet.Cells.Item(1,5).Value2 = "CSProj"
    $sheet.Cells.Item(1,6).Value2 = "OutputType"
    $sheet.Cells.Item(1,7).Value2 = "AssemblyName"
    $sheet.Cells.Item(1,8).Value2 = "Git"
}
(Get-Project -Organization $Organization -Credential $Credential | Tee-Object -Variable Projects).Value
Write-Information "Found $($Projects.Count) projects in organization $Organization"
$CurrentProjectIndex = 0
Write-Progress -Activity "Processing projects" -Status 'Starting' -PercentComplete 0
$Projects <#| Where-Object {$_.Name -match 'Portal'}#> | ForEach-Object {
    Write-Information "Project: $($_.Name) ($($_.Id))"

    Write-Progress -Activity "Processing projects" -Status $_.Name -PercentComplete ($CurrentProjectIndex / $Projects.Count * 100)
    $CurrentProjectIndex++

    $CurrentProject = $_
    (Get-ProjectRepository -Organization $Organization -ProjectId $_.Id -Credential $Credential | Tee-Object -Variable Repositories).Value
    $Repositories <#| Where-Object {$_.Name -match 'Portal$'}#> | ForEach-Object {
        $CurrentRepository = $_
        Write-Information "  Repository: $($_.Name) ($($_.Id)) - $($_.webUrl)"
        git clone $_.webUrl "$env:TEMP\$($_.Name).git" 2>&1 | Write-Verbose
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Failed to clone repository $($_.Name)"
            return
        }
        Get-ChildItem -Path "$env:TEMP\$($_.Name).git" -Filter "*.csproj" -Recurse | ForEach-Object {
            Write-Information "    Found project file: $($_.FullName)"
            $CSProj = [xml](Get-Content $_.FullName)
            $FrameworkVersion = @()
            $Legacy = $false

            $CSProj.Project.PropertyGroup.TargetFrameworks | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Frameworks: $_"
                $FrameworkVersion += $_
            }
            $CSProj.Project.PropertyGroup.TargetFramework | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Framework: $_"
                $FrameworkVersion += $_
            }
            $CSProj.Project.PropertyGroup.TargetFrameworkVersion | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Framework Version: $_"
                $FrameworkVersion += $_
                $legacy = $true # If TargetFrameworkVersion is used, it's a legacy (pre .net core) project file format
            }
            if ($FrameworkVersion.Count -ne 0) {
                [pscustomobject]@{
                    Project = $CurrentProject.Name
                    Repository = $CurrentRepository.Name
                    FrameworkVersion = $FrameworkVersion
                    DotNetCore = -not $Legacy
                    More = @{
                        OutputType = $CSProj.Project.PropertyGroup.OutputType | Where-Object {$_ -ne $null}
                        AssemblyName = $CSProj.Project.PropertyGroup.AssemblyName | Where-Object {$_ -ne $null}
                        Project = $CurrentProject
                        Repository = $CurrentRepository
                    }
                }
            } else {
                Write-Warning "No Target Framework found in project file $($_.FullName)"
                [pscustomobject]@{
                    Project = $CurrentProject.Name
                    Repository = $CurrentRepository.Name
                    FrameworkVersion = $null
                    DotNetCore = $null
                    More = @{
                        CSProj = (Split-Path -Leaf $_.FullName)
                        OutputType = $null
                        AssemblyName = $null
                        Project = $CurrentProject
                        Repository = $CurrentRepository
                    }
                }
            }
        }
        Start-Job -ScriptBlock {
            param($using:path)
            Remove-Item -Path $using:path -Recurse -Force -ErrorAction SilentlyContinue
        } -ArgumentList "$env:TEMP\$($_.Name).git"
    }
} | Tee-Object -Variable Results | Foreach-Object {
    if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
        $Row = $sheet.UsedRange.Rows.Count + 1
        $sheet.Cells.Item($Row,1).Value2 = $_.Project
        $sheet.Cells.Item($Row,2).Value2 = $_.Repository
        if ($_.DotNetCore){
            $sheet.Cells.Item($Row,3).Value2 = ".Net Core"
        }else{
            $sheet.Cells.Item($Row,3).Value2 = ".Net"
        }
        $sheet.Cells.Item($Row,4).Value2 = ($_.FrameworkVersion | Select-Object -Unique) -join ", "
        $sheet.Cells.Item($Row,5).Value2 = $_.More.CSProj
        $sheet.Cells.Item($Row,6).Value2 = $_.More.OutputType
        $sheet.Cells.Item($Row,7).Value2 = $_.More.AssemblyName
        $sheet.Cells.Item($Row,8).Value2 = $_.More.Repository.webUrl
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
Write-Warning "Data collection complete and available in `$Results, `$Repositories and `$Projects variables."
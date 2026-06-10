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
    $InformationPreference = "SilentlyContinue"
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
$Projects = Get-Project -Organization $Organization -Credential $Credential
Write-Information "Found $($Projects.Count) projects in organization $Organization"
$CurrentProjectIndex = 0
Write-Progress -Activity "Processing projects" -Status 'Starting' -PercentComplete 0

$Projects <#| Where-Object {$_.Name -match 'Portal'}#> <#| Select-Object -First 5#> | ForEach-Object {
    $CurrentProject = $_
    Write-Information "Project: $($CurrentProject.Name) ($($CurrentProject.Id))"

    Write-Progress -Activity "Processing projects" -Status $CurrentProject.Name -PercentComplete ($CurrentProjectIndex / $Projects.Count * 100)
    $CurrentProjectIndex++

    $Repositories = Get-ProjectRepository -Organization $Organization -ProjectId $CurrentProject.Id -Credential $Credential
    $CurrentRepositoryIndex = 0
    Write-Information "  Found $($Repositories.Count) repositories in project $($CurrentProject.Name)"
    $Repositories <#| Where-Object {$_.Name -match 'Portal$'}#> | ForEach-Object {
        $CurrentRepository = $_
        Write-Information "  Repository: $($CurrentRepository.Name) ($($CurrentRepository.Id)) - $($CurrentRepository.webUrl)"

        Write-Progress -Activity "Processing projects" -Status "$($CurrentProject.Name) ($($CurrentRepositoryIndex + 1) of $($Repositories.Count)) - $($CurrentRepository.Name)" -PercentComplete ($CurrentProjectIndex / $Projects.Count * 100)
        $CurrentRepositoryIndex++

        git clone $CurrentRepository.webUrl "$env:TEMP\$($CurrentRepository.Name).git" 2>&1 | Write-Verbose
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Failed to clone repository $($CurrentRepository.Name)"
            return
        }
        Get-ChildItem -Path "$env:TEMP\$($CurrentRepository.Name).git" -Filter "*.csproj" -Recurse | ForEach-Object {
            $CurrentCSProj = $_
            $CsProjRelativePath = $CurrentCSProj.FullName.Substring("$env:TEMP\$($CurrentRepository.Name).git".Length).TrimStart('\')
            Write-Information "    Found project file: $($CsProjRelativePath)"

            $CSProj = [xml](Get-Content $CurrentCSProj.FullName)

            $OutputObject = [pscustomobject]@{
                Project = $CurrentProject.Name
                Repository = $CurrentRepository.Name
                FrameworkVersion = @()
                DotNetCore = $null
                More = @{
                    CSProj = $CsProjRelativePath
                    OutputType = $null
                    AssemblyName = $null
                    Project = $CurrentProject
                    Repository = $CurrentRepository
                }
            }

            $CSProj.Project.PropertyGroup.TargetFrameworks | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Frameworks: $_"
                $OutputObject.FrameworkVersion += $_
                $OutputObject.DotNetCore = $true
            }
            $CSProj.Project.PropertyGroup.TargetFramework | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Framework: $_"
                $OutputObject.FrameworkVersion += $_
                $OutputObject.DotNetCore = $true
            }
            $CSProj.Project.PropertyGroup.TargetFrameworkVersion | Where-Object {$_ -ne $null} | ForEach-Object {
                Write-Information "      Target Framework Version: $_"
                $OutputObject.FrameworkVersion += $_
                # If TargetFrameworkVersion is used, it's a legacy (pre .net core) project file format
                $OutputObject.DotNetCore = $false
            }
            if ($OutputObject.FrameworkVersion.Count -ne 0) {
                $OutputObject.FrameworkVersion = $OutputObject.FrameworkVersion | Select-Object -Unique | Join-String -Separator ", "
                $OutputObject.More.OutputType = $CSProj.Project.PropertyGroup.OutputType | Where-Object {$_ -ne $null}
                $OutputObject.More.AssemblyName = $CSProj.Project.PropertyGroup.AssemblyName | Where-Object {$_ -ne $null}
            } else {
                Write-Warning "No Target Framework found in project file $($CurrentCSProj.FullName)"
            }

            $OutputObject | Write-Output
        }
        Start-Job -ScriptBlock {
            param($path)
            Remove-Item -Path $path -Recurse -Force -ErrorAction SilentlyContinue
        } -ArgumentList "$env:TEMP\$($CurrentRepository.Name).git" | Out-Null
    }
} | Tee-Object -Variable Results | Foreach-Object {
    if ($WriteXls.IsPresent -and $WriteXls -eq $true) {
        $Row = $sheet.UsedRange.Rows.Count + 1
        $sheet.Cells.Item($Row,1).Value2 = "$($_.Project)"
        $sheet.Cells.Item($Row,2).Value2 = "$($_.Repository)"
        if ($_.DotNetCore){
            $sheet.Cells.Item($Row,3).Value2 = ".Net Core"
        }else{
            $sheet.Cells.Item($Row,3).Value2 = ".Net"
        }
        $sheet.Cells.Item($Row,4).Value2 = "$($_.FrameworkVersion)"
        if ($_.More -ne $null -and $_.More.CSProj -ne $null){
            $sheet.Cells.Item($Row,5).Value2 = "$($_.More.CSProj)"
        }
        if ($_.More -ne $null -and $_.More.OutputType -ne $null){
            $sheet.Cells.Item($Row,6).Value2 = "$($_.More.OutputType)"
        }
        if ($_.More -ne $null -and $_.More.AssemblyName -ne $null){
            $sheet.Cells.Item($Row,7).Value2 = "$($_.More.AssemblyName)"
        }
        if ($_.More -ne $null -and $_.More.Repository -ne $null -and $_.More.Repository.webUrl -ne $null){
            $sheet.Cells.Item($Row,8).Value2 = "$($_.More.Repository.webUrl)"
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
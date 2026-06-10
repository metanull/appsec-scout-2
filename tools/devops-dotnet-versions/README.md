(# Collect-DotNetVersions.ps1 — dotnet-version

A small, efficient wrapper to scan an Azure DevOps organization for .NET project framework versions and related metadata.

Usage
- Basic: `./Collect-DotNetVersions.ps1 -Credential (Get-Credential)` — scans the default organization (`EESC-CoR`).
- Write Excel: `./Collect-DotNetVersions.ps1 -Credential (Get-Credential) -WriteXls` — outputs results to an Excel workbook (requires Excel).
- Specify org: `./Collect-DotNetVersions.ps1 -Credential (Get-Credential) -Organization "MyOrg"`

Dependencies
- Requires the `AzureDevOpsIngest` PowerShell module — source: https://dev.azure.com/EESC-CoR/CyberSecurityTools/_git/PowershellModules
- `git` available on `PATH` (the script clones repositories to `$env:TEMP`).
- Excel (COM) required when using `-WriteXls`.

Notes
- The script has a header `#Requires -Module AzureDevOpsIngest` and needs credentials with access to the target Azure DevOps organization.
- Repositories are cloned into the temporary directory and removed after processing.

Example
```powershell
./Collect-DotNetVersions.ps1 -Credential (Get-Credential)
./Collect-DotNetVersions.ps1 -Credential (Get-Credential) -WriteXls
./Collect-DotNetVersions.ps1 -Credential (Get-Credential) -Organization "EESC-CoR"
```

For more information about the module this script depends on, see the repository above.)

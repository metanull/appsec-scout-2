<#
	.SYNOPSIS
	Backup a git repository into a ZIP archive

	.PARAMETER Path
	Directory where to store the archive

	.PARAMETER ProjectKey
	DevOps's Project Identifier

	.PARAMETER RepositoryKey
	DevOps's Repository Identifier

	.PARAMETER OrganizationKey
	DevOps's OrganizationKey Identifier

	.EXAMPLE
		@('my-repo-1', 'my-repo-2') | New-GitBackup -Path $env:TEMP -ProjectKey MyProject -OrganizationKey MyOrg
#>
[CmdletBinding()]
param (
	[Parameter(Mandatory, Position=0)]
	[ValidateScript({Test-Path -PathType Container -Path $_})]
	[String]
	$Path,
	[Parameter(Mandatory, Position=1)]
	[String]
	$ProjectKey,
	[Parameter(Mandatory, Position=2, ValueFromPipeline)]
	[String]
	$RepositoryKey,
	[Parameter(Position=3)]
	[String]
	$OrganizationKey = 'EESC-CoR'
)
Begin {
	$OldErrorActionPreference = $ErrorActionPreference
	$ErrorActionPreference = 'Stop'

	Function Get-ArchiveDrive {
		[CmdletBinding()]
		param (
			[Parameter(Mandatory, Position=0)]
			[String]
			$Path
		)
		$ArchiveDrive = 'Archive'
		if (-not (Test-Path "$($ArchiveDrive):")) {
			Write-Verbose "New PSDrive ($Path)"
			$PSDrive = New-PSDrive -Name $ArchiveDrive -PSProvider FileSystem -Root $Path -Scope Global
		} else {
			$PSDrive = Get-PSDrive $ArchiveDrive
		}
		Write-Output ($PSDrive)
	}

	Function New-AzDOGitUrl {
		[CmdletBinding()]
		param (
			[Parameter(Mandatory, Position=0)]
			[String]
			$ProjectKey,
			[Parameter(Mandatory, Position=1)]
			[String]
			$RepositoryKey,
			[Parameter(Position=2)]
			[String]
			$OrganizationKey = 'EESC-CoR'
		)
		Write-Output "https://$($OrganizationKey)@dev.azure.com/$($OrganizationKey)/$($ProjectKey)/_git/$($RepositoryKey)"
	}

	Function New-AzDOGitClone {
		[CmdletBinding()]
		param (
			[Parameter(Mandatory, Position=0)]
			[ValidateScript({Test-Path -PathType Container -Path $_})]
			[String]
			$Path,
			[Parameter(Mandatory, Position=1)]
			[String]
			$ProjectKey,
			[Parameter(Mandatory, Position=2)]
			[String]
			$RepositoryKey,
			[Parameter(Position=3)]
			[String]
			$OrganizationKey = 'EESC-CoR'
		)
		$RepoUrl = New-AzDOGitUrl -RepositoryKey $RepositoryKey -ProjectKey $ProjectKey -OrganizationKey $OrganizationKey
		Write-Verbose "Cloning repository ($RepoUrl)"

		Push-Location -Path $Path | Out-Null
		try {
			$ErrorActionPreference = 'Continue'
			$GitOutput = git clone --mirror $RepoUrl $RepositoryKey 2>&1
			$ErrorActionPreference = 'Stop'
			Write-Debug "Return: $LASTEXITCODE"
			$GitOutput |ForEach-Object { Write-Debug "Type: $($_.GetType()); Value: $($_)" }
			if ($LASTEXITCODE -ne 0) {
				$GitError = $GitOutput | Where-Object { $_ -is [System.Management.Automation.ErrorRecord] } | Foreach-Object { $_.ToString() }
				throw "Git: $GitError"
			}

			$GitPath = (Join-Path -Path $Path -ChildPath $RepositoryKey | Get-Item | Select-Object -ExpandProperty FullName)
			Write-Verbose "Cloned to ($GitPath)"
			Write-Output ($GitPath)
		} finally {
			Pop-Location | Out-Null
		}
	}

	Function New-ZipAndHash {
		[CmdletBinding()]
		param (
			[Parameter(Mandatory, Position=0)]
			[ValidateScript({Test-Path -Path $_})]
			[String]
			$Path,
			[Switch]
			$Force
		)

		$ZipPath = "$($Path).zip"
		$HashPath = "$($ZipPath).hash"
		if ( -not ($Force.IsPresent -and $Force)) {
			if ((Test-Path -Path $ZipPath)) {
				throw "$ZipPath exists"
			}
			if ((Test-Path -Path $HashPath)) {
				throw "$HashPath exists"
			}
		}
		Write-Verbose "Creating Zip archive ($ZipPath)"
		Compress-Archive -Path $Path -DestinationPath $ZipPath -Force

		Write-Verbose "Creating SHA256 checksum ($HashPath)"
		Get-FileHash $ZipPath -Algorithm SHA256 | Select-Object -ExpandProperty Hash | Out-File -Encoding ascii -FilePath $HashPath -Force

		Write-Output (Resolve-Path $ZipPath | Select-Object -ExpandProperty Path)
	}
}

Process {
	$ArchiveDrive = Get-ArchiveDrive -Path $Path

	$ArchivePath = "$($ArchiveDrive.Name):\$($ProjectKey)"
	if( -not (Test-Path -Path $ArchivePath -PathType Container)) {
		$ArchiveItem = New-Item -ItemType Directory $ArchivePath
	} else {
		$ArchiveItem = Get-Item $ArchivePath
	}
	$ArchivePath = $ArchiveItem.FullName
	try {
		$GitPath = New-AzDOGitClone -Path $ArchivePath -ProjectKey $ProjectKey -RepositoryKey $RepositoryKey -OrganizationKey $OrganizationKey
	} catch {
		Write-Warning "Couldn't backup the repository: $($_)"
		return
	}
	try {
		$ZipPath = New-ZipAndHash -Path $GitPath -Force
	} finally {
		Remove-Item -Force -Recurse $GitPath | Out-Null
	}
	Write-Output ($ZipPath | Get-Item | Select-Object -ExpandProperty FullName)
}
End {
	$ErrorActionPreference = $OldErrorActionPreference
}

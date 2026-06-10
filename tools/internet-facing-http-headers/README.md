(# Collect-HttpHeaders.ps1 - Collects unusual headers from a collection of URLs.

Usage

```
# Using a file for the list of urls
.\Collect-HttpHeaders.ps1 -UrlListDataFile UrlList.psd1.example

# Passing an url
.\Collect-HttpHeaders.ps1 -Url 'https://aka.ms'

# PAssing multiple url via piping
@('https://google.com','aka.ms','gmail.com') | . .\Collect-HttpHeaders.ps1

```
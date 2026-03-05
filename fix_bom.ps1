# Remove BOM from .env file
$filePath = "C:\Users\rimaj\OneDrive\Bureau\projet\.env"
$content = [System.IO.File]::ReadAllText($filePath, [System.Text.Encoding]::UTF8)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($filePath, $content, $utf8NoBom)
Write-Host "BOM removed from .env file"


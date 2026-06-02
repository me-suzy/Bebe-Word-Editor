# Sample extractor/diagnostic for 2-3 dictionary files (no transformations)
# Outputs multiple decoding attempts + hex preview so we can understand structure
# Author: Assistant AI - 28 Aug 2025

Write-Host "=== DIAGNOSTIC EXTRAGERE MOSTRE ===" -ForegroundColor Green

$baseDir = Get-Location
$outDir = Join-Path $baseDir "extract_samples"
if (!(Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }

# Choose 3 representative files if present
$files = @(
  "Dictionare\ca.dic",
  "Dictionare\ci.dic",
  "Dictionare\do.dic"
)

# Encodings to test
$encs = @(
  @{ Name = "Default"; Enc = [System.Text.Encoding]::Default },
  @{ Name = "UTF8"; Enc = [System.Text.Encoding]::UTF8 },
  @{ Name = "Unicode"; Enc = [System.Text.Encoding]::Unicode },
  @{ Name = "BigEndianUnicode"; Enc = [System.Text.Encoding]::BigEndianUnicode },
  @{ Name = "Windows-1250"; Enc = [System.Text.Encoding]::GetEncoding(1250) },
  @{ Name = "Windows-1252"; Enc = [System.Text.Encoding]::GetEncoding(1252) },
  @{ Name = "ISO-8859-2"; Enc = [System.Text.Encoding]::GetEncoding(28592) }
)

foreach ($rel in $files) {
  $path = Join-Path $baseDir $rel
  if (!(Test-Path $path)) {
    Write-Host "SKIP (nu exista): $rel" -ForegroundColor Yellow
    continue
  }
  Write-Host "Procesez mostra: $rel" -ForegroundColor Cyan

  $name = [IO.Path]::GetFileNameWithoutExtension($path)

  # 1) Hex preview first 2048 bytes
  try {
    $bytes = [IO.File]::ReadAllBytes($path)
    $previewLen = [Math]::Min(2048, $bytes.Length)
    $hex = -join ($bytes[0..($previewLen-1)] | ForEach-Object { $_.ToString("X2") + " " })
    $hexFile = Join-Path $outDir ("${name}.hex.txt")
    "File: $rel`r`nBytes: $($bytes.Length)`r`nPreview(${previewLen}):`r`n$hex" | Out-File -FilePath $hexFile -Encoding UTF8
  } catch { Write-Host "  EROARE hex: $($_.Exception.Message)" -ForegroundColor Red }

  # 2) Save raw bytes as a .bin copy (for reference)
  try {
    $rawCopy = Join-Path $outDir ("${name}.raw.bin")
    [IO.File]::WriteAllBytes($rawCopy, $bytes)
  } catch { }

  # 3) Try multiple decodings and save full text
  foreach ($e in $encs) {
    try {
      $txt = $e.Enc.GetString($bytes)
      $out = Join-Path $outDir ("${name}.${($e.Name)}.txt")
      $txt | Out-File -FilePath $out -Encoding UTF8
    } catch {
      Write-Host "  EROARE decodare ${($e.Name)}: $($_.Exception.Message)" -ForegroundColor Yellow
    }
  }

  # 4) Also dump first 2000 chars of each decoding into a combined preview file
  $previewAll = New-Object System.Text.StringBuilder
  $null = $previewAll.AppendLine("File: $rel")
  foreach ($e in $encs) {
    try {
      $txt = $e.Enc.GetString($bytes)
      $slice = if ($txt.Length -gt 2000) { $txt.Substring(0,2000) } else { $txt }
      $null = $previewAll.AppendLine("--- ${($e.Name)} preview ---")
      $null = $previewAll.AppendLine($slice)
    } catch { }
  }
  $prevFile = Join-Path $outDir ("${name}.previews.txt")
  $previewAll.ToString() | Out-File -FilePath $prevFile -Encoding UTF8
}

Write-Host "Mostre salvate in: $outDir" -ForegroundColor Green


param(
  [string]$DocxPath = 'E:\Carte\BB\++++carti scrise de bebe\CELE 63 de calitati ale liderului\Final Corectat V1.docx'
)

$ErrorActionPreference = 'Stop'

$word = $null
$doc = $null

try {
  $word = New-Object -ComObject Word.Application
  $word.Visible = $false
  $word.DisplayAlerts = 0
  $doc = $word.Documents.Open($DocxPath, $false, $true)

  Write-Output "WORD_OPEN_OK"
  Write-Output "DOCX $DocxPath"
  Write-Output ("WORD_PAGES {0}" -f $doc.ComputeStatistics(2))
  Write-Output ("WORD_WORDS {0}" -f $doc.ComputeStatistics(0))
}
finally {
  if ($doc) {
    $doc.Close(0) | Out-Null
  }
  if ($word) {
    $word.Quit() | Out-Null
  }
}

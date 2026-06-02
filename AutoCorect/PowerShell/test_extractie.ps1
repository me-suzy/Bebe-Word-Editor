# Script de test pentru extragerea bazei de date AutoCorect
# Acest script testează procesul de extragere cu un număr limitat de fișiere

Write-Host "=== TEST EXTRAGERE BAZA DE DATE AUTOCORECT ===" -ForegroundColor Green
Write-Host "Testează procesul de extragere..." -ForegroundColor Yellow

# 1. Creează fișierul de test
$outputFile = "test_baza_date.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul de test existent a fost șters." -ForegroundColor Red
}

# 3. Găsește primele 5 fișiere de dicționar pentru test
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName | Select-Object -First 5

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere pentru test." -ForegroundColor Cyan

# 4. Extrage conținutul fiecărui fișier (doar primele 1000 de caractere pentru test)
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Adaugă doar primele 1000 de caractere pentru test
        $content = Get-Content $file.FullName -Raw
        if ($content.Length -gt 1000) {
            $content = $content.Substring(0, 1000) + "... [TRUNCAT PENTRU TEST]"
        }
        $content | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Adaugă separator între fișiere
        "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        $totalSize += $file.Length
        $processedFiles++
        
        Write-Host "Test procesat: $($file.Name) - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
        
    } catch {
        Write-Host "Eroare la testarea $($file.Name): $($_.Exception.Message)" -ForegroundColor Red
    }
}

# 5. Afișează rezultatele testului
Write-Host "`n=== REZULTATE TEST ===" -ForegroundColor Green
Write-Host "Fișiere testate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totală originală: $([math]::Round($totalSize/1KB,2)) KB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fișier de test creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fișier test: $([math]::Round($finalSize/1KB,2)) KB" -ForegroundColor Green
    Write-Host "Testul a fost finalizat cu succes!" -ForegroundColor Green
    
    # Afișează primele linii din fișierul de test
    Write-Host "`n=== PREVIEW CONȚINUT TEST ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 10 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul de test nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nTestul este complet! Acum poți rula scriptul principal." -ForegroundColor Cyan
Write-Host "Apasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")

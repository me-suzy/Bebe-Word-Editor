# Script CORECT pentru extragerea cuvintelor reale din baza de date AutoCorect
# Autor: Assistant AI
# Data: 28 august 2025
# Versiune: CORECTATA - Cuvinte separate prin virgula, nu litere

Write-Host "=== EXTRAGERE CUVINTE REALE CORECTA ===" -ForegroundColor Green
Write-Host "Extrag cuvintele reale romanesti si le separ prin virgula..." -ForegroundColor Yellow

# 1. Creeaza fisierul de baza de date
$outputFile = "cuvinte_reale_virgula_corect.txt"

# 2. Sterge fisierul daca exista
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fisierul existent a fost sters." -ForegroundColor Red
}

# 3. Functie pentru extragerea cuvintelor reale (CORECTA)
function Extract-Real-Words {
    param([string]$text)
    
    if (-not $text) { return "" }
    
    # 1. Inlocuieste caracterele speciale cu spatii
    $result = $text -replace '[º°²³¼½¾]', ' '
    
    # 2. Inlocuieste caracterele non-alfabetice cu spatii (pastrand doar literele)
    $result = $result -replace '[^a-zA-ZaaiiAASSTT\s]', ' '
    
    # 3. Curata spatiile multiple
    $result = $result -replace '\s+', ' '
    
    # 4. Imparte textul in cuvinte
    $words = $result -split '\s+' | Where-Object { $_.Length -gt 0 }
    
    # 5. Filtreaza cuvintele (elimina cuvintele prea scurte sau care contin doar o litera)
    $filteredWords = $words | Where-Object { $_.Length -gt 1 }
    
    # 6. Uneste cuvintele cu virgula
    $result = $filteredWords -join ','
    
    return $result.Trim()
}

# 4. Gaseste toate fisierele de dictionar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au gasit $($dictionaryFiles.Count) fisiere de dictionar." -ForegroundColor Cyan

# 5. Extrage continutul fiecarui fisier cu separarea cuvintelor reale
$totalSize = 0
$processedFiles = 0
$allWords = @()

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Citeste continutul cu encoding-ul corect
        $content = Get-Content $file.FullName -Raw -Encoding Default
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        if ($content) {
            # Proceseaza continutul pentru extragerea cuvintelor reale
            $processedContent = Extract-Real-Words $content
            
            if ($processedContent) {
                # Adauga cuvintele la lista totala
                $words = $processedContent -split ','
                $allWords += $words
            }
            
            Write-Host "  Procesat cu succes - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
        } else {
            Write-Host "  Continut gol sau null" -ForegroundColor Yellow
        }
        
        $totalSize += $file.Length
        $processedFiles++
        
    } catch {
        Write-Host "  Eroare: $($_.Exception.Message)" -ForegroundColor Red
    }
}

# 6. Curata si unifica toate cuvintele
Write-Host "`nCuratez si unific cuvintele..." -ForegroundColor Yellow

# Elimina duplicatele si sorteaza
$uniqueWords = $allWords | Sort-Object -Unique | Where-Object { $_.Length -gt 1 }

# Uneste toate cuvintele cu virgula
$finalContent = $uniqueWords -join ','

# 7. Salveaza rezultatul final
$finalContent | Out-File -FilePath $outputFile -Encoding UTF8

# 8. Afiseaza rezultatele finale
Write-Host "`n=== REZULTATE FINALE ===" -ForegroundColor Green
Write-Host "Fisiere procesate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totala originala: $([math]::Round($totalSize/1MB,2)) MB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fisier final creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fisier final: $([math]::Round($finalSize/1MB,2)) MB" -ForegroundColor Green
    Write-Host "Cuvinte unice gasite: $($uniqueWords.Count)" -ForegroundColor Green
    Write-Host "Extragerea cuvintelor reale a fost finalizata cu succes!" -ForegroundColor Green
    
    # Afiseaza primele linii pentru verificare
    Write-Host "`n=== PREVIEW CONTINUT ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 5 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
    # Afiseaza cateva exemple de cuvinte
    Write-Host "`n=== EXEMPLE CUVINTE ===" -ForegroundColor Yellow
    $uniqueWords | Select-Object -First 20 | ForEach-Object { Write-Host $_ -ForegroundColor Cyan }
    
} else {
    Write-Host "EROARE: Fisierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nExtragerea cuvintelor reale este completa! Verifica fisierul $outputFile" -ForegroundColor Green

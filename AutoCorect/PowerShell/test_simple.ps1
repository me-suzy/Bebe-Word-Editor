# Test simplu pentru separarea cuvintelor
Write-Host "=== TEST SEPARARE CUVINTE ===" -ForegroundColor Green

# Text de test
$testText = "BRADIN BRADIC BRADICII"

Write-Host "Text original: $testText" -ForegroundColor Yellow

# Metoda GRESITA (ceea ce fac scripturile actuale)
Write-Host "`nMetoda GRESITA (virgule intre litere):" -ForegroundColor Red
$wrong = $testText -replace '([a-z])([A-Z])', '$1,$2'
Write-Host $wrong -ForegroundColor Red

# Metoda CORECTA (ceea ce trebuie sa faca)
Write-Host "`nMetoda CORECTA (virgule intre cuvinte):" -ForegroundColor Green
$correct = $testText -split '\s+' -join ','
Write-Host $correct -ForegroundColor Green

Write-Host "`nVede diferenta? Prima metoda adauga virgule intre litere, a doua intre cuvinte!" -ForegroundColor Cyan


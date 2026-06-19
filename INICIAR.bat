@echo off
title Bussola do Trafego - Servidor
color 0A
echo.
echo  =======================================
echo    BUSSOLA DO TRAFEGO
echo    Iniciando servidor...
echo  =======================================
echo.

:: Abre o navegador depois de 2 segundos
start "" "http://localhost:8000"

:: Inicia o servidor PHP
echo  Servidor rodando em: http://localhost:8000
echo  Para parar, feche esta janela.
echo.
E:\php\php.exe -S localhost:8000 -t "%~dp0"
pause

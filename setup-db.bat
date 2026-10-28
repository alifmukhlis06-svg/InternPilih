@echo off
REM InternPilih Database Setup via HeidiSQL
REM This script will open HeidiSQL with the database schema

cd /d "%~dp0"

echo.
echo ============================================
echo  InternPilih Database Setup
echo ============================================
echo.

echo Opening HeidiSQL...
echo.
echo Instructions:
echo 1. In HeidiSQL, connect to your MySQL server
echo 2. Right-click on your connection and select "New" > "Database"
echo 3. Name it: internpilih
echo 4. Click "OK" to create
echo 5. Go to File menu > Open SQL file
echo 6. Select: database\schema.sql
echo 7. Press Ctrl+E to execute all queries
echo.

start "" "C:\laragon\bin\heidisql\heidisql.exe"

pause

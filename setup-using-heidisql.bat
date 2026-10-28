@echo off
REM Database Setup Script using HeidiSQL
REM This script creates the database and tables using HeidiSQL

set HEIDISQL_PATH=C:\laragon\bin\heidisql\heidisql.exe
set SCHEMA_FILE=%~dp0database\schema.sql

echo Starting HeidiSQL Database Setup...
echo.
echo A HeidiSQL window will open. Follow these steps:
echo 1. Connect to your MySQL server
echo 2. In the "Query" tab, paste the contents of schema.sql
echo 3. Execute the SQL (Ctrl+E or click Execute)
echo.
echo Opening schema.sql with HeidiSQL...

REM Open HeidiSQL with the schema file
start "" "%HEIDISQL_PATH%" --import="%SCHEMA_FILE%"

echo.
echo HeidiSQL should now be opening. The schema.sql file will be ready to execute.
echo.
pause

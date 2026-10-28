@echo off
REM Database Setup Script for InternPilih
REM This script creates the database and tables

set MYSQL_PATH=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe
set SCHEMA_FILE=database\schema.sql
set DB_USER=root
set DB_HOST=localhost

echo Creating InternPilih Database...
echo.

REM First create the database
"%MYSQL_PATH%" -h %DB_HOST% -u %DB_USER% -e "CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if %ERRORLEVEL% NEQ 0 (
    echo Error creating database.
    echo.
    echo If you get "Access denied", please:
    echo 1. Open HeidiSQL from Laragon
    echo 2. Connect with root user (no password)
    echo 3. Create database manually or provide password
    pause
    exit /b 1
)

echo Database created successfully!
echo.
echo Creating tables...

REM Create all tables from schema
"%MYSQL_PATH%" -h %DB_HOST% -u %DB_USER% internpilih < %SCHEMA_FILE%

if %ERRORLEVEL% NEQ 0 (
    echo Error creating tables.
    pause
    exit /b 1
)

echo.
echo ✓ All tables created successfully!
echo.
echo Tables created:
echo   - users
echo   - companies
echo   - job_offers
echo   - applications
echo   - notifications
echo   - messages
echo   - student_profiles
echo   - verifications
echo.
echo Database setup complete!
pause

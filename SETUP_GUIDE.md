# InternPilih Database Setup - Manual Guide

## 🔧 Setup Options

Since you're using Laragon, here are the easiest ways to set up your database:

---

## **Option 1: Using HeidiSQL (Easiest)**

HeidiSQL is already installed with Laragon. This is the recommended method.

### Steps:
1. **Open HeidiSQL** from Laragon (or find it at `C:\laragon\bin\heidisql\heidisql.exe`)
2. **Click "New"** to create a new connection
3. **Enter your MySQL credentials:**
   - Host: `localhost`
   - Username: `root`
   - Password: `alifmukhlis` (or your actual password)
   - Port: `3306`
4. **Click "Open"** to connect
5. **Right-click on your connection** and select **"Create new"** → **"Database"**
6. **Name it:** `internpilih` (charset: `utf8mb4`)
7. **Open SQL file:** File menu → "Open SQL file" → select `database/schema.sql`
8. **Execute all queries** by pressing `Ctrl+E` or clicking the Execute button

✅ Done! All tables will be created.

---

## **Option 2: Using MySQL Command Line**

### Step 1: Open Command Prompt/PowerShell

### Step 2: Run this command to create database:
```cmd
C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe -h localhost -u root -p
```

When prompted, enter your password: `alifmukhlis`

### Step 3: In MySQL terminal, run:
```sql
CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE internpilih;
-- Then paste the contents of database/schema.sql
```

---

## **Option 3: Using Batch File (Windows)**

Simply run: **`setup-using-heidisql.bat`**

This will open HeidiSQL with the schema file ready to execute.

---

## **Option 4: Manual SQL Execution**

If your credentials are different:

1. **Edit `config/database.php`** with your actual credentials:
```php
'username' => 'your_username',
'password' => 'your_password',
'host' => 'localhost',
```

2. **Visit in browser:** `http://localhost/InternPilih/setup-database.html`

3. **Enter your MySQL credentials** and click "Create Database"

---

## **Database Tables Created**

- `users` - All users (students, employers, admins)
- `companies` - Company information
- `job_offers` - Job/internship postings
- `applications` - Student applications
- `notifications` - User notifications
- `messages` - Direct messaging
- `student_profiles` - Extended student info
- `verifications` - Company verification

---

## **🔍 Verify Setup**

After setup, visit: `http://localhost/InternPilih/verify-database.php`

This will show all created tables and verify everything is working.

---

## **❓ Troubleshooting**

**Q: "Access denied for user 'root'"**
- A: Check your MySQL password in Laragon settings or use HeidiSQL to test connection first

**Q: "Can't connect to localhost"**
- A: Make sure MySQL is running. Check Laragon's status or start MySQL from Laragon control panel

**Q: "Database already exists"**
- A: That's fine! The schema uses "IF NOT EXISTS" so it's safe to run multiple times

---

## **📝 After Setup**

Edit `config/database.php` with your correct credentials:
```php
<?php
return [
    'host' => 'localhost',
    'database' => 'internpilih',
    'username' => 'root',
    'password' => 'your_actual_password',
    'charset' => 'utf8mb4',
];
?>
```

Then use in your PHP files:
```php
require_once 'config/DatabaseConnection.php';
$db = new DatabaseConnection();
$conn = $db->getConnection();
```

---

**Need help?** Check the DATABASE_README.md file for more details!

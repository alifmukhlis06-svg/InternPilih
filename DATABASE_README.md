# Database Setup Guide - InternPilih

> Current schema: see [database/schema.sql](database/schema.sql) and
> [database/ROLE_TABLES.md](database/ROLE_TABLES.md). The legacy table list
> further down this guide describes an earlier prototype.

## Quick Start

### 1. Create Database & Tables
Run this setup script from your browser or PHP CLI:

```bash
php database/setup.php
```

This will:
- Create the `internpilih` database
- Create all 8 tables automatically

### 2. Database Configuration
Edit `config/database.php` with your database credentials:
```php
'host' => 'localhost',
'database' => 'internpilih',
'username' => 'root',
'password' => '',
```

### 3. Use in Your Code
```php
require_once 'config/DatabaseConnection.php';

$db = new DatabaseConnection();
$conn = $db->getConnection();

// Run queries
$result = $conn->query("SELECT * FROM users");
```

---

## Database Tables Overview

### 1. **users** - All system users
- id, name, email, password, role (student/employer/admin)
- phone, profile_picture, is_active

### 2. **companies** - Employer company details
- id, user_id, company_name, email, industry
- company_size, address, city, phone, website, logo_url, is_verified

### 3. **job_offers** - Internship/job postings
- id, company_id, title, description, requirements
- position_count, salary_range, location, job_type
- status (active/closed/filled), deadline

### 4. **applications** - Student applications to jobs
- id, student_id, job_offer_id, status (pending/accepted/rejected/withdrawn)
- cv_url, cover_letter, applied_at

### 5. **notifications** - User notifications
- id, user_id, title, message, type
- is_read, related_id, created_at

### 6. **messages** - Direct messaging
- id, sender_id, receiver_id, subject, message
- is_read, created_at

### 7. **student_profiles** - Extended student info
- id, user_id, major, university, gpa
- skills, bio, cv_url, portfolio_url, expected_graduation_date

### 8. **verifications** - Company verification tracking
- id, company_id, status (pending/approved/rejected)
- documents_url, notes, verified_by

---

## Connection Methods

### Prepared Statements (Recommended - Prevents SQL Injection)
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
$stmt->bind_param("ss", $email, $role);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
```

### Direct Query
```php
$result = $conn->query("SELECT * FROM users");
while ($row = $result->fetch_assoc()) {
    // Process row
}
```

### Execute (INSERT, UPDATE, DELETE)
```php
$conn->query("INSERT INTO users (name, email, password, role) VALUES ('John', 'john@example.com', 'hash', 'student')");
```

---

## Features
✓ UTF-8 Character Set  
✓ Timestamps (created_at, updated_at)  
✓ Foreign Key Constraints  
✓ Indexed columns for performance  
✓ Unique constraints where needed  
✓ Enum fields for role/status

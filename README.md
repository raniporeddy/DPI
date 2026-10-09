# Digital Investor Onboarding System using SSO, eKYC, eSign and Paperless Office Modules

A complete full-stack web application developed as an academic final-year B.Tech project prototype. The system provides a seamless paperless digital onboarding experience for investors, featuring Single Sign-On (SSO), Demo eKYC verification, interactive HTML5 Canvas electronic signature (eSign), digital document uploads, application status tracking, and an Admin verification portal.

---

## 🛠️ Technology Stack
- **Backend:** PHP 8+ (Vanilla PHP with PDO Prepared Statements)
- **Database:** MySQL / MariaDB
- **Frontend:** HTML5, CSS3, JavaScript (ES6), Bootstrap 5, FontAwesome 6
- **Server Environment:** XAMPP (Apache + MySQL)
- **Design:** Modern financial-themed responsive UI with stepper navigation and status badges.

---

## 🔑 Academic Demo Account Credentials

| User Role | Email Address | Password | Description |
|---|---|---|---|
| **Admin** | `admin@digitalinvestor.com` | `Admin@123` | Full admin privileges, eKYC verification & application approvals |
| **Approved Investor** | `investor@example.com` | `Investor@123` | Pre-configured approved investor with completed dossier |
| **Pending Investor** | `john.doe@example.com` | `Investor@123` | Pre-configured investor with pending eKYC review |

*Note: You can also click "Continue with SSO" to test instant 1-click single sign-on authentication.*

---

## 📋 Step-by-Step XAMPP Setup Guide

### Step 1: Install XAMPP
Download and install **XAMPP for Windows** (with PHP 8.0 or higher) from [Apache Friends](https://www.apachefriends.org/).

### Step 2: Start Apache and MySQL
1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

### Step 3: Create Database in phpMyAdmin
1. Open your web browser and navigate to `http://localhost/phpmyadmin/`.
2. Click on **Databases** tab.
3. Enter database name: `digital_investor`.
4. Select collation `utf8mb4_unicode_ci` and click **Create**.

### Step 4: Import database.sql
1. Select the newly created `digital_investor` database in phpMyAdmin.
2. Click on the **Import** tab at the top.
3. Click **Choose File** and select `database.sql` located inside the project folder.
4. Scroll down and click **Import** / **Go**.

### Step 5: Copy Project Files into XAMPP htdocs
Copy the complete `digital-investor` folder directly into your XAMPP installation directory:
```
C:\xampp\htdocs\digital-investor
```

### Step 6: Verify Database Connection Configuration
Open `config/database.php` and verify the settings match your local XAMPP configuration:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'digital_investor');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Step 7: Open the Application
Open your browser and visit:
```
http://localhost/digital-investor/
```

### Step 8: Test Investor Onboarding Workflow
1. Click **Register** or **Continue with SSO** to create an investor account.
2. Fill out the **Investor Profile** details (Name, DOB, Mobile, Address, ID Number).
3. Access **eKYC Verification** module and upload a demo identity proof (PAN/Aadhaar/Passport).
4. Access **Document Management** to upload supporting address proof files.
5. Access **eSign Module** and draw your digital signature on the interactive HTML5 canvas board using your mouse or touch screen. Click **Save & Apply eSign**.
6. On the Investor Dashboard, click **Submit Application**.

### Step 9: Test Admin Approval Workflow
1. Navigate to `http://localhost/digital-investor/admin/login.php` or select Admin Login.
2. Sign in with `admin@digitalinvestor.com` / `Admin@123`.
3. View summary metrics counters on the **Admin Dashboard**.
4. Go to **Approvals Queue** or **All Investors** directory.
5. Click **Inspect Dossier** for any submitted investor.
6. Verify/Reject the submitted eKYC document, enter admin remarks, and click **Approve Application**.
7. Log back into the investor account to view the updated status and print the **Paperless Summary Dossier**.

---

## 📂 Project Structure

```
digital-investor/
├── index.php                 # Public Landing Page
├── login.php                 # Investor & Admin Login
├── register.php              # Investor Registration
├── sso_login.php             # Simulated SSO Authentication Portal
├── logout.php                # Session Logout Handler
├── dashboard.php             # Investor Central Dashboard & Stepper Progress
├── paperless.php             # Paperless Office Summary Dossier Page
├── database.sql              # MySQL Database Schema & Seed Data
├── README.md                 # Setup & Documentation Guide
│
├── config/
│   └── database.php          # PDO Database Connection
│
├── includes/
│   ├── header.php            # Shared Navigation Bar Header
│   ├── footer.php            # Shared Footer & Scripts
│   ├── auth_check.php        # Session & Role Access Control
│   ├── csrf.php              # CSRF Token Protection Helpers
│   ├── functions.php         # Utility Helpers & Upload Handler
│   └── sso_helper.php        # SSO Service Prototype Handler
│
├── investor/
│   ├── profile.php           # Module 1: Investor Profile Form
│   ├── ekyc.php              # Module 2: Academic Demo eKYC Module
│   ├── documents.php          # Module 3: Document Management Repository
│   ├── esign.php             # Module 4: HTML5 Canvas eSign Module
│   └── submit_application.php# Final Application Submission Handler
│
├── admin/
│   ├── login.php             # Admin Login Portal
│   ├── dashboard.php         # Admin Metrics Dashboard
│   ├── investors.php         # Investor Directory & Search/Filter
│   ├── view_investor.php     # Investor Dossier Inspection & Verification
│   ├── approvals.php         # Pending Approvals Queue Handler
│   └── paperless.php         # Admin Central Paperless Repository
│
├── assets/
│   ├── css/
│   │   └── style.css         # Modern Financial UI Stylesheet
│   └── js/
│       ├── main.js           # Form & File Validation Helpers
│       └── esign.js          # Interactive HTML5 Signature Canvas Script
│
└── uploads/                  # Secure Media Uploads Directory
    ├── documents/            # Uploaded Proof Documents
    ├── kyc/                  # Uploaded eKYC Documents
    └── signatures/           # Base64 PNG Generated eSignatures
```

---

## 🔒 Security Implementation Features
- **Password Hashing:** Passwords encrypted using PHP `password_hash()` (Bcrypt).
- **SQL Injection Prevention:** 100% prepared SQL statements via PHP Data Objects (PDO).
- **Session Security:** `session_regenerate_id()` on login and strict role-based page protection via `requireRole()`.
- **CSRF Protection:** Form submission tokens validated via `verifyCSRFPost()`.
- **File Upload Security:** MIME-type check, extension whitelisting (JPG, PNG, PDF), and 5MB size limit validation.
- **Input Sanitization:** XSS output escaping using `htmlspecialchars()`.

---

## 📜 Disclaimer
This software is an academic final-year project prototype. The eKYC and eSign modules demonstrate digital workflow integration and do not perform actual government API validation or legally certified PKI digital signature verification.
